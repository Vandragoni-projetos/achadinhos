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
$token = trim($hdr !== '' ? $hdr : (string) ($_GET['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$loja = isset($_GET['loja']) ? preg_replace('/[^a-z0-9_]/', '', (string) $_GET['loja']) : '';
$map = [
    'ml' => ['cfg' => 'ml_evolution_conta_id', 'prefix' => 'ml'],
    'shopee' => ['cfg' => 'shopee_evolution_conta_id', 'prefix' => 'shopee'],
    'magalu' => ['cfg' => 'magalu_evolution_conta_id', 'prefix' => 'magalu'],
    'amazon' => ['cfg' => 'amazon_evolution_conta_id', 'prefix' => 'amazon'],
    'shein' => ['cfg' => 'shein_evolution_conta_id', 'prefix' => 'shein'],
    'aliexpress' => ['cfg' => 'aliexpress_evolution_conta_id', 'prefix' => 'aliexpress'],
    'ml_cupons' => ['cfg' => 'ml_cupons_evolution_conta_id', 'prefix' => 'ml_cupons'],
];
if (!isset($map[$loja])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Loja inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lojaEvolutionLojaKey = $loja;
$lojaEvolutionContaId = (int) getConfig($map[$loja]['cfg'], '0');
$lojaEvolutionPainelPrefix = $map[$loja]['prefix'];

ob_start();
require __DIR__ . '/../includes/loja-evolution-status.php';
$html = ob_get_clean();

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
