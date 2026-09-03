<?php

/**
 * Cron externo por loja (cron-job.org, etc.).
 * Identificação da loja: GET ?loja=ml (ou shopee, amazon, …) e/ou cabeçalho X-Cron-Loja.
 * Autenticação: cabeçalho X-Cron-Token ou GET ?token= (legado).
 */
set_time_limit(600);
if (php_sapi_name() !== 'cli') {
    ignore_user_abort(true);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/db/SchemaHelper.php';
garantirColunaAutomacoesCron();
garantirColunaGruposWhatsappIntervaloMinutos();
garantirTabelaCronExecucoes();
require_once __DIR__ . '/../config/bootstrap.php';

$wantDebug = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
$forcar = isset($_GET['forcar']) && $_GET['forcar'] === '1';
$cronLojaTrace = [
    'script' => 'rodar-loja',
    'loja' => null,
    'forcar' => $forcar,
    'hora_g' => (int) date('G'),
    'timezone' => @date_default_timezone_get() ?: 'unknown',
];

header('Content-Type: ' . ($wantDebug ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));

$loja = isset($_GET['loja']) ? trim((string) $_GET['loja']) : '';
$loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
if ($loja === '') {
    $hdrLoja = achadinhosCronLerHeaderHttp('X-Cron-Loja');
    if ($hdrLoja !== '') {
        $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($hdrLoja));
    }
}

$tokInfo = achadinhosCronLerTokenDaRequisicao();
$token = $tokInfo['value'];
if ($loja === '') {
    http_response_code(400);
    achadinhosCronHttpDiagnosticHeader('loja_missing');
    exit('Bad request');
}
$cronLojaTrace['loja'] = $loja;

$row = null;
try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT token, hora_inicio, hora_fim, dias_remocao, cron_individual_ativo FROM automacoes_cron WHERE loja = ? LIMIT 1'
    );
    $stmt->execute([$loja]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    http_response_code(500);
    achadinhosCronHttpDiagnosticHeader('db_error');
    exit('Erro ao ler configuração');
}

if (!$row) {
    http_response_code(404);
    achadinhosCronHttpDiagnosticHeader('loja_not_found');
    exit('Loja não configurada');
}

if (empty($row['cron_individual_ativo'])) {
    http_response_code(403);
    achadinhosCronHttpDiagnosticHeader('cron_individual_off');
    exit('Cron individual desativado para esta loja. Use a cron global (rodar-tudo.php).');
}

$configToken = trim((string) ($row['token'] ?? ''));
if ($configToken === '' || !hash_equals($configToken, $token)) {
    http_response_code(401);
    achadinhosCronHttpDiagnosticHeader('token_rejected');
    achadinhosCronAuthLog('cron_loja_token_fail', [
        'source' => $tokInfo['source'] ?? 'none',
        'expected_len' => strlen($configToken),
        'received_len' => strlen($token),
        'reject' => $configToken === '' ? 'loja_token_not_configured' : 'token_mismatch',
    ]);
    exit('Unauthorized');
}

$horaAtual = (int) date('G');
$hi = max(0, min(23, (int) ($row['hora_inicio'] ?? 0)));
$hf = max(0, min(23, (int) ($row['hora_fim'] ?? 23)));

if (!$forcar) {
    if ($hi <= $hf) {
        if ($horaAtual < $hi || $horaAtual > $hf) {
            $cronLojaTrace['bloqueio'] = 'fora_janela';
            $cronLojaTrace['janela'] = ['inicio' => $hi, 'fim' => $hf];
            if ($wantDebug) {
                http_response_code(200);
                echo achadinhosCronJsonEncode(['ok' => false, 'trace' => $cronLojaTrace], JSON_PRETTY_PRINT);
            } else {
                exit('Fora da janela');
            }
            exit;
        }
    } else {
        // Janela que cruza meia-noite: permitido se hora >= início OU hora <= fim
        if ($horaAtual > $hf && $horaAtual < $hi) {
            $cronLojaTrace['bloqueio'] = 'fora_janela_noturna';
            $cronLojaTrace['janela'] = ['inicio' => $hi, 'fim' => $hf];
            if ($wantDebug) {
                http_response_code(200);
                echo achadinhosCronJsonEncode(['ok' => false, 'trace' => $cronLojaTrace], JSON_PRETTY_PRINT);
            } else {
                exit('Fora da janela');
            }
            exit;
        }
    }
}

