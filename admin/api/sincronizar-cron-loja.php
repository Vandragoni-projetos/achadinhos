<?php
declare(strict_types=1);

/**
 * Sincroniza apenas o job na cron-job.org (global ou por loja), sem executar automação.
 * Exige os mesmos critérios de auth que executar-loja.php.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../core/cron/CronJobService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

startSession();
$expected = $_SESSION['admin_autosave_token'] ?? '';
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';

$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token inválido.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$loja = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['loja'] ?? '')));
$lojasPermitidas = ['ml', 'shopee', 'magalu', 'amazon', 'shein', 'aliexpress', 'ml_cupons'];
if (!in_array($loja, $lojasPermitidas, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Loja inválida.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$phases = [];
$apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
$cfgSync = dadosCronLoja($loja);
$indivAtivo = (int) ($cfgSync['cron_individual_ativo'] ?? 0) === 1;
$tokLoja = trim((string) ($cfgSync['token'] ?? ''));
$cronsSetupRel = 'configuracoes.php?tab=crons';

if ($apiKey === '') {
    $phases['sync_org'] = [
        'ok' => false,
        'skipped' => true,
        'message' => 'A integração com a cron-job.org não está ativa: não há chave API guardada. Ative a API em Configurações → Crons.',
        'crons_setup_url' => $cronsSetupRel,
    ];
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Chave API cron-job.org não configurada.',
        'phases' => $phases,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$indivAtivo) {
    $phases['sync_org'] = [
        'ok' => true,
        'skipped' => true,
        'message' => 'Agendamento na cron-job.org é por grupo (Grupos). Não há sincronização de job global rodar-tudo.',
        'escopo' => 'grupos',
    ];
} elseif ($tokLoja === '') {
    $phases['sync_org'] = [
        'ok' => false,
        'skipped' => true,
        'message' => 'Horário exclusivo ativo: defina o token do cron desta loja para criar o job na cron-job.org (rodar-loja.php).',
        'crons_setup_url' => $cronsSetupRel,
        'escopo' => 'loja',
    ];
} else {
    $sync = cronJobSincronizarLoja($loja, $cfgSync);
    if (!empty($sync['success'])) {
        $jid = (string) ($sync['job_id'] ?? '');
        if ($jid !== '') {
            salvarCronExternoLoja($loja, array_merge($cfgSync, ['cron_job_id' => $jid]));
        }
        $phases['sync_org'] = [
            'ok' => true,
            'skipped' => !empty($sync['skipped']),
            'message' => (string) ($sync['message'] ?? 'Sincronizado.'),
            'job_id' => $jid,
            'escopo' => 'loja',
        ];
    } else {
        $phases['sync_org'] = [
            'ok' => false,
            'skipped' => false,
            'message' => (string) ($sync['message'] ?? 'Falha ao sincronizar job da loja na cron-job.org.'),
            'crons_setup_url' => $cronsSetupRel,
            'escopo' => 'loja',
        ];
    }
}

$syncPh = $phases['sync_org'] ?? [];
$ok = !empty($syncPh['ok']);
$msg = (string) ($syncPh['message'] ?? ($ok ? 'Sincronização concluída.' : 'Falha na sincronização.'));

if (!$ok) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'phases' => $phases,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $msg,
    'phases' => $phases,
], JSON_UNESCAPED_UNICODE);
