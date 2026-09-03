<?php
/**
 * Ponto de entrada para o CRON da automação Amazon.
 * URL: /cron/rodar-automacao-amazon.php?token=SEU_TOKEN
 * Via HTTP: ?token= obrigatório. CLI: sem token.
 */
$wantJson = false;
if (php_sapi_name() !== 'cli') {
    $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (isset($_GET['format']) && $_GET['format'] === 'json');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

achadinhosCronHttpExigirToken('amazon_cron_token', $wantJson);

require_once __DIR__ . '/../config/automacao-amazon.php';
$result = runAutomacaoAmazon(false);

if (!empty($wantJson)) {
    header('Content-Type: application/json; charset=utf-8');
    echo achadinhosCronJsonEncode($result, JSON_PRETTY_PRINT);
} else {
    echo $result['success'] ? 'OK' : ('ERRO: ' . ($result['message'] ?? 'Falha na automação.'));
}
