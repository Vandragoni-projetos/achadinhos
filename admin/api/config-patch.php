<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

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

$key = isset($body['key']) ? (string) $body['key'] : '';
$value = isset($body['value']) ? (string) $body['value'] : '';

if ($key === 'admin_display_version') {
    $v = preg_replace('/^V\s*/i', '', trim($value));
    if ($v === '' || strlen($v) > 24 || !preg_match('/^[A-Za-z0-9._\-]+$/', $v)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Versão inválida (1–24 caracteres: letras, números, ponto, _ ou -).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    setConfig('admin_display_version', $v);
    echo json_encode(['ok' => true, 'value' => $v], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Chave não suportada'], JSON_UNESCAPED_UNICODE);
