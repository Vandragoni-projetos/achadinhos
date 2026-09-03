<?php
/**
 * Remove produtos com mais de X dias (padrão 30).
 * Opcional: apagar imagens em uploads antes do DELETE.
 *
 * Uso:
 *   CLI:  php cron/limpar-produtos-antigos.php
 *   Web:  https://ofertas.digitalavance.com.br/cron/limpar-produtos-antigos.php
 *         (ou ?token=SEU_TOKEN se produtos_cron_token estiver em configurações)
 *
 * Config (configuracoes): produtos_dias_expiracao (ex.: 30); produtos_cron_token (opcional).
 *
 * Agendar no painel da hospedagem (1x/dia, ex. 3h):
 *   URL: https://ofertas.digitalavance.com.br/cron/limpar-produtos-antigos.php
 */
$wantJson = false;
if (php_sapi_name() !== 'cli') {
    $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (isset($_GET['format']) && $_GET['format'] === 'json');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$tokenConfig = getConfig('produtos_cron_token', '');
if ($tokenConfig !== '' && php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($token !== $tokenConfig) {
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo achadinhosCronJsonEncode(['success' => false, 'message' => 'Token inválido.', 'deletados' => 0]);
        } else {
            http_response_code(403);
            echo 'ERRO: Token inválido.';
        }
        exit;
    }
}

$dias = max(1, (int) getConfig('produtos_dias_expiracao', '30'));

try {
    $pdo = getDB();
    $sql = "SELECT id, imagem FROM produtos WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dias]);
    $antigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deletados = 0;

    foreach ($antigos as $p) {
        if (!empty($p['imagem']) && function_exists('deleteImagem')) {
            deleteImagem($p['imagem']);
        }
        $del = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $del->execute([(int)$p['id']]);
        if ($del->rowCount()) $deletados++;
    }

    if ($wantJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo achadinhosCronJsonEncode([
            'success' => true,
            'message' => $deletados . ' produto(s) removido(s) (mais antigos que ' . $dias . ' dias).',
            'deletados' => $deletados,
            'dias' => $dias,
        ], JSON_PRETTY_PRINT);
    } else {
        echo $deletados . ' produto(s) removido(s) (mais antigos que ' . $dias . ' dias).';
    }
} catch (Exception $e) {
    if ($wantJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo achadinhosCronJsonEncode(['success' => false, 'message' => $e->getMessage(), 'deletados' => 0]);
    } else {
        http_response_code(500);
        echo 'ERRO: ' . $e->getMessage();
    }
}
