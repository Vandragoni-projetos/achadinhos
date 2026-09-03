<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../core/cron/CronJobService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

startSession();
$expected = $_SESSION['admin_autosave_token'] ?? '';
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';

$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$syncApi = !empty($body['sync_api']);

if (array_key_exists('cron_intervalo_minutos', $body)) {
    $iv = CronPolicy::normalizeInterval((int) $body['cron_intervalo_minutos']);
    setConfig('cron_intervalo_minutos', (string) $iv);
}
if (array_key_exists('cron_hora_inicio', $body)) {
    setConfig('cron_hora_inicio', (string) max(0, min(23, (int) $body['cron_hora_inicio'])));
}
if (array_key_exists('cron_hora_fim', $body)) {
    setConfig('cron_hora_fim', (string) max(0, min(23, (int) $body['cron_hora_fim'])));
}
if (array_key_exists('produtos_dias_expiracao', $body)) {
    $diasExp = max(1, min(365, (int) $body['produtos_dias_expiracao']));
    setConfig('produtos_dias_expiracao', (string) $diasExp);
}
$apiKey = trim((string) ($body['cron_job_org_api_key'] ?? getConfig('cron_job_org_api_key', '')));
$baseUrl = rtrim(trim((string) ($body['cron_public_base_url'] ?? getConfig('cron_public_base_url', ''))), '/');

if (array_key_exists('cron_job_org_api_key', $body)) {
    setConfig('cron_job_org_api_key', $apiKey);
}
if (array_key_exists('cron_public_base_url', $body)) {
    setConfig('cron_public_base_url', $baseUrl);
}

$resp = ['ok' => true, 'saved' => true];

if ($syncApi) {
    if ($apiKey === '') {
        setConfig('cron_global_job_id', '');
        setConfig('cron_global_sync_last_error', '');
        setConfig('cron_global_org_probe_cache', '');
        setConfig('cron_global_org_last_iv', '');
        setConfig('cron_global_org_last_h1', '');
        setConfig('cron_global_org_last_h2', '');
        setConfig('cron_global_org_last_job_fp', '');
        setConfig('cron_global_last_synced_job_host', '');
        $resp['sync'] = ['success' => true, 'message' => 'Chave API removida: integração cron-job.org desligada neste site.'];
    } else {
        setConfig('cron_global_sync_last_error', '');
        setConfig('cron_global_org_probe_cache', '');
        $jidLegacy = trim((string) getConfig('cron_global_job_id', ''));
        $resp['job_id'] = $jidLegacy;
        $resp['synced_job_host'] = trim((string) getConfig('cron_global_last_synced_job_host', ''));
        $resp['sync'] = [
            'success' => true,
            'message' => 'Chave API guardada. Os jobs na cron-job.org são criados ao salvar cada grupo (cron/rodar-grupo.php); não é necessário job global nem ID global.',
            'job_id' => $jidLegacy,
            'synced_job_host' => $resp['synced_job_host'],
        ];
    }
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE);
