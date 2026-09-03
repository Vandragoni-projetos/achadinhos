<?php
/**
 * Executa a automação Mercado Livre sob demanda (botão "Executar agora").
 * Roda na hora e retorna JSON. Exige login.
 */
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'details' => [], 'errors' => []]);
    exit;
}

set_time_limit(300);
@ini_set('max_execution_time', '300');

try {
    require_once __DIR__ . '/../config/automacao-ml.php';
    $result = runAutomacaoML(true);
} catch (Throwable $e) {
    $result = [
        'success' => false,
        'message' => 'Erro ao executar a automação.',
        'details' => [],
        'errors' => ['Exceção: ' . $e->getMessage()]
    ];
}

ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
