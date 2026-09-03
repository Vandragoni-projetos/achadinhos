<?php
/**
 * Retorna o último resultado da automação Mercado Livre (salvo pelo cron).
 * Exige login.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'data_execucao' => '', 'result' => null]);
    exit;
}

$json = getConfig('ml_ultimo_resultado', '');
$dataExecucao = getConfig('ml_ultimo_resultado_data', '');
$result = $json !== '' ? json_decode($json, true) : null;
if (!is_array($result)) {
    $result = null;
}

echo json_encode([
    'success' => true,
    'data_execucao' => $dataExecucao,
    'result' => $result
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
