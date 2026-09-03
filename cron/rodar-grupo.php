<?php
/**
 * Executa a automação de envios para um único grupo WhatsApp (ID na tabela grupos_whatsapp).
 * Cada grupo pode ter um job dedicado na cron-job.org que aponta para este script.
 *
 * GET /cron/rodar-grupo.php?grupo=ID&token=CRON_TOKEN
 * Cabeçalho X-Cron-Token também é aceite (se o proxy repassar).
 */
set_time_limit(600);
if (php_sapi_name() !== 'cli') {
    ignore_user_abort(true);
}

$wantJson = (php_sapi_name() !== 'cli')
    && ((strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (isset($_GET['format']) && $_GET['format'] === 'json'));

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/db/SchemaHelper.php';
garantirColunaAutomacoesCron();
garantirColunaGruposWhatsappIntervaloMinutos();
garantirTabelaCronExecucoes();
garantirCronExecucoesTipoGrupoStatusPulado();
garantirColunaCronExecucoesGrupoWhatsappId();
require_once __DIR__ . '/../config/functions.php';

if (php_sapi_name() !== 'cli') {
    achadinhosCronHttpExigirTokenFlexivel($wantJson, 'rodar-grupo.php');
}

$grupoId = isset($_GET['grupo']) ? (int) $_GET['grupo'] : 0;
if ($grupoId <= 0) {
    if (php_sapi_name() !== 'cli') {
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo achadinhosCronJsonEncode(['success' => false, 'message' => 'Parâmetro grupo (ID numérico) obrigatório.']);
        } else {
            http_response_code(400);
            echo 'Bad request: grupo';
        }
        exit;
    }
    fwrite(STDERR, "Uso: php rodar-grupo.php --grupo=ID (com cron_token no ambiente ou apenas em HTTP)\n");
    exit(1);
}

$lock = cronMonitorAdquirirLock('rodar_grupo_' . $grupoId);
if (!$lock['ok']) {
    if (php_sapi_name() !== 'cli') {
        header('X-Achadinhos-Cron-Lock: busy');
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo achadinhosCronJsonEncode([
                'success' => true,
                'skipped' => true,
                'reason' => 'lock_busy',
                'message' => 'Execução deste grupo já em andamento; pedido ignorado.',
            ]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(200);
            echo "OK skipped=lock_busy\n";
        }
        exit;
    }
    fwrite(STDERR, "Lock em uso para grupo $grupoId.\n");
    exit(1);
}

$tInicio = microtime(true);
$throwable = null;
$r = ['success' => false, 'message' => 'Grupo não encontrado.'];
$cronHistoricoStatus = 'erro';
$cronHistoricoDetalhes = ['grupo_id' => $grupoId];
$httpResponseCode = 200;

