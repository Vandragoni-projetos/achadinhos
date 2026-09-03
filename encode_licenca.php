<?php
/**
 * Gera o loader de licença criptografado. Execute no seu PC antes de distribuir o sistema.
 * Uso: php encode_licenca.php
 * Gera/atualiza loader_licenca.php com o núcleo criptografado.
 * NÃO inclua core_licenca.php nem este arquivo no pacote para clientes.
 */
$codeKey = 'AfiliadosPRO_Code_2024_32bytes__'; // 32 bytes para AES-256
if (strlen($codeKey) !== 32) {
    $codeKey = str_pad($codeKey, 32, '0');
}
$coreFile = __DIR__ . DIRECTORY_SEPARATOR . 'core_licenca.php';
$loaderFile = __DIR__ . DIRECTORY_SEPARATOR . 'loader_licenca.php';

if (!is_file($coreFile)) {
    fwrite(STDERR, "Arquivo core_licenca.php nao encontrado.\n");
    exit(1);
}

$code = file_get_contents($coreFile);
$code = preg_replace('/^<\?php\s*/i', '', $code);
$code = trim($code);

$iv = random_bytes(16);
$encrypted = openssl_encrypt($code, 'aes-256-cbc', $codeKey, OPENSSL_RAW_DATA, $iv);
if ($encrypted === false) {
    fwrite(STDERR, "Erro ao criptografar.\n");
    exit(1);
}
$blob = base64_encode($iv . $encrypted);

$loaderContent = <<<'LOADER'
<?php
/** Loader de licença - não remova nem altere */
if (!defined('LICENCA_ATIVA')) {
    if (!defined('LP_ROOT')) { define('LP_ROOT', __DIR__); }
    $__k = 'AfiliadosPRO_Code_2024_32bytes__';
    if (strlen($__k) !== 32) { $__k = str_pad($__k, 32, '0'); }
    $__b = base64_decode('__BLOB__', true);
    if ($__b !== false && strlen($__b) > 16) {
        $__iv = substr($__b, 0, 16);
        $__c = substr($__b, 16);
        $__d = @openssl_decrypt($__c, 'aes-256-cbc', $__k, OPENSSL_RAW_DATA, $__iv);
        if ($__d !== false) { eval($__d); }
    }
    if (!defined('LICENCA_ATIVA')) { define('LICENCA_ATIVA', false); }
}
LOADER;

$loaderContent = str_replace('__BLOB__', $blob, $loaderContent);

if (file_put_contents($loaderFile, $loaderContent) === false) {
    fwrite(STDERR, "Erro ao escrever loader_licenca.php\n");
    exit(1);
}

echo "OK: loader_licenca.php gerado com nucleo criptografado.\n";
echo "Nao distribua core_licenca.php nem encode_licenca.php para clientes.\n";
