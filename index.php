<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

try {
    $pdo = getDB();

    if (function_exists('achadinhosCategoriasPrecisamMescla') && achadinhosCategoriasPrecisamMescla($pdo)) {
        achadinhosMesclarCategoriasDuplicadas($pdo);
    }
    
    // Carregar configurações
    $logo = getConfig('logo');
    $favicon = getConfig('favicon', '');
    $siteNomeMarca = getConfig('site_nome_marca', 'Achadinhos Sinergia');
    $siteMarcaModo = getConfig('site_marca_modo', 'ambos');
    if (!in_array($siteMarcaModo, ['logo', 'texto', 'ambos'], true)) {
        $siteMarcaModo = 'ambos';
    }
    $hasLogoFile = !empty($logo);
    $showLogoImg = $hasLogoFile && ($siteMarcaModo === 'logo' || $siteMarcaModo === 'ambos');
    $showMarcaTexto = $siteMarcaModo === 'texto' || $siteMarcaModo === 'ambos' || ($siteMarcaModo === 'logo' && !$hasLogoFile);
    $showIconFallback = !$showLogoImg && $showMarcaTexto && ($siteMarcaModo === 'ambos' || $siteMarcaModo === 'logo');
    $temaCor = getConfig('tema_cor', '#f97316');
    $categoriasTopbar = getConfig('categorias_topbar', 'Eletrônicos|Celulares|Games|Computadores|Cozinha');
    $bannersJson = getConfig('banners', '[]');
    $footerEmail = getConfig('footer_email', 'contato@ofertasja.com.br');
    $footerInstagram = getConfig('footer_instagram', '#');
    $footerFacebook = getConfig('footer_facebook', '#');
    $whatsappGrupoUrl = getConfig('whatsapp_grupo_url', '');
    $whatsappGrupoBotaoTexto = trim(getConfig('whatsapp_grupo_botao_texto', ''));
    if ($whatsappGrupoBotaoTexto === '') {
        $whatsappGrupoBotaoTexto = 'Entrar No Grupo do Whatsapp';
    }
    $popupAtivo = getConfig('popup_ativo', '0') === '1';
    $popupImagem = getConfig('popup_imagem', '');
    
    // Topbar alinhada às categorias do banco (slug/nome reais; sem repetir âncora)
    $pipeTop = function_exists('achadinhosNormalizarListaTopbar')
        ? achadinhosNormalizarListaTopbar($categoriasTopbar)
        : $categoriasTopbar;
    $navItems = array_filter(array_map('trim', explode('|', $pipeTop)));
    $navItemsArray = [];
    $seenNavSlug = [];
    foreach ($navItems as $item) {
        if ($item === '') {
            continue;
        }
        $resolved = function_exists('achadinhosResolverCategoriaPorLabelTopbar')
            ? achadinhosResolverCategoriaPorLabelTopbar($pdo, $item)
            : null;
        if (!$resolved || empty($resolved['slug'])) {
            continue;
        }
        $slugNav = (string)$resolved['slug'];
        if (isset($seenNavSlug[$slugNav])) {
            continue;
        }
        $seenNavSlug[$slugNav] = true;
        $navItemsArray[] = [
            'label' => (string)$resolved['nome'],
            'href' => '#' . $slugNav,
        ];
    }
    
    // Verificar tipo de banner configurado
    $bannerType = getConfig('banner_type', 'images');
    $banners = [];
    $bannersDefault = [];
    
    if ($bannerType === 'default') {
        // Banners padrão (JSON com textos e gradientes)
        $bannersDefaultJson = getConfig('banners_json', '[]');
        $bannersDefault = json_decode($bannersDefaultJson, true) ?: [];
        if (empty($bannersDefault)) {
            // Banners padrão se não houver configuração
            $bannersDefault = [
                ['id' => 1, 'title' => 'Até 60% OFF', 'subtitle' => 'em Eletrônicos', 'description' => 'Ofertas imperdíveis para você', 'bgGradient' => 'from-[hsl(var(--primary))] via-orange-500 to-orange-600'],
                ['id' => 2, 'title' => 'Super Ofertas', 'subtitle' => 'em Celulares', 'description' => 'Os melhores smartphones com desconto', 'bgGradient' => 'from-orange-600 via-[hsl(var(--primary))] to-red-500'],
                ['id' => 3, 'title' => 'Black Friday', 'subtitle' => 'Todo Dia', 'description' => 'Preços baixos o ano inteiro', 'bgGradient' => 'from-red-500 via-orange-500 to-[hsl(var(--primary))]'],
            ];
        }
    } else {
        // Banners de imagens
        $banners = json_decode($bannersJson, true) ?: [];
        // Filtrar banners vazios e garantir que são strings válidas
        $banners = array_values(array_filter(array_map(function($banner) {
            return !empty($banner) && is_string($banner) ? trim($banner) : null;
        }, $banners)));
    }
    
    // Função para buscar produtos por categoria
    function getProdutosPorCategoria($pdo, $categoriaSlug = null, $destaque = false) {
        $sql = "SELECT p.*, c.slug as categoria_slug 
                FROM produtos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                WHERE p.ativo = 1";
        
        if ($destaque) {
            $sql .= " AND p.destaque = 1";
        } elseif ($categoriaSlug) {
            $sql .= " AND c.slug = ?";
        }
        
        $sql .= " ORDER BY p.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        if ($categoriaSlug && !$destaque) {
            $stmt->execute([$categoriaSlug]);
        } else {
            $stmt->execute();
        }
        
        $produtos = $stmt->fetchAll();
        
        // Converter para formato esperado
        $formatted = [];
        foreach ($produtos as $produto) {
            $preco = (float)($produto['preco'] ?? 0);
            $preco_parcela = !empty($produto['preco_parcela']) ? (float)$produto['preco_parcela'] : null;
            $parcelas = !empty($produto['parcelas']) ? (int)$produto['parcelas'] : null;
            
            // Se tem parcelas e o preço exibido é o valor da parcela (erro comum), usar total = parcela × quantidade
            if ($parcelas && $preco_parcela > 0) {
                $totalCorreto = round($preco_parcela * $parcelas, 2);
                // Preço total não pode ser menor ou igual ao valor de 1 parcela (senão está mostrando parcela como total)
                if ($preco <= $preco_parcela) {
                    $preco = $totalCorreto;
                }
            }
            
            $parcelaTexto = null;
            $parcelaValorFormatado = null;
            $parcelasNumero = null;
            if ($parcelas && $preco_parcela > 0) {
                $parcelaValorFormatado = number_format($preco_parcela, 2, ',', '.');
                $parcelasNumero = (int)$parcelas;
                $parcelaTexto = $parcelasNumero . 'x de R$ ' . $parcelaValorFormatado;
            }
            $formatted[] = [
                'id' => $produto['id'],
                'image' => $produto['imagem'] ? $produto['imagem'] : 'https://via.placeholder.com/400',
                'title' => $produto['nome'],
                'price' => number_format($preco, 2, ',', '.'),
                'originalPrice' => $produto['preco_original'] ? number_format((float)$produto['preco_original'], 2, ',', '.') : null,
                'discount' => $produto['desconto'] ?? 0,
                'parcelaTexto' => $parcelaTexto,
                'parcelasNumero' => $parcelasNumero,
                'parcelaValorFormatado' => $parcelaValorFormatado,
                'link' => $produto['link_compra'] ?: '#'
            ];
        }
        
        return $formatted;
    }
    
    // Função para filtrar produtos
    function filterProducts($products, $searchQuery) {
        if (!is_array($products)) {
            return [];
        }
        if (empty(trim($searchQuery))) {
            return $products;
        }
        $query = strtolower($searchQuery);
        $filtered = [];
        foreach ($products as $product) {
            if (isset($product['title']) && strpos(strtolower($product['title']), $query) !== false) {
                $filtered[] = $product;
            }
        }
        return $filtered;
    }
    
    $searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Buscar produtos do banco
    $bestSellers = getProdutosPorCategoria($pdo, null, true);
    $bestSellers = filterProducts($bestSellers, $searchQuery);

    $blocosCategoria = [];
    foreach ($navItemsArray as $navItem) {
        $slugSec = ltrim($navItem['href'], '#');
        if ($slugSec === '') {
            continue;
        }
        $prods = getProdutosPorCategoria($pdo, $slugSec);
        $prods = filterProducts($prods, $searchQuery);
        $blocosCategoria[] = [
            'slug' => $slugSec,
            'titulo' => $navItem['label'],
            'produtos' => $prods,
        ];
    }

    $hasCatResults = false;
    foreach ($blocosCategoria as $b) {
        if (count($b['produtos']) > 0) {
            $hasCatResults = true;
            break;
        }
    }
    $hasResults = count($bestSellers) > 0 || $hasCatResults;
                  
} catch (Exception $e) {
    die('ERRO: ' . $e->getMessage());
}
// Converter hex para HSL (formato: "H S% L%")
$temaHsl = '16 100% 60%';
if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $temaCor, $m)) {
    $r = hexdec($m[1]) / 255; $g = hexdec($m[2]) / 255; $b = hexdec($m[3]) / 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
    if ($max == $min) { $h = $s = 0; } else {
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        switch ($max) {
            case $r: $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6; break;
            case $g: $h = (($b - $r) / $d + 2) / 6; break;
            default: $h = (($r - $g) / $d + 4) / 6; break;
        }
    }
    $temaHsl = round($h * 360) . ' ' . round($s * 100) . '% ' . round($l * 100) . '%';
}
$faviconUrl = $favicon ? $favicon : 'favicon.png';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconUrl); ?>" />
    <title><?php echo htmlspecialchars($siteNomeMarca); ?> - As Melhores Ofertas</title>
    <meta name="description" content="As melhores ofertas da internet em um só lugar" />
    <meta name="author" content="<?php echo htmlspecialchars($siteNomeMarca); ?>" />
    
    <meta property="og:title" content="<?php echo htmlspecialchars($siteNomeMarca); ?> - As Melhores Ofertas" />
    <meta property="og:description" content="As melhores ofertas da internet em um só lugar" />
    <meta property="og:type" content="website" />
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --background: 0 0% 96%;
            --foreground: 230 25% 12%;
            --card: 0 0% 100%;
            --card-foreground: 230 25% 12%;
            --primary: <?php echo $temaHsl; ?>;
            --primary-foreground: 0 0% 100%;
            --secondary: 230 50% 15%;
            --secondary-foreground: 0 0% 100%;
            --muted: 220 14% 90%;
            --muted-foreground: 220 10% 45%;
            --accent: <?php echo $temaHsl; ?>;
            --accent-foreground: 0 0% 100%;
            --destructive: 0 84% 60%;
            --destructive-foreground: 0 0% 100%;
            --border: 220 13% 88%;
            --input: 220 13% 88%;
            --ring: <?php echo $temaHsl; ?>;
            --header-bg: 240 30% 10%;
            --badge-discount: 0 80% 50%;
            --badge-offer: <?php echo $temaHsl; ?>;
            --price-color: 145 60% 35%;
        }

        * {
            border-color: hsl(var(--border));
        }

        body {
            background-color: hsl(var(--background));
            color: hsl(var(--foreground));
            font-family: 'Inter', sans-serif;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.2s;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .nav-link:hover {
            color: white;
        }

        .product-card {
            background-color: hsl(var(--card));
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: box-shadow 0.3s;
            overflow: hidden;
        }

        .product-card:hover {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .badge-discount {
            background-color: hsl(var(--badge-discount));
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }

        .badge-offer {
            background-color: hsl(var(--badge-offer));
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.75rem;
            border-radius: 0.25rem;
        }

        .btn-buy {
            background-color: hsl(var(--primary));
            color: hsl(var(--primary-foreground));
            font-weight: 600;
            padding: 0.5rem 1.5rem;
            border-radius: 0.375rem;
            transition: all 0.2s;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-buy:hover {
            filter: brightness(1.1);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: hsl(var(--foreground));
            border-left: 4px solid hsl(var(--primary));
            padding-left: 1rem;
        }

        .price-tag {
            color: hsl(var(--price-color));
            font-weight: 700;
            font-size: 1.25rem;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fade-in 0.4s ease-out;
        }

        .slide-enter {
            opacity: 1;
            transform: translateX(0);
        }

        .slide-exit {
            opacity: 0;
        }

        .slide-exit-left {
            transform: translateX(-100%);
        }

        .slide-exit-right {
            transform: translateX(100%);
        }

        .banner-slide {
            transition: all 0.7s ease-in-out;
        }
    </style>
</head>
<body class="min-h-screen bg-background">
    <!-- Header -->
    <header class="bg-[hsl(var(--header-bg))] sticky top-0 z-50 shadow-lg">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="#" class="flex min-w-0 max-w-[min(100%,14rem)] sm:max-w-none items-center gap-2 shrink-0">
                    <?php if ($showLogoImg): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($siteNomeMarca); ?>" class="h-[clamp(1.75rem,5vw,2.5rem)] w-auto max-w-[min(42vw,10rem)] sm:max-w-[11rem] object-contain object-left shrink-0">
                    <?php endif; ?>
                    <?php if ($showIconFallback): ?>
                    <svg class="h-[clamp(1.75rem,5vw,2.5rem)] w-[clamp(1.75rem,5vw,2.5rem)] shrink-0 text-[hsl(var(--primary))]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    <?php endif; ?>
                    <?php if ($showMarcaTexto): ?>
                    <span class="min-w-0 truncate font-bold text-[hsl(var(--primary))] text-[clamp(1rem,4.2vw,1.5rem)] leading-tight"><?php echo htmlspecialchars($siteNomeMarca); ?></span>
                    <?php endif; ?>
                </a>

                <!-- Search Bar (Desktop) -->
                <div class="hidden md:flex flex-1 max-w-md mx-8">
                    <form method="GET" action="" class="relative w-full">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[hsl(var(--muted-foreground))]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input
                            type="text"
                            name="search"
                            placeholder="Buscar produtos..."
                            value="<?php echo htmlspecialchars($searchQuery); ?>"
                            class="pl-10 pr-10 bg-background border-[hsl(var(--input))] border rounded-md py-2 px-4 w-full focus:outline-none focus:ring-2 focus:ring-[hsl(var(--ring))]"
                            oninput="this.form.submit()"
                        />
                        <?php if ($searchQuery): ?>
                        <a href="?" class="absolute right-3 top-1/2 -translate-y-1/2 text-[hsl(var(--muted-foreground))] hover:text-foreground">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center gap-6">
                    <a href="#" class="nav-link">Início</a>
                    <?php foreach ($navItemsArray as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link"><?php echo htmlspecialchars($item['label']); ?></a>
                    <?php endforeach; ?>
                    <?php if (!empty($whatsappGrupoUrl)): ?>
                    <a href="<?php echo htmlspecialchars($whatsappGrupoUrl); ?>" target="_blank" rel="noopener noreferrer" class="bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2 rounded-md transition-colors whitespace-nowrap"><?php echo htmlspecialchars($whatsappGrupoBotaoTexto); ?></a>
                    <?php endif; ?>
                </nav>

                <!-- Mobile Menu Button -->
                <button
                    id="mobileMenuBtn"
                    class="md:hidden text-white p-2"
                    onclick="toggleMobileMenu()"
                >
                    <svg id="menuIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <svg id="closeIcon" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Mobile Navigation -->
            <nav id="mobileNav" class="md:hidden hidden py-4 border-t border-white/30">
                <div class="mb-4">
                    <form method="GET" action="" class="relative w-full">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[hsl(var(--muted-foreground))]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input
                            type="text"
                            name="search"
                            placeholder="Buscar produtos..."
                            value="<?php echo htmlspecialchars($searchQuery); ?>"
                            class="pl-10 pr-10 bg-background border-[hsl(var(--input))] border rounded-md py-2 px-4 w-full focus:outline-none focus:ring-2 focus:ring-[hsl(var(--ring))]"
                            oninput="this.form.submit()"
                        />
                        <?php if ($searchQuery): ?>
                        <a href="?" class="absolute right-3 top-1/2 -translate-y-1/2 text-[hsl(var(--muted-foreground))] hover:text-foreground">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="flex flex-col gap-4">
                    <a href="#" class="nav-link py-2" onclick="closeMobileMenu()">Início</a>
                    <?php foreach ($navItemsArray as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link py-2" onclick="closeMobileMenu()"><?php echo htmlspecialchars($item['label']); ?></a>
                    <?php endforeach; ?>
                    <?php if (!empty($whatsappGrupoUrl)): ?>
                    <a href="<?php echo htmlspecialchars($whatsappGrupoUrl); ?>" target="_blank" rel="noopener noreferrer" class="bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2 rounded-md transition-colors text-center" onclick="closeMobileMenu()"><?php echo htmlspecialchars($whatsappGrupoBotaoTexto); ?></a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <!-- Hero Banner (apenas se não houver busca) -->
    <?php if (empty($searchQuery)): ?>
    <section class="relative overflow-hidden">
        <div class="relative h-[300px] md:h-[400px]">
            <?php if ($bannerType === 'default' && !empty($bannersDefault)): ?>
            <!-- Banners Padrão (JSON com textos e gradientes) -->
            <?php foreach ($bannersDefault as $index => $banner): ?>
            <div
                class="banner-slide absolute inset-0 <?php echo $index === 0 ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-full'; ?>"
                data-index="<?php echo $index; ?>"
            >
                <div class="h-full bg-gradient-to-r <?php echo $banner['bgGradient'] ?? 'from-[hsl(var(--primary))] via-orange-500 to-orange-600'; ?> flex items-center justify-center relative">
                    <!-- Geometric Pattern Overlay -->
                    <div class="absolute inset-0 opacity-10">
                        <div class="absolute top-10 left-10 w-32 h-32 border-4 border-white rotate-45"></div>
                        <div class="absolute bottom-10 right-10 w-48 h-48 border-4 border-white rotate-12"></div>
                        <div class="absolute top-1/2 left-1/4 w-24 h-24 border-4 border-white -rotate-12"></div>
                    </div>

                    <div class="text-center text-white z-10 px-4">
                        <h2 class="text-4xl md:text-6xl font-extrabold mb-2 drop-shadow-lg animate-fade-in">
                            <?php echo htmlspecialchars($banner['title'] ?? ''); ?>
                        </h2>
                        <p class="text-2xl md:text-4xl font-bold mb-4 drop-shadow-md">
                            <?php echo htmlspecialchars($banner['subtitle'] ?? ''); ?>
                        </p>
                        <p class="text-lg md:text-xl opacity-90">
                            <?php echo htmlspecialchars($banner['description'] ?? ''); ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php elseif ($bannerType === 'images' && !empty($banners)): ?>
            <!-- Banners de Imagens -->
            <?php foreach ($banners as $index => $bannerImage): ?>
            <div
                class="banner-slide absolute inset-0 <?php echo $index === 0 ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-full'; ?>"
                data-index="<?php echo $index; ?>"
            >
                <img src="<?php echo htmlspecialchars($bannerImage); ?>" 
                     alt="Banner <?php echo $index + 1; ?>" 
                     class="w-full h-full object-cover">
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Navigation Arrows -->
        <?php 
        $bannersCount = ($bannerType === 'default' ? count($bannersDefault) : count($banners));
        if ($bannersCount > 1): 
        ?>
        <button
            onclick="prevSlide()"
            class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/80 hover:bg-white text-gray-800 p-2 rounded-full transition-all"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </button>
        <button
            onclick="nextSlide()"
            class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/80 hover:bg-white text-gray-800 p-2 rounded-full transition-all"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
        <?php endif; ?>

        <!-- Dots Indicator -->
        <?php if ($bannersCount > 1): ?>
        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
            <?php 
            $bannersToLoop = ($bannerType === 'default' ? $bannersDefault : $banners);
            foreach ($bannersToLoop as $index => $banner): 
            ?>
            <button
                onclick="setSlide(<?php echo $index; ?>)"
                class="banner-dot w-3 h-3 rounded-full transition-all <?php echo $index === 0 ? 'bg-white w-8' : 'bg-white/50'; ?>"
                data-index="<?php echo $index; ?>"
            ></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <main>
        <?php if ($searchQuery && !$hasResults): ?>
        <div class="container mx-auto px-4 py-12 text-center">
            <p class="text-[hsl(var(--muted-foreground))] text-lg">
                Nenhum produto encontrado para "<?php echo htmlspecialchars($searchQuery); ?>"
            </p>
        </div>
        <?php endif; ?>

        <?php if (count($bestSellers) > 0): ?>
        <section class="py-10">
            <div class="container mx-auto px-4">
                <h2 class="section-title mb-8">Mais Vendidos</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    <?php foreach ($bestSellers as $product): ?>
                    <div class="product-card group">
                        <div class="relative aspect-square overflow-hidden bg-[hsl(var(--muted))] p-4">
                            <img
                                src="<?php echo htmlspecialchars($product['image']); ?>"
                                alt="<?php echo htmlspecialchars($product['title']); ?>"
                                class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-300"
                            />
                            <?php if ($product['discount'] > 0): ?>
                            <span class="badge-discount absolute top-3 left-3">
                                <?php echo $product['discount']; ?>% Off
                            </span>
                            <?php endif; ?>
                            <span class="badge-offer absolute top-3 right-3">Oferta</span>
                        </div>
                        <div class="p-4">
                            <h3 class="text-[hsl(var(--card-foreground))] font-medium text-sm line-clamp-2 mb-3 min-h-[40px]">
                                <?php echo htmlspecialchars($product['title']); ?>
                            </h3>
                            <div class="mb-4">
                                <?php if (isset($product['originalPrice'])): ?>
                                <span class="text-[hsl(var(--muted-foreground))] text-sm line-through block">
                                    R$ <?php echo htmlspecialchars($product['originalPrice']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($product['parcelaTexto'])): ?>
                                <div class="text-green-600 font-bold text-lg leading-tight"><?php echo htmlspecialchars($product['parcelaTexto']); ?></div>
                                <div class="text-sm text-[hsl(var(--foreground))] mt-0.5">ou R$ <?php echo htmlspecialchars($product['price']); ?> avista</div>
                                <?php else: ?>
                                <span class="price-tag">R$ <?php echo htmlspecialchars($product['price']); ?></span>
                                <?php endif; ?>
                            </div>
                            <a
                                href="<?php echo htmlspecialchars($product['link']); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn-buy w-full"
                            >
                                Comprar
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php foreach ($blocosCategoria as $bloco): ?>
        <?php if (count($bloco['produtos']) > 0): ?>
        <section id="<?php echo htmlspecialchars($bloco['slug']); ?>" class="py-10">
            <div class="container mx-auto px-4">
                <h2 class="section-title mb-8"><?php echo htmlspecialchars($bloco['titulo']); ?></h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    <?php foreach ($bloco['produtos'] as $product): ?>
                    <div class="product-card group">
                        <div class="relative aspect-square overflow-hidden bg-[hsl(var(--muted))] p-4">
                            <img
                                src="<?php echo htmlspecialchars($product['image']); ?>"
                                alt="<?php echo htmlspecialchars($product['title']); ?>"
                                class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-300"
                            />
                            <?php if ($product['discount'] > 0): ?>
                            <span class="badge-discount absolute top-3 left-3">
                                <?php echo $product['discount']; ?>% Off
                            </span>
                            <?php endif; ?>
                            <span class="badge-offer absolute top-3 right-3">Oferta</span>
                        </div>
                        <div class="p-4">
                            <h3 class="text-[hsl(var(--card-foreground))] font-medium text-sm line-clamp-2 mb-3 min-h-[40px]">
                                <?php echo htmlspecialchars($product['title']); ?>
                            </h3>
                            <div class="mb-4">
                                <?php if (isset($product['originalPrice'])): ?>
                                <span class="text-[hsl(var(--muted-foreground))] text-sm line-through block">
                                    R$ <?php echo htmlspecialchars($product['originalPrice']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($product['parcelaTexto'])): ?>
                                <div class="text-green-600 font-bold text-lg leading-tight"><?php echo htmlspecialchars($product['parcelaTexto']); ?></div>
                                <div class="text-sm text-[hsl(var(--foreground))] mt-0.5">ou R$ <?php echo htmlspecialchars($product['price']); ?> avista</div>
                                <?php else: ?>
                                <span class="price-tag">R$ <?php echo htmlspecialchars($product['price']); ?></span>
                                <?php endif; ?>
                            </div>
                            <a
                                href="<?php echo htmlspecialchars($product['link']); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn-buy w-full"
                            >
                                Comprar
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-[hsl(var(--header-bg))] text-white py-12">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <!-- Logo & About -->
                <div class="md:col-span-2">
                    <a href="#" class="flex min-w-0 items-center gap-2 mb-4">
                        <?php if ($showLogoImg): ?>
                        <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($siteNomeMarca); ?>" class="h-[clamp(1.75rem,5vw,2.5rem)] w-auto max-w-[min(70vw,12rem)] object-contain object-left shrink-0">
                        <?php endif; ?>
                        <?php if ($showIconFallback): ?>
                        <svg class="h-[clamp(1.75rem,5vw,2.5rem)] w-[clamp(1.75rem,5vw,2.5rem)] shrink-0 text-[hsl(var(--primary))]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                        <?php endif; ?>
                        <?php if ($showMarcaTexto): ?>
                        <span class="min-w-0 truncate font-bold text-[hsl(var(--primary))] text-[clamp(1.125rem,3.5vw,1.5rem)] leading-tight"><?php echo htmlspecialchars($siteNomeMarca); ?></span>
                        <?php endif; ?>
                    </a>
                    <p class="text-white/70 text-sm leading-relaxed">
                        As melhores ofertas da internet em um só lugar. Encontre produtos com os melhores
                        preços e descontos exclusivos todos os dias.
                    </p>
                </div>

                <!-- Categories -->
                <div>
                    <h4 class="font-bold text-[hsl(var(--primary))] mb-4">Categorias</h4>
                    <ul class="space-y-2 text-sm text-white/70">
                        <?php foreach ($navItemsArray as $item): ?>
                        <li><a href="<?php echo htmlspecialchars($item['href']); ?>" class="hover:text-[hsl(var(--primary))] transition-colors"><?php echo htmlspecialchars($item['label']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4 class="font-bold text-[hsl(var(--primary))] mb-4">Contato</h4>
                    <ul class="space-y-3 text-sm text-white/70">
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <?php echo htmlspecialchars($footerEmail); ?>
                        </li>
                    </ul>
                    <div class="flex gap-4 mt-4">
                        <a href="<?php echo htmlspecialchars($footerInstagram); ?>" target="_blank" rel="noopener noreferrer" class="text-white/70 hover:text-[hsl(var(--primary))] transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"></path>
                            </svg>
                        </a>
                        <a href="<?php echo htmlspecialchars($footerFacebook); ?>" target="_blank" rel="noopener noreferrer" class="text-white/70 hover:text-[hsl(var(--primary))] transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>

            <div class="border-t border-white/30 mt-8 pt-8 text-center text-sm text-white/50">
                <p>© 2026 <?php echo htmlspecialchars($siteNomeMarca); ?>. Todos os direitos reservados.</p>
                <p class="mt-2">
                    Este site contém links de afiliados. Ao comprar através desses links, você não paga
                    nada a mais e ainda nos ajuda a manter o site.
                </p>
            </div>
        </div>
    </footer>

    <script>
        // Menu Mobile
        function toggleMobileMenu() {
            const mobileNav = document.getElementById('mobileNav');
            const menuIcon = document.getElementById('menuIcon');
            const closeIcon = document.getElementById('closeIcon');
            
            mobileNav.classList.toggle('hidden');
            menuIcon.classList.toggle('hidden');
            closeIcon.classList.toggle('hidden');
        }

        function closeMobileMenu() {
            const mobileNav = document.getElementById('mobileNav');
            const menuIcon = document.getElementById('menuIcon');
            const closeIcon = document.getElementById('closeIcon');
            
            mobileNav.classList.add('hidden');
            menuIcon.classList.remove('hidden');
            closeIcon.classList.add('hidden');
        }

        // Banner Carousel
        let currentSlide = 0;
        const slides = document.querySelectorAll('.banner-slide');
        const dots = document.querySelectorAll('.banner-dot');
        const totalSlides = slides.length;

        function showSlide(index) {
            // Remove active classes
            slides.forEach((slide, i) => {
                if (i === index) {
                    slide.classList.remove('opacity-0', 'translate-x-full', '-translate-x-full');
                    slide.classList.add('opacity-100', 'translate-x-0');
                } else if (i < index) {
                    slide.classList.remove('opacity-100', 'translate-x-0', 'translate-x-full');
                    slide.classList.add('opacity-0', '-translate-x-full');
                } else {
                    slide.classList.remove('opacity-100', 'translate-x-0', '-translate-x-full');
                    slide.classList.add('opacity-0', 'translate-x-full');
                }
            });

            // Update dots
            dots.forEach((dot, i) => {
                if (i === index) {
                    dot.classList.remove('bg-white/50', 'w-3');
                    dot.classList.add('bg-white', 'w-8');
                } else {
                    dot.classList.remove('bg-white', 'w-8');
                    dot.classList.add('bg-white/50', 'w-3');
                }
            });
        }

        function nextSlide() {
            currentSlide = (currentSlide + 1) % totalSlides;
            showSlide(currentSlide);
        }

        function prevSlide() {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            showSlide(currentSlide);
        }

        function setSlide(index) {
            currentSlide = index;
            showSlide(currentSlide);
        }

        // Auto-play banner (only if banner exists)
        if (slides.length > 0) {
            setInterval(nextSlide, 5000);
        }
    </script>
    
    <?php if ($popupAtivo && !empty($popupImagem)): ?>
    <!-- Popup -->
    <div id="popup-overlay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" style="display: flex;">
        <div class="relative max-w-lg mx-4 bg-white rounded-xl shadow-2xl overflow-hidden">
            <button type="button" id="popup-close" class="absolute top-3 right-3 z-10 w-10 h-10 flex items-center justify-center rounded-full bg-black/40 hover:bg-black/60 text-white transition-colors" aria-label="Fechar">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <?php if (!empty($whatsappGrupoUrl)): ?>
            <a href="<?php echo htmlspecialchars($whatsappGrupoUrl); ?>" target="_blank" rel="noopener" id="popup-link" class="block">
            <?php endif; ?>
                <img src="/<?php echo htmlspecialchars($popupImagem); ?>" alt="Entre no grupo" class="w-full h-auto max-h-[80vh] object-contain">
            <?php if (!empty($whatsappGrupoUrl)): ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function() {
        var overlay = document.getElementById('popup-overlay');
        var closeBtn = document.getElementById('popup-close');
        if (overlay && closeBtn) {
            closeBtn.onclick = function() { overlay.style.display = 'none'; };
            overlay.onclick = function(e) { if (e.target === overlay) overlay.style.display = 'none'; };
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