try {
    $pdo = getDB();
    $st = $pdo->prepare('SELECT id, automacao_loja, ativo, grupo_id AS wa_grupo_jid FROM grupos_whatsapp WHERE id = ? LIMIT 1');
    $st->execute([$grupoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $r = ['success' => false, 'message' => 'Grupo não encontrado (ID interno inexistente). Use o ID da regra em Grupos, não o JID do WhatsApp.'];
        $cronHistoricoStatus = 'erro';
        $cronHistoricoDetalhes['motivo'] = 'not_found';
        $httpResponseCode = 404;
    } elseif (empty($row['ativo'])) {
        $r = [
            'success' => true,
            'skipped' => true,
            'message' => 'Grupo inativo: pedido HTTP aceite (200), sem envio de ofertas. O agendador na cron-job.org pode continuar a chamar esta URL — desative o job remoto ou marque o grupo como ativo.',
        ];
        $cronHistoricoStatus = 'pulado';
        $cronHistoricoDetalhes['motivo'] = 'grupo_inativo';
        $cronHistoricoDetalhes['wa_grupo_jid'] = substr((string) ($row['wa_grupo_jid'] ?? ''), 0, 120);
    } else {
        $loja = gruposNormalizarAutomacaoLoja((string) ($row['automacao_loja'] ?? ''));
        $cronHistoricoDetalhes['automacao_loja'] = (string) ($row['automacao_loja'] ?? '');
        $cronHistoricoDetalhes['wa_grupo_jid'] = substr((string) ($row['wa_grupo_jid'] ?? ''), 0, 120);
        if ($loja === null) {
            $raw = preg_replace('/[^\w_]/', '', strtolower(trim((string) ($row['automacao_loja'] ?? ''))));
            $r = [
                'success' => false,
                'message' => 'Automação inválida ou não suportada em execução por grupo («' . ($raw !== '' ? $raw : 'vazio') . '»). Corrija a regra em Grupos (lista de lojas).',
            ];
            $cronHistoricoStatus = 'erro';
            $cronHistoricoDetalhes['motivo'] = 'automacao_invalida';
            $httpResponseCode = 400;
        } else {
            // Execução dedicada a um grupo: deve enviar WA/Telegram agora (evita site OK + grupos 0 por intervalo/janela).
            $forcar = true;
            switch ($loja) {
                case 'ml':
                    require_once __DIR__ . '/../config/automacao-ml.php';
                    $r = runAutomacaoML($forcar, $grupoId);
                    break;
                case 'shopee':
                    require_once __DIR__ . '/../config/automacao-shopee.php';
                    $r = runAutomacaoShopee($forcar, $grupoId);
                    break;
                case 'magalu':
                    require_once __DIR__ . '/../config/automacao-magalu.php';
                    $r = runAutomacaoMagalu($forcar, $grupoId);
                    break;
                case 'amazon':
                    require_once __DIR__ . '/../config/automacao-amazon.php';
                    $r = runAutomacaoAmazon($forcar, $grupoId);
                    break;
                case 'shein':
                    require_once __DIR__ . '/../config/automacao-shein.php';
                    $r = runAutomacaoShein($forcar, $grupoId);
                    break;
                case 'aliexpress':
                    require_once __DIR__ . '/../config/automacao-aliexpress.php';
                    $r = runAutomacaoAliExpress($forcar, $grupoId);
                    break;
                case 'ml_cupons':
                    require_once __DIR__ . '/../config/automacao-cupons-ml.php';
                    $r = runAutomacaoCuponsML($forcar, $grupoId);
                    break;
                default:
                    $r = ['success' => false, 'message' => 'Loja de automação não mapeada no runner: ' . $loja];
            }
            $cronHistoricoStatus = !empty($r['success']) ? 'sucesso' : 'erro';
            $cronHistoricoDetalhes['success'] = $r['success'] ?? false;
        }
    }
} catch (Throwable $e) {
    $throwable = $e;
}

$ms = (int) round((microtime(true) - $tInicio) * 1000);
if ($throwable !== null) {
    registrarExecucaoCron([
        'tipo' => 'grupo',
        'grupo_whatsapp_id' => $grupoId,
        'status' => 'erro',
        'mensagem' => 'grupo ' . $grupoId . ': ' . $throwable->getMessage(),
        'tempo_execucao' => $ms,
        'detalhes' => ['grupo_id' => $grupoId, 'exception' => $throwable->getMessage()],
    ]);
} else {
    registrarExecucaoCron([
        'tipo' => 'grupo',
        'grupo_whatsapp_id' => $grupoId,
        'status' => $cronHistoricoStatus,
        'mensagem' => 'grupo ' . $grupoId . ': ' . (string) ($r['message'] ?? ''),
        'tempo_execucao' => $ms,
        'detalhes' => $cronHistoricoDetalhes,
    ]);
}

cronMonitorLiberarLock($lock['fh']);

if ($throwable !== null) {
    if (php_sapi_name() !== 'cli') {
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo achadinhosCronJsonEncode([
                'success' => false,
                'message' => $throwable->getMessage(),
                'grupo_id' => $grupoId,
                'http_status' => 500,
                'runner' => 'rodar-grupo.php',
            ]);
        } else {
            http_response_code(500);
            echo 'ERRO: ' . $throwable->getMessage();
        }
        exit;
    }
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    http_response_code($httpResponseCode);
    header('X-Achadinhos-Cron-Runner: rodar-grupo');
    header('X-Achadinhos-Cron-Http-Status: ' . (string) $httpResponseCode);
}

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo achadinhosCronJsonEncode(
        $r + [
            'grupo_id' => $grupoId,
            'tempo_ms' => $ms,
            'http_status' => $httpResponseCode,
            'runner' => 'rodar-grupo.php',
        ],
        JSON_PRETTY_PRINT
    );
} else {
    $ok = !empty($r['success']);
    echo ($ok ? 'OK' : 'FALHA') . ' grupo ' . $grupoId . ': ' . ($r['message'] ?? '') . "\n";
}
