<?php
/**
 * Cron job para rodar a automação de cupons do Mercado Livre
 *
 * Uso: crontab ou agendador HTTP com ?token= na URL.
 * Via HTTP: token obrigatório (config ml_cupons_cron_token). CLI: sem token.
 */
$wantJson = false;
if (php_sapi_name() !== 'cli') {
    $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (isset($_GET['format']) && $_GET['format'] === 'json');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

achadinhosCronHttpExigirToken('ml_cupons_cron_token', $wantJson);

require_once __DIR__ . '/../config/automacao-cupons-ml.php';

// Executar automação
$result = runAutomacaoCuponsML(false);

// Log do resultado (opcional)
if (function_exists('error_log')) {
    $logMsg = 'Automação Cupons ML: ' . ($result['success'] ? 'Sucesso' : 'Erro') . ' - ' . ($result['message'] ?? '');
    error_log($logMsg);
}

// Retornar JSON para debug (opcional, pode remover em produção)
header('Content-Type: application/json; charset=utf-8');
echo achadinhosCronJsonEncode($result, JSON_PRETTY_PRINT);
