<?php
/**
 * Cron global: automações + limpeza. Lojas com "horário exclusivo" (cron individual) ativo são ignoradas aqui
 * salvo com forcar=1 (executam também no global). Sem forcar, usam rodar-loja.php + cron-job.org.
 *
 * HTTP (cron-job.org / crontab):
 *   GET /cron/rodar-tudo.php (URL curta) + cabeçalho X-Cron-Token (preferido na integração API)
 *   ou GET /cron/rodar-tudo.php?token=… — o token pode ser o cron_token global ou qualquer token de loja em automacoes_cron.
 *
 * Teste manual no browser: use o mesmo segredo que nas configurações (global ou por loja).
 * Se 403 com X-Achadinhos-Cron-Error: token_missing → cabeçalho não chegou ao PHP (use ?token= ou ajuste o proxy).
 * Falhas registam-se em debug-cron-auth.log (sem expor o segredo).
 *   Parâmetros opcionais: forcar=1, debug=1 (com token válido), format=json
 *
 * CLI (fallback quando o agendador externo falha):
 *   php cron/rodar-tudo.php
 *   php cron/rodar-tudo.php --debug
 *   php cron/rodar-tudo.php --forcar
 *
 * Não existe worker persistente: cada execução é um processo PHP único.
 */
set_time_limit(600);
if (php_sapi_name() !== 'cli') {
    ignore_user_abort(true);
}

$cronCliDebug = php_sapi_name() === 'cli' && in_array('--debug', $argv ?? [], true);
$cronCliForcar = php_sapi_name() === 'cli' && in_array('--forcar', $argv ?? [], true);

