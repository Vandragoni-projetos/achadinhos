<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

startSession();

// Se já estiver logado, redirecionar para dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, username, password FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Usuário ou senha incorretos!';
        }
} else {
            $error = 'Por favor, preencha todos os campos!';
    }
}
$loginFavicon = getConfig('favicon', '');
$loginFaviconUrl = $loginFavicon ? '../' . $loginFavicon : '/favicon.png';
$loginTemaCor = getConfig('tema_cor', '#f97316');
$loginTemaRgb = '249, 115, 22';
$loginTemaEscuro = '#ea580c';
if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $loginTemaCor, $m)) {
    $loginTemaRgb = hexdec($m[1]) . ', ' . hexdec($m[2]) . ', ' . hexdec($m[3]);
    $r = max(0, min(255, hexdec($m[1]) - 25));
    $g = max(0, min(255, hexdec($m[2]) - 25));
    $b = max(0, min(255, hexdec($m[3]) - 25));
    $loginTemaEscuro = sprintf('#%02x%02x%02x', $r, $g, $b);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($loginFaviconUrl); ?>" />
    <title>Login - Painel Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --theme-primary: <?php echo $loginTemaCor; ?>; --theme-primary-dark: <?php echo $loginTemaEscuro; ?>; --theme-rgb: <?php echo $loginTemaRgb; ?>; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .login-card {
            animation: slideUp 0.5s ease-out;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .input-focus:focus { box-shadow: 0 0 0 3px rgba(var(--theme-rgb), 0.2); }
        .btn-entrar:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px -5px rgba(var(--theme-rgb), 0.4);
        }
        .bg-mesh {
            background-color: #0f172a;
            background-image: 
                radial-gradient(at 40% 20%, rgba(30, 41, 59, 0.8) 0px, transparent 50%),
                radial-gradient(at 80% 0%, rgba(var(--theme-rgb), 0.15) 0px, transparent 50%),
                radial-gradient(at 0% 50%, rgba(51, 65, 85, 0.6) 0px, transparent 50%);
        }
        .bg-orange-500, .bg-orange-600 { background-color: var(--theme-primary) !important; }
        .hover\:bg-orange-600:hover { background-color: var(--theme-primary-dark) !important; }
        .focus\:border-orange-500:focus, .focus\:ring-orange-500:focus { border-color: var(--theme-primary) !important; --tw-ring-color: var(--theme-primary) !important; }
        .shadow-orange-500\/25 { --tw-shadow-color: rgba(var(--theme-rgb), 0.25) !important; }
    </style>
</head>
<body class="bg-mesh min-h-screen flex items-center justify-center p-4">
    <div class="login-card bg-white/95 backdrop-blur-sm rounded-2xl p-8 sm:p-10 w-full max-w-md border border-white/20">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-orange-500 text-white mb-4 shadow-lg shadow-orange-500/25">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">OfertasJá</h1>
            <p class="text-sm text-gray-500 mt-1">Painel Administrativo</p>
        </div>
        
        <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm flex items-center gap-3" role="alert">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="space-y-5">
            <div>
                <label for="username" class="block text-sm font-semibold text-gray-700 mb-2">Usuário</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    required
                    autofocus
                    placeholder="Digite seu usuário"
                    class="input-focus w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50/50 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-orange-500 focus:bg-white transition-colors"
                >
            </div>
            
            <div>
                <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">Senha</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required
                    placeholder="Digite sua senha"
                    class="input-focus w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50/50 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-orange-500 focus:bg-white transition-colors"
                >
            </div>
            
            <button 
                type="submit" 
                class="btn-entrar w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold py-3.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition-all duration-200"
            >
                Entrar
            </button>
        </form>
        
        <p class="mt-6 text-center text-xs text-gray-400">AfiliadosPro &copy; <?php echo date('Y'); ?></p>
    </div>
</body>
</html>
