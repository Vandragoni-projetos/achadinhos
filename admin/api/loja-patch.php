<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/loja_autosave.php';

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

$token = $hdr !== '' ? trim($hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$loja = isset($body['loja']) ? (string) $body['loja'] : '';
$patch = isset($body['patch']) && is_array($body['patch']) ? $body['patch'] : null;
if ($patch === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Patch ausente'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = lojaAutosaveAplicarPatch($loja, $patch);
if (!$res['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Erro'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = ['ok' => true];
if (!empty($res['cron_extra'])) {
    $out['cron_extra'] = $res['cron_extra'];
}
if (!empty($res['cron_modo_global_forcado'])) {
    $out['cron_modo_global_forcado'] = true;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
