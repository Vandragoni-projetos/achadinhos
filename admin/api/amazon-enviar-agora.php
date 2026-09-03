<?php
declare(strict_types=1);

/**
 * Executa a automação Amazon (runAutomacaoAmazon forçada), sem depender do cron.
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

$result = null;
try {
    require_once __DIR__ . '/../../config/automacao-amazon.php';
    $result = runAutomacaoAmazon(true);
} catch (Throwable $e) {
    error_log('amazon-enviar-agora: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao executar a automação Amazon: ' . $e->getMessage(),
        'details' => [],
        'errors' => [$e->getMessage()],
    ], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

if (!is_array($result)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A automação Amazon não devolveu um resultado válido (esperado JSON interno).',
        'details' => [],
        'errors' => [],
    ], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

$ok = !empty($result['success']);
http_response_code($ok ? 200 : 400);
$flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
}
$json = json_encode($result, $flags);
if ($json === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Falha ao serializar a resposta: ' . json_last_error_msg(),
        'details' => ['produtos_processados' => $result['details']['produtos_processados'] ?? null, 'mensagens_enviadas' => $result['details']['mensagens_enviadas'] ?? null],
        'errors' => [json_last_error_msg()],
    ], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
echo $json;
