<?php
/**
 * Executa a automação Magalu sob demanda (botão "Executar agora").
 * Exige login. Ignora o checkbox "Automação ativa".
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'details' => [], 'errors' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../config/automacao-magalu-loja.php';
    $result = runAutomacaoMagaluLoja(true);
    if (!is_array($result)) {
        $result = ['success' => false, 'message' => 'Resposta inválida da automação.', 'details' => [], 'errors' => []];
    }
} catch (Throwable $e) {
    $result = [
        'success' => false,
        'message' => 'Erro ao executar automação: ' . $e->getMessage(),
        'details' => [],
        'errors' => [ $e->getFile() . ':' . $e->getLine() ],
    ];
}

ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
