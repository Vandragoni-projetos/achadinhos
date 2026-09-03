<?php
// Teste direto de salvamento (apenas admin autenticado)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (achadinhosIsProduction()) {
    http_response_code(404);
    exit;
}

if (!isLoggedIn()) {
    die('Você precisa estar logado!');
}

echo "<h1>Teste de Salvamento</h1>";

// Testar salvamento direto
echo "<h2>Testando salvamento direto:</h2>";

$testValue = 'teste_' . date('Y-m-d H:i:s');
$result = setConfig('teste_save', $testValue);

if ($result) {
    echo "<p style='color: green;'>✓ setConfig retornou TRUE</p>";
} else {
    echo "<p style='color: red;'>✗ setConfig retornou FALSE</p>";
}

// Verificar se foi salvo
$valorRecuperado = getConfig('teste_save');

if ($valorRecuperado === $testValue) {
    echo "<p style='color: green;'>✓ Valor foi salvo e recuperado: '{$valorRecuperado}'</p>";
} else {
    echo "<p style='color: red;'>✗ Valor NÃO foi salvo. Esperado: '{$testValue}', Obtido: '{$valorRecuperado}'</p>";
}

// Testar salvamento de footer
echo "<h2>Testando salvamento de configurações do footer:</h2>";

setConfig('footer_email', 'teste@teste.com');
setConfig('footer_instagram', 'https://instagram.com/teste');
setConfig('footer_facebook', 'https://facebook.com/teste');

$footerEmail = getConfig('footer_email');
$footerInstagram = getConfig('footer_instagram');
$footerFacebook = getConfig('footer_facebook');

echo "<p>footer_email: " . htmlspecialchars($footerEmail) . "</p>";
echo "<p>footer_instagram: " . htmlspecialchars($footerInstagram) . "</p>";
echo "<p>footer_facebook: " . htmlspecialchars($footerFacebook) . "</p>";

// Testar POST simulado
echo "<h2>Testando com valores simulados de POST:</h2>";

// Simular POST
$_POST['categorias_topbar'] = 'Teste|Categorias|Exemplo';
$_POST['footer_email'] = 'post@teste.com';
$_POST['footer_instagram'] = 'https://instagram.com/post';
$_POST['footer_facebook'] = 'https://facebook.com/post';
$_POST['banner_type'] = 'images';

echo "<p>Valores simulados:</p>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

setConfig('categorias_topbar', $_POST['categorias_topbar']);
setConfig('footer_email', $_POST['footer_email']);
setConfig('footer_instagram', $_POST['footer_instagram']);
setConfig('footer_facebook', $_POST['footer_facebook']);
setConfig('banner_type', $_POST['banner_type']);

// Verificar se foram salvos
echo "<h3>Valores recuperados do banco:</h3>";
echo "<p>categorias_topbar: " . htmlspecialchars(getConfig('categorias_topbar')) . "</p>";
echo "<p>footer_email: " . htmlspecialchars(getConfig('footer_email')) . "</p>";
echo "<p>footer_instagram: " . htmlspecialchars(getConfig('footer_instagram')) . "</p>";
echo "<p>footer_facebook: " . htmlspecialchars(getConfig('footer_facebook')) . "</p>";
echo "<p>banner_type: " . htmlspecialchars(getConfig('banner_type')) . "</p>";

?>
