<?php
declare(strict_types=1);

/**
 * Executa a automação Shopee (runAutomacaoShopee forçada), sem depender do cron.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$expected = $_SESSION['admin_autosave_token'] ?? '';
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';
$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token inválido. Recarregue a página.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

achadinhosPainelApiPrepararJobLongo();

require_once __DIR__ . '/../../config/automacao-shopee.php';
$result = runAutomacaoShopee(true);
if (!is_array($result)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Resposta inválida da automação.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = !empty($result['success']);
http_response_code($ok ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
