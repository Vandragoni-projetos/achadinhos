<?php
require_once __DIR__ . '/config.php';

// Bootstrap da licença (um único ponto: quem carrega o DB passa por aqui)
if (!defined('LICENCA_ATIVA')) {
    $__lp_root = dirname(__DIR__);
    if (is_file($__lp_root . '/loader_licenca.php')) {
        require_once $__lp_root . '/loader_licenca.php';
    } elseif (is_file($__lp_root . '/helpers/licenca_local_helper.php')) {
        require_once $__lp_root . '/helpers/licenca_local_helper.php';
        validarLicencaAfiliadosPRO();
        // Se chegou aqui e ainda não definiu LICENCA_ATIVA, a validação passou mas não definiu
        if (!defined('LICENCA_ATIVA')) {
            define('LICENCA_ATIVA', true);
        }
    } else {
        define('LICENCA_ATIVA', false);
    }
}
// Travamento: o sistema só funciona com licença ativa
if (!defined('LICENCA_ATIVA') || !LICENCA_ATIVA) {
    if (defined('APP_ENV') && APP_ENV !== 'production') {
        @file_put_contents(__DIR__ . '/../debug-cca25f.log', json_encode(['sessionId' => 'cca25f', 'hypothesisId' => 'H1', 'location' => 'database.php:license_gate', 'message' => 'license_blocked', 'data' => ['defined' => defined('LICENCA_ATIVA'), 'active' => defined('LICENCA_ATIVA') ? (bool) LICENCA_ATIVA : null, 'uri' => $_SERVER['REQUEST_URI'] ?? '', 'sapi' => php_sapi_name()], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log('achadinhos: license gate blocked request');
    }
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Licenca nao ativa.\n");
        exit(1);
    }
    http_response_code(403);
    header('Cache-Control: no-store, no-cache');
    exit('Acesso nao autorizado.');
}
if (empty($GLOBALS['__dbg_cca25f_boot'])) {
    $GLOBALS['__dbg_cca25f_boot'] = true;
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array((int) $e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (defined('APP_ENV') && APP_ENV !== 'production') {
            @file_put_contents(__DIR__ . '/../debug-cca25f.log', json_encode(['sessionId' => 'cca25f', 'hypothesisId' => 'H3', 'location' => 'shutdown_fatal', 'message' => 'php_fatal', 'data' => ['type' => $e['type'], 'file' => $e['file'], 'line' => $e['line'], 'err' => $e['message']], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } else {
            error_log('achadinhos fatal: ' . ($e['message'] ?? '') . ' in ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? ''));
        }
    });
    if (defined('APP_ENV') && APP_ENV !== 'production') {
        @file_put_contents(__DIR__ . '/../debug-cca25f.log', json_encode(['sessionId' => 'cca25f', 'hypothesisId' => 'H4', 'location' => 'database.php:boot_ok', 'message' => 'after_license_ok', 'data' => ['uri' => $_SERVER['REQUEST_URI'] ?? '', 'script' => $_SERVER['SCRIPT_NAME'] ?? ''], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}
// Configurações do banco de dados
// Compatibilidade: em hospedagem tradicional (Hostinger/cPanel) os valores fixos abaixo
// continuam a valer. Em Docker/EasyPanel, se as variáveis de ambiente correspondentes
// estiverem definidas e não vazias, elas têm prioridade. Nenhuma credencial real fica no código.
if (!function_exists('achadinhos_db_env')) {
    function achadinhos_db_env(string $nome, string $padrao): string
    {
        $v = getenv($nome);
        return ($v !== false && $v !== '') ? (string) $v : $padrao;
    }
}
if (!defined('DB_HOST')) { define('DB_HOST', achadinhos_db_env('DB_HOST', 'localhost')); }
if (!defined('DB_PORT')) { define('DB_PORT', achadinhos_db_env('DB_PORT', '3306')); }
if (!defined('DB_NAME')) { define('DB_NAME', achadinhos_db_env('DB_NAME', 'achadinhos')); }
if (!defined('DB_USER')) { define('DB_USER', achadinhos_db_env('DB_USER', 'root')); }
if (!defined('DB_PASS')) {
    // Aceita DB_PASSWORD (padrão) ou DB_PASS; senha vazia continua a ser válida.
    $__dbp = getenv('DB_PASSWORD');
    if ($__dbp === false) { $__dbp = getenv('DB_PASS'); }
    define('DB_PASS', $__dbp !== false ? (string) $__dbp : '');
    unset($__dbp);
}
define('DB_CHARSET', 'utf8mb4');

// Função para conectar ao banco de dados
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (defined('APP_ENV') && APP_ENV !== 'production') {
                @file_put_contents(__DIR__ . '/../debug-cca25f.log', json_encode(['sessionId' => 'cca25f', 'hypothesisId' => 'H2', 'location' => 'getDB:pdo', 'message' => 'pdo_connect_failed', 'data' => ['err' => $e->getMessage(), 'uri' => $_SERVER['REQUEST_URI'] ?? ''], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            } else {
                error_log('achadinhos getDB: ' . $e->getMessage());
            }
            die('Erro na conexão com o banco de dados.');
        }
    }
    
    return $pdo;
}

// Função para iniciar sessão
function startSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_start();
}

// Função para verificar se está logado
function isLoggedIn() {
    startSession();
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Antes de jobs longos no painel (automação): libera o lock da sessão e aumenta o tempo de execução do PHP.
 */
function achadinhosPainelApiPrepararJobLongo(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ignore_user_abort(true);
    @ini_set('max_execution_time', '360');
    @set_time_limit(360);
}

// Função para fazer logout
function logout() {
    startSession();
    $_SESSION = [];
    session_destroy();
}
