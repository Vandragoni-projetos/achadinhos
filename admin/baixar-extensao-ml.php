<?php
/**
 * Download do pacote da extensão Mercado Livre (afiliados) — arquivo: extensao-ml-afiliados.zip
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$path = __DIR__ . '/../downloads/extensao-ml-afiliados.zip';
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Arquivo indisponível</title></head><body>';
    echo '<p>O arquivo <code>downloads/extensao-ml-afiliados.zip</code> não foi encontrado no servidor (coloque o ZIP na pasta <code>downloads/</code> do projeto).</p>';
    $dlUrl = adminBaixarExtensaoMlUrl();
    echo '<p>Endereço deste download: <code>' . htmlspecialchars($dlUrl, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p><a href="mercadolivre.php">Voltar ao Mercado Livre</a></p>';
    echo '</body></html>';
    exit;
}

$size = filesize($path);
if ($size === false) {
    http_response_code(500);
    exit;
}

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="extensao-ml-afiliados.zip"');
header('Content-Length: ' . (string) $size);
header('Cache-Control: private, no-cache, must-revalidate');

readfile($path);
exit;
