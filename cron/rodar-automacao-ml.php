<?php
/**
 * Ponto de entrada para o CRON da automação Mercado Livre.
 * URL: /cron/rodar-automacao-ml.php?token=SEU_TOKEN
 * ?forcar=1 = executa mesmo com automação desativada (usado pelo botão "Executar agora").
 * CLI: php rodar-automacao-ml.php forcar
 * Via HTTP: ?token= obrigatório (ml_cron_token nas configs). CLI: sem token.
 */
set_time_limit(300);
if (php_sapi_name() !== 'cli') {
    ignore_user_abort(true);
}
$wantJson = false;
if (php_sapi_name() !== 'cli') {
    $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (isset($_GET['format']) && $_GET['format'] === 'json');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

achadinhosCronHttpExigirToken('ml_cron_token', $wantJson);

require_once __DIR__ . '/../config/automacao-ml.php';
$forcar = (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'forcar')
    || (isset($_GET['forcar']) && $_GET['forcar'] === '1');
$result = runAutomacaoML($forcar);

setConfig('ml_ultimo_resultado', achadinhosCronJsonEncode($result));
setConfig('ml_ultimo_resultado_data', date('Y-m-d H:i:s'));

if (!empty($wantJson)) {
    header('Content-Type: application/json; charset=utf-8');
    echo achadinhosCronJsonEncode($result, JSON_PRETTY_PRINT);
} else {
    echo $result['success'] ? 'OK' : ('ERRO: ' . ($result['message'] ?? 'Falha na automação.'));
}
