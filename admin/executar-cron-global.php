<?php
/**
 * Executa cron global (rodar-tudo.php) sob demanda — botão na aba Configurações → Crons.
 * Equivale a chamar /cron/rodar-tudo.php?forcar=1. Exige login admin.
 */
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

@ini_set('display_errors', '0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'resultados' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

set_time_limit(600);
@ini_set('max_execution_time', '600');

$_GET['forcar'] = '1';
$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
if (stripos($accept, 'application/json') === false) {
    $_SERVER['HTTP_ACCEPT'] = 'application/json' . ($accept !== '' ? ', ' . $accept : '');
}

ob_end_clean();

if (!defined('ACHADINHOS_CRON_RODAR_TUDO_INTERNAL')) {
    define('ACHADINHOS_CRON_RODAR_TUDO_INTERNAL', true);
}
require __DIR__ . '/../cron/rodar-tudo.php';
