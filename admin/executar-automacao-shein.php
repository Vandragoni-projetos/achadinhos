<?php
/**
 * Executa a automação Shein sob demanda (botão "Executar agora").
 * Exige login. Ignora o checkbox "Automação ativa".
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'details' => [], 'errors' => []]);
    exit;
}

require_once __DIR__ . '/../config/automacao-shein.php';

$result = runAutomacaoShein(true);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