$lock = cronMonitorAdquirirLock('rodar_loja_' . $loja);
if (!$lock['ok']) {
    header('X-Achadinhos-Cron-Lock: busy');
    achadinhosCronHttpDiagnosticHeader('lock_busy');
    http_response_code(200);
    if ($wantDebug) {
        echo achadinhosCronJsonEncode(['ok' => true, 'skipped' => true, 'reason' => 'lock_busy', 'trace' => $cronLojaTrace], JSON_PRETTY_PRINT);
    } else {
        echo "OK skipped=lock_busy\n";
    }
    exit;
}

$tInicio = microtime(true);
$r = ['success' => false, 'message' => 'Loja não reconhecida.'];
$throwable = null;

try {
    switch ($loja) {
        case 'ml':
            require_once __DIR__ . '/../config/automacao-ml.php';
            $r = runAutomacaoML($forcar);
            break;

        case 'shopee':
            require_once __DIR__ . '/../config/automacao-shopee.php';
            $r = runAutomacaoShopee($forcar);
            break;

        case 'magalu':
            require_once __DIR__ . '/../config/automacao-magalu.php';
            $r = runAutomacaoMagalu($forcar);
            if (getConfig('magalu_loja_automacao_ativa', '0') === '1') {
                require_once __DIR__ . '/../config/automacao-magalu-loja.php';
                runAutomacaoMagaluLoja($forcar);
            }
            break;

        case 'aliexpress':
            require_once __DIR__ . '/../config/automacao-aliexpress.php';
            $r = runAutomacaoAliExpress($forcar);
            break;

        case 'amazon':
            require_once __DIR__ . '/../config/automacao-amazon.php';
            $r = runAutomacaoAmazon($forcar);
            break;

        case 'shein':
            require_once __DIR__ . '/../config/automacao-shein.php';
            $r = runAutomacaoShein($forcar);
            break;

        case 'ml_cupons':
            require_once __DIR__ . '/../config/automacao-cupons-ml.php';
            $r = runAutomacaoCuponsML($forcar);
            break;

        default:
            cronMonitorLiberarLock($lock['fh']);
            http_response_code(404);
            exit('Unknown store');
    }

    $dias = max(1, min(365, (int) ($row['dias_remocao'] ?? 30)));
    cronExecutarLimpezaProdutosAntigos($dias);
} catch (Throwable $e) {
    $throwable = $e;
} finally {
    $ms = (int) round((microtime(true) - $tInicio) * 1000);
    if ($throwable !== null) {
        registrarExecucaoCron([
            'tipo' => 'loja',
            'loja' => $loja,
            'status' => 'erro',
            'mensagem' => $throwable->getMessage(),
            'tempo_execucao' => $ms,
        ]);
    } else {
        $det = ['loja' => $loja, 'tempo_ms' => $ms];
        if ($wantDebug && isset($r) && is_array($r)) {
            $det['automacao'] = [
                'success' => $r['success'] ?? null,
                'message' => isset($r['message']) ? substr((string) $r['message'], 0, 500) : null,
            ];
        }
        registrarExecucaoCron([
            'tipo' => 'loja',
            'loja' => $loja,
            'status' => !empty($r['success']) ? 'sucesso' : 'erro',
            'mensagem' => (string) ($r['message'] ?? ''),
            'tempo_execucao' => $ms,
            'detalhes' => $det,
        ]);
    }
    cronMonitorLiberarLock($lock['fh']);
}

if ($throwable !== null) {
    http_response_code(500);
    echo 'ERRO: ' . $throwable->getMessage();
    exit;
}

if ($wantDebug) {
    $cronLojaTrace['resultado'] = $r ?? null;
    echo achadinhosCronJsonEncode(['ok' => !empty($r['success']), 'trace' => $cronLojaTrace], JSON_PRETTY_PRINT);
} else {
    echo 'OK';
}