$wantJson = (php_sapi_name() !== 'cli')
    && ((strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (isset($_GET['format']) && $_GET['format'] === 'json'));

/** Modo debug: trace de decisões (requer token HTTP válido se cron_token estiver definido; CLI: --debug). */
$cronDebug = $cronCliDebug
    || (php_sapi_name() !== 'cli'
        && isset($_GET['debug'])
        && (string) $_GET['debug'] === '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/db/SchemaHelper.php';
garantirColunaAutomacoesCron();
garantirColunaGruposWhatsappIntervaloMinutos();
garantirTabelaCronExecucoes();
garantirColunaCronExecucoesDetalhesJson();
require_once __DIR__ . '/../config/functions.php';

if (php_sapi_name() !== 'cli' && !(defined('ACHADINHOS_CRON_RODAR_TUDO_INTERNAL') && ACHADINHOS_CRON_RODAR_TUDO_INTERNAL)) {
    achadinhosCronHttpExigirTokenFlexivel($wantJson, 'rodar-tudo.php');
}

// #region agent log (81af1f audit — sem token/PII)
if (php_sapi_name() !== 'cli') {
    $auditFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'debug-81af1f.log';
    $h = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    @file_put_contents(
        $auditFile,
        json_encode([
            'sessionId' => '81af1f',
            'hypothesisId' => 'H_EXEC',
            'location' => 'cron/rodar-tudo.php',
            'message' => 'http_entry_after_token',
            'data' => [
                'http_host' => $h,
                'https' => $https,
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}
// #endregion

$lock = cronMonitorAdquirirLock('rodar_tudo');
if (!$lock['ok']) {
    if (php_sapi_name() !== 'cli') {
        // HTTP 200: agendadores externos (ex. cron-job.org) tratam 4xx/5xx como falha; lock = salto, não erro do job.
        header('X-Achadinhos-Cron-Lock: busy');
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo achadinhosCronJsonEncode([
                'success' => true,
                'skipped' => true,
                'reason' => 'lock_busy',
                'message' => 'Outra execução do cron global já está em andamento; este pedido foi ignorado.',
                'resultados' => [],
            ]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(200);
            echo "OK skipped=lock_busy\n";
        }
        exit;
    }
    fwrite(STDERR, "Cron global: lock em uso.\n");
    exit(1);
}

$horaAtual = (int) date('G');
$tInicio = microtime(true);

/** @var array<string, mixed> Trace para debug e coluna detalhes_json */
$cronTrace = [
    'versao' => 1,
    'script' => 'rodar-tudo',
    'php_sapi' => php_sapi_name(),
    'hora_servidor_g' => $horaAtual,
    'timezone' => @date_default_timezone_get() ?: 'unknown',
    'dispatch_ativo_producao' => getConfig('dispatch_ativo_producao', '0') === '1',
    'forcar' => ($cronCliForcar || (isset($_GET['forcar']) && $_GET['forcar'] === '1')),
    'debug' => $cronDebug,
    'lojas' => [],
];

function cronRodarTudoDeveExecutarLoja($horaInicio, $horaFim) {
    $hi = (int) $horaInicio;
    $hf = (int) $horaFim;
    global $horaAtual;
    if ($hi <= $hf) {
        return $horaAtual >= $hi && $horaAtual <= $hf;
    }

    return $horaAtual >= $hi || $horaAtual <= $hf;
}

/**
 * @param array|null $r Retorno runAutomacao* — opcional
 */
function cronRodarTudoTraceLoja(array &$trace, string $chave, string $decisao, ?string $motivo, $r = null): void {
    $entry = [
        'decisao' => $decisao,
        'motivo' => $motivo,
    ];
    if ($r !== null && is_array($r)) {
        $entry['success'] = $r['success'] ?? null;
        $entry['message'] = isset($r['message']) ? substr((string) $r['message'], 0, 800) : null;
        if (!empty($r['details']) && is_array($r['details'])) {
            $entry['details_resumo'] = array_slice($r['details'], 0, 15, true);
        }
        if (!empty($r['errors']) && is_array($r['errors'])) {
            $entry['errors_count'] = count($r['errors']);
        }
    }
    $trace['lojas'][$chave] = $entry;
}

$resultados = [];
$throwable = null;

try {
    $forcar = $cronTrace['forcar'];

    // Mercado Livre
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('ml_hora_inicio', '8'), getConfig('ml_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('ml'))) {
        require_once __DIR__ . '/../config/automacao-ml.php';
        $r = runAutomacaoML($forcar);
        $resultados['ml'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'ml', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('ml')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'ml', 'nao_executado', $motivo, null);
    }

    // Shopee
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('shopee_hora_inicio', '8'), getConfig('shopee_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('shopee'))) {
        require_once __DIR__ . '/../config/automacao-shopee.php';
        $r = runAutomacaoShopee($forcar);
        $resultados['shopee'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'shopee', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('shopee')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'shopee', 'nao_executado', $motivo, null);
    }

    // Magalu
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('magalu_hora_inicio', '8'), getConfig('magalu_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('magalu'))) {
        require_once __DIR__ . '/../config/automacao-magalu.php';
        $r = runAutomacaoMagalu($forcar);
        $resultados['magalu'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'magalu', 'executado', null, $r);
        if (getConfig('magalu_loja_automacao_ativa', '0') === '1') {
            require_once __DIR__ . '/../config/automacao-magalu-loja.php';
            $rLoja = runAutomacaoMagaluLoja($forcar);
            $resultados['magalu_loja'] = ['success' => $rLoja['success'], 'message' => $rLoja['message'] ?? ''];
            cronRodarTudoTraceLoja($cronTrace, 'magalu_loja', 'executado', null, $rLoja);
        } else {
            cronRodarTudoTraceLoja($cronTrace, 'magalu_loja', 'nao_executado', 'magalu_loja_automacao_ativa_desligada', null);
        }
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('magalu')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'magalu', 'nao_executado', $motivo, null);
        cronRodarTudoTraceLoja($cronTrace, 'magalu_loja', 'nao_executado', $motivo, null);
    }

    // Amazon
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('amazon_hora_inicio', '8'), getConfig('amazon_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('amazon'))) {
        require_once __DIR__ . '/../config/automacao-amazon.php';
        $r = runAutomacaoAmazon($forcar);
        $resultados['amazon'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'amazon', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('amazon')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'amazon', 'nao_executado', $motivo, null);
    }

    // Shein
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('shein_hora_inicio', '8'), getConfig('shein_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('shein'))) {
        require_once __DIR__ . '/../config/automacao-shein.php';
        $r = runAutomacaoShein($forcar);
        $resultados['shein'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'shein', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('shein')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'shein', 'nao_executado', $motivo, null);
    }

    // AliExpress
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('aliexpress_hora_inicio', '8'), getConfig('aliexpress_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('aliexpress'))) {
        require_once __DIR__ . '/../config/automacao-aliexpress.php';
        $r = runAutomacaoAliExpress($forcar);
        $resultados['aliexpress'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'aliexpress', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('aliexpress')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'aliexpress', 'nao_executado', $motivo, null);
    }

    // ML Cupons
    if (($forcar || cronRodarTudoDeveExecutarLoja(getConfig('ml_cupons_hora_inicio', '8'), getConfig('ml_cupons_hora_fim', '22'))) && ($forcar || !cronLojaUsaAgendamentoIndividual('ml_cupons'))) {
        require_once __DIR__ . '/../config/automacao-cupons-ml.php';
        $r = runAutomacaoCuponsML($forcar);
        $resultados['ml_cupons'] = ['success' => $r['success'], 'message' => $r['message'] ?? ''];
        cronRodarTudoTraceLoja($cronTrace, 'ml_cupons', 'executado', null, $r);
    } else {
        $motivo = (!$forcar && cronLojaUsaAgendamentoIndividual('ml_cupons')) ? 'cron_individual_ativo' : 'fora_janela_ou_forcar';
        cronRodarTudoTraceLoja($cronTrace, 'ml_cupons', 'nao_executado', $motivo, null);
    }

    $diasExpiracao = max(1, (int) getConfig('produtos_dias_expiracao', '30'));
    $lim = cronExecutarLimpezaProdutosAntigos($diasExpiracao);
    $resultados['limpeza'] = ['success' => $lim['success'], 'message' => $lim['message'], 'deletados' => $lim['deletados']];
    $cronTrace['limpeza'] = $resultados['limpeza'];

    $cronTrace['nota_intervalo_grupos'] = 'Intervalo por grupo (grupoPodeReceberEnvio) aplica-se às execuções normais; com forcar=1 a automação ignora esse bloqueio para permitir envio imediato.';
} catch (Throwable $e) {
    $throwable = $e;
} finally {
    $ms = (int) round((microtime(true) - $tInicio) * 1000);
    $cronTrace['tempo_execucao_ms'] = $ms;
    $cronTrace['resultados_keys'] = array_keys($resultados);

    if ($throwable !== null) {
        $cronTrace['exception'] = $throwable->getMessage();
        registrarExecucaoCron([
            'tipo' => 'global',
            'status' => 'erro',
            'mensagem' => $throwable->getMessage(),
            'tempo_execucao' => $ms,
            'detalhes' => $cronDebug ? $cronTrace : ['erro' => true, 'trace_compacto' => ['lojas' => array_keys($cronTrace['lojas'] ?? [])]],
        ]);
    } else {
        $allOk = true;
        foreach ($resultados as $v) {
            if (empty($v['success'])) {
                $allOk = false;
                break;
            }
        }
        $linhasRes = [];
        foreach ($resultados as $k => $v) {
            $ico = !empty($v['success']) ? 'OK' : 'FALHA';
            $linhasRes[] = $k . ':' . $ico . ' ' . (string) ($v['message'] ?? '');
        }
        registrarExecucaoCron([
            'tipo' => 'global',
            'status' => $allOk ? 'sucesso' : 'erro',
            'mensagem' => implode(' | ', $linhasRes),
            'tempo_execucao' => $ms,
            'detalhes' => $cronTrace,
        ]);
    }
    cronMonitorLiberarLock($lock['fh']);
}

if ($throwable !== null) {
    if (php_sapi_name() !== 'cli') {
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo achadinhosCronJsonEncode([
                'success' => false,
                'message' => $throwable->getMessage(),
                'resultados' => $resultados,
                'debug' => $cronDebug ? $cronTrace : null,
            ]);
        } else {
            http_response_code(500);
            echo 'ERRO: ' . $throwable->getMessage();
        }
        exit;
    }
    fwrite(STDERR, $throwable->getMessage() . "\n");
    if ($cronDebug) {
        fwrite(STDERR, achadinhosCronJsonEncode($cronTrace, JSON_PRETTY_PRINT) . "\n");
    }
    exit(1);
}

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['success' => true, 'resultados' => $resultados, 'tempo_ms' => (int) round((microtime(true) - $tInicio) * 1000)];
    if ($cronDebug) {
        $out['trace'] = $cronTrace;
    }
    echo achadinhosCronJsonEncode($out, JSON_PRETTY_PRINT);
} else {
    $linhas = [];
    foreach ($resultados as $k => $v) {
        $ico = $v['success'] ? '✓' : '✗';
        $linhas[] = $k . ': ' . $ico . ' ' . ($v['message'] ?? '');
    }
    echo implode("\n", $linhas);
    if ($cronDebug) {
        echo "\n\n--- DEBUG ---\n";
        echo achadinhosCronJsonEncode($cronTrace, JSON_PRETTY_PRINT);
    }
    if (php_sapi_name() === 'cli' && $cronDebug) {
        fwrite(STDERR, "\n--- DEBUG (trace completo no JSON acima) ---\n");
    }
}
