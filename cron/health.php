<?php
/**
 * Healthcheck do agendador global (última execução, atraso, integrações).
 *
 * GET /cron/health.php?token=TOKEN
 *   — token = valor de `cron_health_token` na BD, ou (se vazio) `cron_token`.
 *
 * Uso: monitorização externa (UptimeRobot, cron-job.org heartbeat, etc.).
 * Não expõe segredos além do que já protege o cron; ainda assim use HTTPS.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$tok = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
$expected = trim((string) getConfig('cron_health_token', ''));
if ($expected === '') {
    $expected = trim((string) getConfig('cron_token', ''));
}

if ($expected === '') {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'configure_cron_token',
        'message' => 'Defina cron_token ou cron_health_token em configuracoes para usar o healthcheck.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($tok === '' || !hash_equals($expected, $tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = cronHealthPayload();
$payload['status'] = ($payload['ok'] ?? false) ? 'OK' : 'DEGRADED';

http_response_code(($payload['ok'] ?? false) ? 200 : 503);
echo achadinhosCronJsonEncode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
