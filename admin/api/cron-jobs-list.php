<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../core/cron/CronJobService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado', 'jobs' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$forcarSemCache = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
$cronApiKey = trim((string) getConfig('cron_job_org_api_key', ''));

if ($cronApiKey === '') {
    echo json_encode(['success' => false, 'message' => 'Chave da API não configurada', 'jobs' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = cronJobListarJobsConta($forcarSemCache);
echo json_encode([
    'success' => !empty($res['success']),
    'message' => (string) ($res['message'] ?? ''),
    'jobs' => is_array($res['jobs'] ?? null) ? $res['jobs'] : [],
], JSON_UNESCAPED_UNICODE);
