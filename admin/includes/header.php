<?php
if (ob_get_level() === 0) {
    ob_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

startSession();
if (!isset($_SESSION['admin_id']) || (string) $_SESSION['admin_id'] === '') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['admin_autosave_token'])) {
    $_SESSION['admin_autosave_token'] = bin2hex(random_bytes(16));
}
$adminAutosaveToken = $_SESSION['admin_autosave_token'];

if (defined('APP_ENV') && APP_ENV !== 'production') {
    @file_put_contents(__DIR__ . '/../../debug-cca25f.log', json_encode(['sessionId' => 'cca25f', 'hypothesisId' => 'H_admin', 'location' => 'admin/header.php', 'message' => 'admin_authed', 'data' => ['page' => basename($_SERVER['PHP_SELF'] ?? '')], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

$currentPage = basename($_SERVER['PHP_SELF']);
$headerFavicon = getConfig('favicon', '');
$headerFaviconUrl = $headerFavicon ? '../' . $headerFavicon : '/favicon.png';
$adminDisplayVersion = getConfig('admin_display_version', '4.0');
if ($adminDisplayVersion === '') {
    $adminDisplayVersion = '4.0';
}
$headerTemaCor = getConfig('tema_cor', '#f97316');
$headerTemaRgb = '249, 115, 22';
$headerTemaCorEscuro = $headerTemaCor;
if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $headerTemaCor, $m)) {
    $headerTemaRgb = hexdec($m[1]) . ', ' . hexdec($m[2]) . ', ' . hexdec($m[3]);
    $r = max(0, min(255, hexdec($m[1]) - 30));
    $g = max(0, min(255, hexdec($m[2]) - 30));
    $b = max(0, min(255, hexdec($m[3]) - 30));
    $headerTemaCorEscuro = sprintf('#%02x%02x%02x', $r, $g, $b);
}

ensureAdminAvatarColumn();
$headerAdminId = (int) ($_SESSION['admin_id'] ?? 0);
$headerAdminAvatar = $headerAdminId > 0 ? getAdminAvatarPathById($headerAdminId) : '';
$headerAdminUsername = (string) ($_SESSION['admin_username'] ?? '');
$headerAdminInitial = '?';
if ($headerAdminUsername !== '') {
    $ch = function_exists('mb_substr') ? mb_substr($headerAdminUsername, 0, 1, 'UTF-8') : substr($headerAdminUsername, 0, 1);
    if ($ch !== '' && $ch !== false) {
        $headerAdminInitial = function_exists('mb_strtoupper') ? mb_strtoupper($ch, 'UTF-8') : strtoupper($ch);
    }
}
$headerAdminAvatarV = '';
if ($headerAdminAvatar !== '') {
    $absA = __DIR__ . '/../../' . $headerAdminAvatar;
    $headerAdminAvatarV = is_file($absA) ? (string) filemtime($absA) : (string) time();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($headerFaviconUrl); ?>" />
    <title><?php echo $pageTitle ?? 'Painel Admin'; ?> - OfertasJá</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>window.CRON_CONFIG=<?php echo CronPolicy::adminScriptConfigJson(); ?>;</script>
    <style>
        :root {
            --theme-primary: <?php echo $headerTemaCor; ?>;
            --theme-primary-dark: <?php echo $headerTemaCorEscuro; ?>;
            --theme-primary-rgb: <?php echo $headerTemaRgb; ?>;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .nav-link { transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease; }
        .nav-link.active { box-shadow: 0 2px 8px rgba(var(--theme-primary-rgb), 0.25); }
        .nav-section { letter-spacing: 0.05em; }
        /* Sobrescrever cores laranja do tema com a cor configurada */
        .bg-orange-500, .bg-orange-600, .bg-orange-700 { background-color: var(--theme-primary) !important; }
        .hover\:bg-orange-600:hover, .hover\:bg-orange-700:hover { background-color: var(--theme-primary-dark) !important; }
        .bg-orange-500\/90 { background-color: rgba(var(--theme-primary-rgb), 0.9) !important; }
        .bg-orange-500\/20 { background-color: rgba(var(--theme-primary-rgb), 0.2) !important; }
        .text-orange-400, .text-orange-500, .text-orange-600 { color: var(--theme-primary) !important; }
        .text-orange-700, .text-orange-800 { color: var(--theme-primary-dark) !important; }
        .hover\:text-orange-700:hover, .hover\:text-orange-800:hover { color: var(--theme-primary-dark) !important; }
        .bg-orange-100 { background-color: rgba(var(--theme-primary-rgb), 0.14) !important; }
        .hover\:bg-orange-50:hover { background-color: rgba(var(--theme-primary-rgb), 0.09) !important; }
        .border-orange-500 { border-color: var(--theme-primary) !important; }
        .focus\:ring-orange-500:focus, .focus\:ring-orange-600:focus { --tw-ring-color: var(--theme-primary) !important; }
        .shadow-orange-500\/20 { box-shadow: 0 0 0 1px rgba(var(--theme-primary-rgb), 0.2) !important; }
        /* Grupo Lojas: mesmo fundo da sidebar; só a árvore marca hierarquia */
        .lojas-nav-group { background: transparent; border: none; padding: 0; box-shadow: none; }
        .lojas-submenu-tree { margin-top: 0.25rem; margin-left: 1.375rem; padding-left: 0.65rem; border-left: 2px solid rgba(71, 85, 105, 0.75); padding-top: 0.15rem; padding-bottom: 0.15rem; }
        .lojas-sublink { transform: none !important; }
        .lojas-sublink.lojas-sublink--active { background-color: rgba(67, 20, 7, 0.45) !important; color: var(--theme-primary) !important; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2); }
        #lojas-submenu.lojas-submenu-flyout { background: rgba(15, 23, 42, 0.98) !important; }
        #lojas-submenu.lojas-submenu-flyout .lojas-submenu-tree { margin-left: 0.35rem; border-left-color: rgba(71, 85, 105, 0.85); }
        /* Sidebar: colapsada só em desktop (ícones) */
        @media (min-width: 1024px) {
            body.admin-nav-collapsed #admin-sidebar { width: 4.25rem !important; }
            body.admin-nav-collapsed #admin-header-brand { width: 4.25rem !important; min-width: 4.25rem !important; flex: 0 0 4.25rem !important; }
            body.admin-nav-collapsed #admin-header-brand { justify-content: center; padding-left: 0.25rem; padding-right: 0.25rem; }
            body.admin-nav-collapsed #admin-header-brand > div { width: 100%; justify-content: center; }
            body.admin-nav-collapsed .admin-header-brand-link { display: none !important; }
            body.admin-nav-collapsed .admin-nav-label,
            body.admin-nav-collapsed .admin-nav-section { display: none !important; }
            body.admin-nav-collapsed .admin-nav-item { justify-content: center !important; gap: 0 !important; padding-left: 0.5rem !important; padding-right: 0.5rem !important; }
            body.admin-nav-collapsed #lojas-arrow { display: none !important; }
            body.admin-nav-collapsed #lojas-submenu:not(.lojas-submenu-flyout) { display: none !important; }
            body.admin-nav-collapsed .admin-nav-collapsed-hide { display: none !important; }
            body.admin-nav-collapsed .nav-link:hover { transform: none; }
            body.admin-nav-collapsed #lojas-submenu.lojas-submenu-flyout .admin-nav-label { display: block !important; }
            body.admin-nav-collapsed #lojas-submenu.lojas-submenu-flyout .admin-nav-collapsed-hide { display: inline-block !important; }
            body.admin-nav-collapsed #lojas-submenu.lojas-submenu-flyout .admin-nav-item { justify-content: space-between !important; padding-left: 0.75rem !important; padding-right: 0.75rem !important; }
        }
        /* Desktop: sidebar sempre visível e estável (não depender do Tailwind carregar para translate) */
        @media (min-width: 1024px) {
            #admin-sidebar {
                position: relative !important;
                left: auto !important;
                top: auto !important;
                bottom: auto !important;
                transform: none !important;
                max-height: none !important;
            }
        }
        /* Mobile: drawer */
        @media (max-width: 1023px) {
            #admin-sidebar { position: fixed; left: 0; top: 3.25rem; bottom: 0; z-index: 50; width: 16rem; max-height: calc(100dvh - 3.25rem); transform: translateX(-100%); transition: transform 0.2s ease-out; }
            body.admin-mobile-nav-open #admin-sidebar { transform: translateX(0); }
            #admin-nav-backdrop { display: none; }
            body.admin-mobile-nav-open #admin-nav-backdrop { display: block; }
            body.admin-mobile-nav-open { overflow: hidden; }
        }
    </style>
</head>
<body class="bg-gray-100 overflow-hidden" data-admin-autosave-token="<?php echo htmlspecialchars($adminAutosaveToken, ENT_QUOTES, 'UTF-8'); ?>">
    <script>
    (function () {
        try {
            if (typeof localStorage === 'undefined' || !window.matchMedia) return;
            if (!window.matchMedia('(min-width: 1024px)').matches) return;
            if (localStorage.getItem('admin_sidebar_collapsed') !== '1') return;
            document.body.classList.add('admin-nav-collapsed');
        } catch (e) {}
    })();
    </script>
    <div class="flex h-screen flex-col overflow-hidden bg-gray-100">
        <div id="admin-nav-backdrop" class="fixed inset-0 z-40 bg-black/50 lg:hidden" aria-hidden="true"></div>
        <header class="relative z-[60] flex min-h-[3.25rem] w-full shrink-0 items-stretch border-b border-slate-700/50 bg-slate-900" role="banner">
            <div id="admin-header-brand" class="flex min-w-0 w-auto max-w-full shrink-0 items-center justify-start border-r border-slate-700/50 py-2 px-3 transition-[width,min-width] duration-200 ease-out sm:px-4 lg:w-64 lg:min-w-[16rem] lg:max-w-[16rem] lg:shrink-0">
                <div class="flex w-full min-w-0 items-center gap-2 sm:gap-2.5">
                <a href="index.php" class="admin-header-brand-link flex min-w-0 flex-1 items-center gap-2 rounded-lg py-0.5 transition-colors hover:bg-slate-800/40 sm:gap-2.5 lg:gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-orange-500 shadow-lg shadow-orange-500/20 sm:h-10 sm:w-10">
                        <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <span class="admin-nav-label block truncate text-base font-bold tracking-tight text-white sm:text-lg">OfertasJá</span>
                        <span class="admin-nav-label block truncate text-xs font-medium text-slate-400">Admin</span>
                    </div>
                </a>
                <button type="button" id="admin-sidebar-toggle" class="flex h-10 w-10 shrink-0 flex-none items-center justify-center rounded-lg text-slate-300 transition-colors hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Abrir ou fechar menu lateral">
                    <svg id="admin-sidebar-toggle-icon-open" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <svg id="admin-sidebar-toggle-icon-close" class="hidden h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                </div>
            </div>
            <div class="flex min-w-0 flex-1 items-center justify-end px-3 py-1 sm:px-4">
            <div class="relative" id="admin-user-menu-root">
                <button type="button" id="admin-user-menu-btn" class="flex max-w-[min(100vw-1.5rem,18rem)] items-center gap-2.5 rounded-lg py-1 pl-1 pr-2 text-left transition-colors hover:bg-slate-800/90" aria-expanded="false" aria-haspopup="menu" aria-controls="admin-user-menu-panel">
                    <?php if ($headerAdminAvatar !== ''): ?>
                    <span class="relative h-9 w-9 shrink-0 overflow-hidden rounded-full ring-2 ring-slate-600">
                        <img src="../<?php echo htmlspecialchars($headerAdminAvatar); ?><?php echo $headerAdminAvatarV !== '' ? '?v=' . rawurlencode($headerAdminAvatarV) : ''; ?>" alt="" class="h-full w-full object-cover">
                    </span>
                    <?php else: ?>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-700 text-sm font-semibold text-white ring-2 ring-slate-600"><?php echo htmlspecialchars($headerAdminInitial); ?></span>
                    <?php endif; ?>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold leading-tight text-white">Painel admin</span>
                        <span class="block truncate text-xs leading-tight text-slate-400"><?php echo htmlspecialchars($headerAdminUsername !== '' ? $headerAdminUsername : 'Administrador'); ?></span>
                    </span>
                    <svg id="admin-user-menu-chevron" class="h-4 w-4 shrink-0 text-slate-300 transition-transform duration-200 ease-out" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div id="admin-user-menu-panel" class="absolute right-0 top-full z-50 mt-1.5 hidden min-w-[13.5rem] overflow-hidden rounded-xl border border-slate-700/80 bg-slate-900 py-1 shadow-xl shadow-black/40" role="menu" aria-labelledby="admin-user-menu-btn">
                    <a href="configuracoes.php?tab=conta" class="flex items-center gap-3 px-3 py-2.5 text-sm text-slate-200 transition-colors hover:bg-slate-800/90" role="menuitem">
                        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        Meu perfil
                    </a>
                    <a href="configuracoes.php?tab=geral" class="flex items-center gap-3 px-3 py-2.5 text-sm text-slate-200 transition-colors hover:bg-slate-800/90" role="menuitem">
                        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        Configurações
                    </a>
                    <a href="../index.php" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 px-3 py-2.5 text-sm text-slate-200 transition-colors hover:bg-slate-800/90" role="menuitem">
                        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        Ver site
                    </a>
                    <div class="my-1 border-t border-slate-700/80" role="separator"></div>
                    <div class="group flex items-center justify-between gap-3 px-3 py-2.5 transition-colors hover:bg-red-950/40" role="none">
                        <a href="logout.php" class="flex min-w-0 flex-1 items-center gap-3 text-sm text-red-400 transition-colors group-hover:text-red-300" role="menuitem">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                            Sair
                        </a>
                        <span class="flex shrink-0 items-baseline gap-0 text-xs font-medium tabular-nums text-slate-500" role="note">
                            <span class="opacity-70" aria-hidden="true">V</span><span id="admin-version-num" class="cursor-pointer rounded px-0.5 outline-none hover:bg-slate-800/80 hover:text-slate-300 focus-visible:ring-1 focus-visible:ring-slate-500" title="Clique 5 vezes para editar a versão" tabindex="0" data-version="<?php echo htmlspecialchars($adminDisplayVersion, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminDisplayVersion); ?></span>
                        </span>
                    </div>
                </div>
            </div>
            </div>
        </header>
        <script>
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                var root = document.getElementById('admin-user-menu-root');
                var btn = document.getElementById('admin-user-menu-btn');
                var panel = document.getElementById('admin-user-menu-panel');
                var chev = document.getElementById('admin-user-menu-chevron');
                if (!root || !btn || !panel) return;
                function openMenu(open) {
                    panel.classList.toggle('hidden', !open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
                }
                btn.addEventListener('click', function () {
                    openMenu(panel.classList.contains('hidden'));
                });
                document.addEventListener('click', function (e) {
                    if (!root.contains(e.target)) openMenu(false);
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') openMenu(false);
                });
            });
        })();
        </script>
        <script>
        (function () {
            function adminConfigPatchUrl() {
                var p = window.location.pathname || '';
                var i = p.lastIndexOf('/');
                return (i >= 0 ? p.slice(0, i + 1) : '/') + 'api/config-patch.php';
            }
            var SPAN_CLASS = 'cursor-pointer rounded px-0.5 outline-none hover:bg-slate-800/80 hover:text-slate-300 focus-visible:ring-1 focus-visible:ring-slate-500';
            var SPAN_TITLE = 'Clique 5 vezes para editar a versão';

            document.addEventListener('DOMContentLoaded', function () {
                var el = document.getElementById('admin-version-num');
                if (!el) return;
                var clicks = 0;
                var resetTimer;
                var body = document.body;
                var token = body.getAttribute('data-admin-autosave-token') || '';

                function mountSpan(parent, val) {
                    var s = document.createElement('span');
                    s.id = 'admin-version-num';
                    s.className = SPAN_CLASS;
                    s.title = SPAN_TITLE;
                    s.tabIndex = 0;
                    s.setAttribute('data-version', val);
                    s.textContent = val;
                    parent.appendChild(s);
                    return s;
                }

                function replaceWithSpan(node, val) {
                    var p = node.parentNode;
                    if (!p) return null;
                    p.removeChild(node);
                    return mountSpan(p, val);
                }

                function saveVersion(raw, revertVal) {
                    var v = String(raw || '').replace(/^\s*V\s*/i, '').trim();
                    fetch(adminConfigPatchUrl(), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Autosave-Token': token
                        },
                        body: JSON.stringify({ key: 'admin_display_version', value: v, token: token })
                    })
                        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                        .then(function (x) {
                            if (x.ok && x.j && x.j.ok && x.j.value) {
                                el = replaceWithSpan(el, x.j.value) || el;
                                bindSpan();
                            } else {
                                var msg = (x.j && x.j.error) ? x.j.error : 'Não foi possível guardar.';
                                alert(msg);
                                el = replaceWithSpan(el, revertVal) || el;
                                bindSpan();
                            }
                        })
                        .catch(function () {
                            alert('Erro de rede ao guardar.');
                            el = replaceWithSpan(el, revertVal) || el;
                            bindSpan();
                        });
                }

                function enterEdit() {
                    clicks = 0;
                    if (el.tagName !== 'SPAN') return;
                    var parent = el.parentNode;
                    var revert = el.getAttribute('data-version') || el.textContent.trim() || '4.0';
                    var inp = document.createElement('input');
                    inp.type = 'text';
                    inp.className = 'min-w-[3.5rem] max-w-[6rem] rounded border border-slate-600 bg-slate-800 px-1 py-0.5 text-xs text-slate-200 tabular-nums';
                    inp.value = revert;
                    inp.setAttribute('aria-label', 'Versão do painel');
                    parent.replaceChild(inp, el);
                    el = inp;
                    inp.focus();
                    inp.select();
                    var done = false;
                    function cleanup() {
                        done = true;
                        inp.removeEventListener('blur', onBlur);
                        inp.removeEventListener('keydown', onKey);
                    }
                    function finish() {
                        if (done) return;
                        cleanup();
                        saveVersion(inp.value, revert);
                    }
                    function onBlur() { finish(); }
                    function onKey(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            finish();
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            if (done) return;
                            cleanup();
                            el = replaceWithSpan(inp, revert) || el;
                            bindSpan();
                        }
                    }
                    inp.addEventListener('blur', onBlur);
                    inp.addEventListener('keydown', onKey);
                }

                function bindSpan() {
                    if (!el || el.tagName !== 'SPAN') return;
                    el.onclick = function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        clicks++;
                        clearTimeout(resetTimer);
                        resetTimer = setTimeout(function () { clicks = 0; }, 2000);
                        if (clicks >= 5) {
                            clicks = 0;
                            enterEdit();
                        }
                    };
                }
                bindSpan();
            });
        })();
        </script>
        <script>
        (function () {
            var STORAGE = 'admin_sidebar_collapsed';
            var MQ = '(min-width: 1024px)';

            function isDesktop() {
                return window.matchMedia(MQ).matches;
            }

            function adminStripLojasFlyout(submenu) {
                if (!submenu) return;
                ['lojas-submenu-flyout', 'fixed', 'rounded-xl', 'border', 'border-slate-700', 'bg-slate-900', 'p-2', 'shadow-xl'].forEach(function (c) {
                    submenu.classList.remove(c);
                });
                submenu.style.left = '';
                submenu.style.top = '';
                submenu.style.maxWidth = '';
                submenu.style.maxHeight = '';
                submenu.style.overflowY = '';
                submenu.style.minWidth = '';
                submenu.style.zIndex = '';
            }

            function adminCloseLojasFlyout() {
                var submenu = document.getElementById('lojas-submenu');
                var arrow = document.getElementById('lojas-arrow');
                var btn = document.getElementById('lojas-menu-btn');
                if (!submenu) return;
                adminStripLojasFlyout(submenu);
                submenu.classList.add('hidden');
                if (arrow) arrow.classList.remove('rotate-90');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }

            function adminPositionLojasFlyout() {
                var submenu = document.getElementById('lojas-submenu');
                var btn = document.getElementById('lojas-menu-btn');
                if (!submenu || !btn || !submenu.classList.contains('lojas-submenu-flyout')) return;
                var r = btn.getBoundingClientRect();
                var w = Math.min(280, window.innerWidth - r.right - 16);
                submenu.style.left = (r.right + 6) + 'px';
                submenu.style.top = Math.max(8, Math.min(r.top, window.innerHeight - submenu.offsetHeight - 8)) + 'px';
                submenu.style.maxWidth = w + 'px';
            }

            window.toggleLojasMenu = function (evt) {
                if (evt && evt.stopPropagation) evt.stopPropagation();
                var submenu = document.getElementById('lojas-submenu');
                var arrow = document.getElementById('lojas-arrow');
                var btn = document.getElementById('lojas-menu-btn');
                if (!submenu || !btn) return;

                var collapsed = document.body.classList.contains('admin-nav-collapsed');
                var useFlyout = collapsed && isDesktop();

                if (useFlyout) {
                    var flyHidden = submenu.classList.contains('hidden');
                    if (flyHidden) {
                        submenu.classList.remove('hidden');
                        submenu.classList.add('lojas-submenu-flyout', 'fixed', 'rounded-xl', 'border', 'border-slate-700', 'bg-slate-900', 'p-2', 'shadow-xl');
                        submenu.style.zIndex = '80';
                        submenu.style.maxHeight = 'min(70vh, 24rem)';
                        submenu.style.overflowY = 'auto';
                        submenu.style.minWidth = '13rem';
                        if (arrow) arrow.classList.add('rotate-90');
                        btn.setAttribute('aria-expanded', 'true');
                        adminPositionLojasFlyout();
                    } else {
                        adminCloseLojasFlyout();
                    }
                    return;
                }

                adminStripLojasFlyout(submenu);
                var isHidden = submenu.classList.contains('hidden');
                if (isHidden) {
                    submenu.classList.remove('hidden');
                    if (arrow) arrow.classList.add('rotate-90');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    submenu.classList.add('hidden');
                    if (arrow) arrow.classList.remove('rotate-90');
                    btn.setAttribute('aria-expanded', 'false');
                }
            };

            function adminSyncMobileToggleIcon() {
                var openI = document.getElementById('admin-sidebar-toggle-icon-open');
                var closeI = document.getElementById('admin-sidebar-toggle-icon-close');
                var on = document.body.classList.contains('admin-mobile-nav-open');
                if (openI && closeI) {
                    openI.classList.toggle('hidden', on);
                    closeI.classList.toggle('hidden', !on);
                }
            }

            function adminSetMobileNav(open) {
                document.body.classList.toggle('admin-mobile-nav-open', open);
                var t = document.getElementById('admin-sidebar-toggle');
                if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
                adminSyncMobileToggleIcon();
            }

            function adminApplyDesktopCollapsed(collapsed) {
                document.body.classList.toggle('admin-nav-collapsed', collapsed);
                try {
                    localStorage.setItem(STORAGE, collapsed ? '1' : '0');
                } catch (e) {}
                adminCloseLojasFlyout();
            }

            document.addEventListener('DOMContentLoaded', function () {
                var toggle = document.getElementById('admin-sidebar-toggle');
                var backdrop = document.getElementById('admin-nav-backdrop');

                if (isDesktop()) {
                    try {
                        if (localStorage.getItem(STORAGE) === '1') {
                            document.body.classList.add('admin-nav-collapsed');
                        }
                    } catch (e) {}
                } else {
                    adminSetMobileNav(false);
                }

                if (toggle) {
                    toggle.addEventListener('click', function () {
                        if (isDesktop()) {
                            adminApplyDesktopCollapsed(!document.body.classList.contains('admin-nav-collapsed'));
                        } else {
                            adminSetMobileNav(!document.body.classList.contains('admin-mobile-nav-open'));
                        }
                    });
                }

                if (backdrop) {
                    backdrop.addEventListener('click', function () {
                        adminSetMobileNav(false);
                    });
                }

                document.querySelectorAll('#admin-sidebar a[href]').forEach(function (a) {
                    a.addEventListener('click', function () {
                        if (!isDesktop()) adminSetMobileNav(false);
                    });
                });

                document.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    if (!isDesktop()) adminSetMobileNav(false);
                    if (isDesktop() && document.body.classList.contains('admin-nav-collapsed')) adminCloseLojasFlyout();
                });

                document.addEventListener('click', function (e) {
                    if (!document.body.classList.contains('admin-nav-collapsed') || !isDesktop()) return;
                    var sub = document.getElementById('lojas-submenu');
                    var btn = document.getElementById('lojas-menu-btn');
                    if (!sub || !sub.classList.contains('lojas-submenu-flyout') || sub.classList.contains('hidden')) return;
                    if (sub.contains(e.target) || (btn && btn.contains(e.target))) return;
                    adminCloseLojasFlyout();
                });

                window.addEventListener('resize', function () {
                    if (isDesktop()) {
                        document.body.classList.remove('admin-mobile-nav-open');
                        adminSyncMobileToggleIcon();
                        try {
                            if (localStorage.getItem(STORAGE) === '1') {
                                document.body.classList.add('admin-nav-collapsed');
                            } else {
                                document.body.classList.remove('admin-nav-collapsed');
                            }
                        } catch (e) {
                            document.body.classList.remove('admin-nav-collapsed');
                        }
                        var sub = document.getElementById('lojas-submenu');
                        if (sub && sub.classList.contains('lojas-submenu-flyout')) {
                            if (!document.body.classList.contains('admin-nav-collapsed')) {
                                adminStripLojasFlyout(sub);
                            } else {
                                adminPositionLojasFlyout();
                            }
                        }
                    } else {
                        document.body.classList.remove('admin-nav-collapsed');
                        var sub2 = document.getElementById('lojas-submenu');
                        if (sub2 && sub2.classList.contains('lojas-submenu-flyout')) {
                            adminStripLojasFlyout(sub2);
                            sub2.classList.remove('hidden');
                        }
                        adminSetMobileNav(false);
                    }
                });

                window.addEventListener('scroll', function () {
                    if (document.body.classList.contains('admin-nav-collapsed') && isDesktop()) adminPositionLojasFlyout();
                }, true);
            });
        })();
        </script>

        <div class="flex min-h-0 min-w-0 flex-1 overflow-hidden lg:min-h-0">
        <!-- Sidebar -->
        <aside id="admin-sidebar" class="flex w-64 shrink-0 flex-col overflow-y-auto border-r border-slate-700/50 bg-slate-900 text-white min-h-0 lg:relative lg:top-auto lg:max-h-none lg:translate-x-0" aria-label="Menu principal">
            <nav class="space-y-1 p-3 pb-6 sm:p-4">
                <p class="admin-nav-section nav-section px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Principal</p>
                <a href="index.php" 
                   class="admin-nav-item nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl <?php echo $currentPage === 'index.php' ? 'bg-orange-500 text-white active' : 'text-slate-300 hover:bg-slate-800'; ?>">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                    <span class="admin-nav-label font-medium">Dashboard</span>
                </a>
                
                <a href="produtos.php" 
                   class="admin-nav-item nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl <?php echo $currentPage === 'produtos.php' || strpos($currentPage, 'produto') !== false ? 'bg-orange-500 text-white active' : 'text-slate-300 hover:bg-slate-800'; ?>">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span class="admin-nav-label font-medium">Produtos</span>
                </a>
                
                <a href="grupos.php" 
                   class="admin-nav-item nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl <?php echo $currentPage === 'grupos.php' ? 'bg-orange-500 text-white active' : 'text-slate-300 hover:bg-slate-800'; ?>">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="admin-nav-label font-medium">Grupos / publicação</span>
                </a>
                
                <!-- Lojas -->
                <?php
