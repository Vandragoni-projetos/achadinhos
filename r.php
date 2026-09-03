<?php
/**
 * Redirecionador de links curtos (encurtador próprio).
 * Uso: /r.php?c=CODIGO
 */
$codigo = isset($_GET['c']) ? trim($_GET['c']) : '';
if ($codigo === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Link não encontrado.');
}

$root = __DIR__;
if (!is_file($root . '/config/database.php')) {
    http_response_code(500);
    exit('Configuração não encontrada.');
}

require_once $root . '/config/database.php';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT url_destino FROM short_urls WHERE code = ? LIMIT 1");
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit('Erro ao consultar link.');
}

if (!$row || empty($row['url_destino'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Link não encontrado.');
}

$destino = $row['url_destino'];
if (strpos($destino, 'http') !== 0) {
    $destino = 'https://' . $destino;
}

header('Location: ' . $destino, true, 302);
header('Cache-Control: no-store, no-cache');
exit;
