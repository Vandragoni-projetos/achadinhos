<?php
declare(strict_types=1);

/**
 * Dispara um envio de teste da automação da loja associada ao grupo (um produto / fluxo normal).
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
$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';
$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token inválido. Recarregue a página.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$grupoId = (int) ($body['grupo_id'] ?? 0);
if ($grupoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Informe um grupo_id válido.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, nome, ativo, COALESCE(automacao_loja, \'ml\') AS automacao_loja FROM grupos_whatsapp WHERE id = ? LIMIT 1'
);
$stmt->execute([$grupoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Grupo não encontrado.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!(int) $row['ativo']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Grupo inativo; ative-o antes do teste.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

achadinhosPainelApiPrepararJobLongo();

$loja = gruposNormalizarAutomacaoLoja((string) $row['automacao_loja']);
if ($loja === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Automação do grupo inválida ou não suportada. Corrija a regra em Grupos.',
        'details' => [],
        'errors' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = null;

switch ($loja) {
    case 'ml':
        require_once __DIR__ . '/../../config/automacao-ml.php';
        $result = runAutomacaoML(true, $grupoId);
        break;
    case 'shopee':
        require_once __DIR__ . '/../../config/automacao-shopee.php';
        $result = runAutomacaoShopee(true, $grupoId);
        break;
    case 'magalu':
        require_once __DIR__ . '/../../config/automacao-magalu.php';
        $result = runAutomacaoMagalu(true, $grupoId);
        break;
    case 'amazon':
        require_once __DIR__ . '/../../config/automacao-amazon.php';
        $result = runAutomacaoAmazon(true, $grupoId);
        break;
    case 'aliexpress':
        require_once __DIR__ . '/../../config/automacao-aliexpress.php';
        $result = runAutomacaoAliExpress(true, $grupoId);
        break;
    case 'shein':
        require_once __DIR__ . '/../../config/automacao-shein.php';
        $result = runAutomacaoShein(true, $grupoId);
        break;
    case 'ml_cupons':
        require_once __DIR__ . '/../../config/automacao-cupons-ml.php';
        $result = runAutomacaoCuponsML(true, $grupoId);
        break;
    default:
        $result = [
            'success' => false,
            'message' => 'Loja do grupo não suportada para teste de envio.',
            'details' => [],
            'errors' => [],
        ];
}

if (!is_array($result)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Resposta inválida da automação.', 'details' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = !empty($result['success']);
http_response_code($ok ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