$lojasPages = ['mercadolivre.php', 'mercadolivre-api.php', 'shopee.php', 'magalu.php', 'amazon.php', 'shein.php', 'aliexpress.php'];
                $isLojasPage = in_array($currentPage, $lojasPages);
                $lojasExpanded = $isLojasPage;
                ?>
                <p class="admin-nav-section nav-section mt-4 px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Lojas</p>
                <?php
                $lojaSublinkClass = function (string $file) use ($currentPage): string {
                    $on = $currentPage === $file;
                    $base = 'lojas-sublink admin-nav-item nav-link flex items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ';
                    return $base . ($on
                        ? 'lojas-sublink--active text-orange-400'
                        : 'text-slate-300 hover:bg-slate-800');
                };
                ?>
                <div class="lojas-nav-group">
                    <button type="button" id="lojas-menu-btn" onclick="toggleLojasMenu(event)" aria-expanded="<?php echo $lojasExpanded ? 'true' : 'false'; ?>"
                            class="admin-nav-item nav-link flex w-full items-center gap-3 rounded-xl border-0 px-3 py-2.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 <?php echo $isLojasPage ? 'bg-orange-500 text-white active' : 'bg-transparent text-slate-300 hover:bg-slate-800'; ?>">
                        <span class="flex min-w-0 flex-1 items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                            <span class="admin-nav-label font-medium">Afiliados</span>
                        </span>
                        <svg id="lojas-arrow" class="admin-nav-collapsed-hide h-4 w-4 shrink-0 opacity-80 transition-transform duration-200 ease-out <?php echo $lojasExpanded ? 'rotate-90' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                    <div id="lojas-submenu" class="<?php echo $lojasExpanded ? '' : 'hidden'; ?>">
                        <div class="lojas-submenu-tree space-y-0.5">
                        <a href="mercadolivre.php"
                           class="<?php echo $lojaSublinkClass('mercadolivre.php'); ?>">
                            <span class="admin-nav-label min-w-0 truncate">Mercado Livre</span>
                            <span class="admin-nav-collapsed-hide shrink-0"><?php echo adminNavLojaBadgeHtml('mercadolivre.php'); ?></span>
                        </a>
                        <a href="shopee.php"
                           class="<?php echo $lojaSublinkClass('shopee.php'); ?>">
                            <span class="admin-nav-label min-w-0 truncate">Shopee</span>
                            <span class="admin-nav-collapsed-hide shrink-0"><?php echo adminNavLojaBadgeHtml('shopee.php'); ?></span>
                        </a>
                        <?php /* Magalu, ML Cupons e Shein: ocultos no menu; magalu.php, mercadolivre-api.php e shein.php por URL. */ ?>
                        <a href="aliexpress.php"
                           class="<?php echo $lojaSublinkClass('aliexpress.php'); ?>">
                            <span class="admin-nav-label min-w-0 truncate">AliExpress</span>
                            <span class="admin-nav-collapsed-hide shrink-0"><?php echo adminNavLojaBadgeHtml('aliexpress.php'); ?></span>
                        </a>
                        <a href="amazon.php"
                           class="<?php echo $lojaSublinkClass('amazon.php'); ?>">
                            <span class="admin-nav-label min-w-0 truncate">Amazon</span>
                            <span class="admin-nav-collapsed-hide shrink-0"><?php echo adminNavLojaBadgeHtml('amazon.php'); ?></span>
                        </a>
                        </div>
                    </div>
                </div>
                
                <p class="admin-nav-section nav-section mt-4 px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Sistema</p>
                <a href="configuracoes.php" 
                   class="admin-nav-item nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl <?php echo $currentPage === 'configuracoes.php' ? 'bg-orange-500 text-white active' : 'text-slate-300 hover:bg-slate-800'; ?>">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="admin-nav-label font-medium">Configurações</span>
                </a>

                <div class="my-3 border-t border-slate-700/50" role="separator"></div>
                <a href="logout.php"
                   class="admin-nav-item nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 transition-colors hover:bg-red-500/10 hover:text-red-400">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    <span class="admin-nav-label font-medium">Sair</span>
                </a>
            </nav>
        </aside>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-gray-100">
