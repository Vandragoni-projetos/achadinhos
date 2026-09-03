<?php
/**
 * Diagnóstico de banco (apenas admin autenticado). Remova do deploy público se não for necessário.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (achadinhosIsProduction()) {
    http_response_code(404);
    exit;
}

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

echo "<h1>Teste de Banco de Dados</h1>";

try {
    $pdo = getDB();
    echo "<p style='color: green;'>✓ Conexão com banco de dados OK</p>";

    $stmt = $pdo->query("SHOW TABLES LIKE 'configuracoes'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Tabela 'configuracoes' existe</p>";
    } else {
        echo "<p style='color: red;'>✗ Tabela 'configuracoes' NÃO existe! Execute o database.sql</p>";
        exit;
    }

    $testKey = 'teste_' . time();
    $testValue = 'valor_teste';

    echo "<h2>Testando setConfig:</h2>";
    $result = setConfig($testKey, $testValue);

    if ($result) {
        echo "<p style='color: green;'>✓ setConfig retornou TRUE</p>";
    } else {
        echo "<p style='color: red;'>✗ setConfig retornou FALSE</p>";
    }

    $valorRecuperado = getConfig($testKey);
    if ($valorRecuperado === $testValue) {
        echo "<p style='color: green;'>✓ Valor foi salvo e recuperado corretamente: '" . htmlspecialchars($valorRecuperado) . "'</p>";
    } else {
        echo "<p style='color: red;'>✗ Valor não foi salvo corretamente.</p>";
    }

    $pdo->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([$testKey]);

    echo '<h2>Configurações atuais no banco:</h2>';
    $stmt = $pdo->query('SELECT chave, valor FROM configuracoes');
    $configs = $stmt->fetchAll();

    if (empty($configs)) {
        echo "<p style='color: orange;'>⚠ Nenhuma configuração encontrada no banco</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo '<tr><th>Chave</th><th>Valor</th></tr>';
        foreach ($configs as $config) {
            $valor = strlen($config['valor']) > 50 ? substr($config['valor'], 0, 50) . '...' : $config['valor'];
            echo '<tr><td>' . htmlspecialchars($config['chave']) . '</td><td>' . htmlspecialchars($valor) . '</td></tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
    if (defined('APP_ENV') && APP_ENV !== 'production') {
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
}
