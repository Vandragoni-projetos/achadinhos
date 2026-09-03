<?php
/**
 * Automação AliExpress: API Affiliate → filtrar/randomizar → OpenAI (copy) → Evolution (WhatsApp) e site.
 * Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_ALIEXPRESS_LOADED')) {
    define('AUTOMACAO_ALIEXPRESS_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

/**
 * Encurta URL usando o próprio site: grava em short_urls e retorna baseUrl/r.php?c=CODE.
 * Requer migration add_short_urls.sql e r.php na raiz. Retorna URL original se falhar.
 */
function encurtarLinkNossoSite($url, $baseUrl) {
    $url = trim($url);
    $baseUrl = rtrim(trim($baseUrl), '/');
    if ($url === '' || strpos($url, 'http') !== 0 || $baseUrl === '') {
        return $url;
    }
    static $chars = 'abcdefghijkmnopqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO short_urls (code, url_destino) VALUES (?, ?)");
        $stmt->execute([$code, $url]);
    } catch (Exception $e) {
        return $url;
    }
    return $baseUrl . '/r.php?c=' . $code;
}

/**
 * Encurta URL do AliExpress via TinyURL (fallback quando não usa encurtador próprio).
 * Retorna a URL original se o encurtamento falhar.
 */
function encurtarLinkAliExpress($url) {
    $url = trim($url);
    if ($url === '' || strpos($url, 'http') !== 0) {
        return $url;
    }
    $api = 'https://tinyurl.com/api-create.php?url=' . rawurlencode($url);
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $short = trim((string) curl_exec($ch));
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $short !== '' && strpos($short, 'http') === 0) {
        return $short;
    }
    return $url;
}

/**
 * URL pública da raiz do projeto (onde r.php está acessível), para gerar links curtos próprios.
 * Ordem: inferência do pedido atual / cron_public_base_url (sitePublicRootUrl), depois configs salvas.
 */
function aliexpressResolverBaseUrlEncurtador(): string {
    if (function_exists('sitePublicRootUrl')) {
        $b = rtrim(trim((string) sitePublicRootUrl()), '/');
        if ($b !== '') {
            return $b;
        }
    }
    foreach (['cron_public_base_url', 'site_url', 'aliexpress_site_url'] as $k) {
        $u = rtrim(trim((string) getConfig($k, '')), '/');
        if ($u !== '' && preg_match('#^https?://#i', $u)) {
            return $u;
        }
    }

    return '';
}

/**
 * Monta a mensagem de promoção AliExpress já na formatação WhatsApp (* negrito, _ itálico, ~ riscado).
 * Não depende da IA: garante que título, nome, preços e CTA saiam sempre formatados.
 */
function montarMensagemAliExpressWhatsApp($nome, $precoTexto, $linkAfiliado) {
    $titulo = '*PROMO ALIEXPRESS! 🔥💰*';
    $nomeItalico = '_' . $nome . '_';
    $precoLinhas = '';
    if (preg_match('/De R\$\s*([\d.,]+)\s*por\s*R\$\s*([\d.,]+)\s*,\s*(\d+)%/', $precoTexto, $m)) {
        $precoLinhas = "~R$ " . $m[1] . "~\n*R$ " . $m[2] . "*\n*" . $m[3] . "% OFF*";
    } elseif (preg_match('/R\$\s*([\d.,]+)/', $precoTexto, $m)) {
        $precoLinhas = '*R$ ' . $m[1] . '*';
    } else {
        $precoLinhas = $precoTexto;
    }
    $beneficios = "✅ Qualidade e preço baixo\n✅ Entrega internacional\n🌍 Frete para o Brasil";
    $cta = '*👉 Clique no link e garanta o seu!*';
    $fechamento = '*Oferta exclusiva do grupo!*';
    $msg = $titulo . "\n" . $nomeItalico . "\n" . $precoLinhas . "\n" . $beneficios . "\n" . $cta . "\n" . $fechamento;
    if ($linkAfiliado !== '') {
        $msg .= "\n\n🔗 " . $linkAfiliado;
    }
    return $msg;
}

// AliExpress Open Platform (openservice.aliexpress.com) - App Key/Secret são válidos aqui.
// Taobao (eco.taobao.com) retorna "Invalid app Key" para apps do AliExpress.
const ALIEXPRESS_API_URL = 'https://api-sg.aliexpress.com/sync';

/**
 * Gera timestamp no formato TOP: yyyy-MM-dd HH:mm:ss, GMT+8 (Asia/Shanghai).
 * Documentação: open.alitrip.com - API Invocation, parâmetro timestamp.
 */
function aliexpressTimestamp() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Assinatura MD5 conforme TOP (open.alitrip.com): ordenar parâmetros por nome (ASCII),
 * concatenar key+value (sem separador), depois sign = UPPER(hex(md5(utf8: app_secret + concat + app_secret))).
 * sign_method pode ser "md5" ou "hmac"; a api-sg aceita o mesmo algoritmo TOP.
 */
function aliexpressSign(array $params, $appSecret, $signMethod = 'md5') {
    ksort($params);
    $concat = '';
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $v === '' || $v === null) {
            continue;
        }
        $concat .= $k . $v;
    }
    if ($signMethod === 'hmac') {
        return strtoupper(hash_hmac('md5', $concat, $appSecret));
    }
    $toSign = $appSecret . $concat . $appSecret;
    return strtoupper(md5($toSign));
}

/**
 * Lista categorias disponíveis na API de afiliados (aliexpress.affiliate.category.get).
 * @return list<array{id:int,name:string,parent_id:int}>
 */
function aliexpressAffiliateCategoryGet($appKey, $appSecret, &$err = '') {
    $err = '';
    $timestamp = aliexpressTimestamp();
    $signMethod = 'md5';
    $params = [
        'method'      => 'aliexpress.affiliate.category.get',
        'app_key'     => $appKey,
        'sign_method' => $signMethod,
        'timestamp'   => $timestamp,
        'format'      => 'json',
        'v'           => '2.0',
        'simplify'    => 'true',
    ];
    $params['sign'] = aliexpressSign($params, $appSecret, $signMethod);
    $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init(ALIEXPRESS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $err = 'AliExpress API HTTP ' . $httpCode;
        return [];
    }
    $json = @json_decode($res, true);
    if (!$json) {
        $err = 'Resposta da API não é JSON válido';
        return [];
    }
    if (isset($json['error_response'])) {
        $sub = $json['error_response'];
        $err = $sub['sub_msg'] ?? $sub['msg'] ?? 'Erro na API AliExpress';
        return [];
    }
    $resp = $json['aliexpress_affiliate_category_get_response'] ?? null;
    if ($resp !== null) {
        $respResult = $resp['resp_result'] ?? $resp;
    } elseif (isset($json['resp_result'])) {
        $respResult = $json['resp_result'];
    } else {
        $keys = array_keys($json);
        $err = 'Resposta sem categorias. Chaves: ' . (count($keys) ? implode(', ', $keys) : '(vazio)');
        return [];
    }
    if ((int) ($respResult['resp_code'] ?? 0) !== 200) {
        $err = $respResult['resp_msg'] ?? 'API retornou erro';
        return [];
    }
    $result = $respResult['result'] ?? [];
    $raw = $result['categories']['category'] ?? $result['categories'] ?? [];
    if (!is_array($raw)) {
        $raw = $raw ? [$raw] : [];
    }
    $out = [];
    foreach ($raw as $c) {
        if (!is_array($c)) {
            continue;
        }
        $id = (int) ($c['category_id'] ?? $c['categoryId'] ?? 0);
        $name = trim((string) ($c['category_name'] ?? $c['categoryName'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $out[] = [
            'id'        => $id,
            'name'      => $name,
            'parent_id' => (int) ($c['parent_category_id'] ?? $c['parentCategoryId'] ?? 0),
        ];
    }
    usort($out, static function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * Chama a API AliExpress Affiliate (aliexpress.affiliate.product.query).
 * $categoryIds: um ou vários IDs separados por vírgula; "0", vazio ou "all" = sem filtro de categoria
 * (usa keyword aleatória e página aleatória para variar os produtos).
 * Retorna array de produtos normalizados ou null em caso de erro (e $err preenchido).
 * Só inclui itens com link de afiliado (promotion_link / code_promotionurl).
 */
function aliexpressAffiliateProductQuery($appKey, $appSecret, $categoryIds, $pageNo = 1, $pageSize = 20, &$err = '') {
    $err = '';
    $raw = trim((string) $categoryIds);
    $useAllCategories = ($raw === '' || $raw === '0' || strcasecmp($raw, 'all') === 0);
    if (!$useAllCategories) {
        $categoryIds = preg_replace('/[^\d,]/', '', $raw);
        $categoryIds = trim($categoryIds, ',');
        if ($categoryIds === '') {
            $err = 'Categoria de produtos inválida (API AliExpress).';
            return null;
        }
    }
    $timestamp = aliexpressTimestamp();
    $signMethod = 'md5';
    $pageUse = $useAllCategories ? random_int(1, 10) : max(1, (int) $pageNo);
    $params = [
        'method'             => 'aliexpress.affiliate.product.query',
        'app_key'            => $appKey,
        'sign_method'        => $signMethod,
        'timestamp'          => $timestamp,
        'format'             => 'json',
        'v'                  => '2.0',
        'simplify'           => 'true',
        'page_no'            => (string) $pageUse,
        'page_size'          => (string) $pageSize,
        'target_currency'    => 'BRL',
        'target_language'    => 'PT',
        'ship_to_country'    => 'BR',
    ];
    if ($useAllCategories) {
        $kwPool = [
            'deals', 'sale', 'best seller', 'hot', 'new', 'gift', 'popular', 'promotion', 'discount',
            'fashion', 'electronics', 'home', 'sport', 'beauty', 'toys', 'phone', 'kitchen', 'wireless', 'car',
        ];
        $params['keywords'] = $kwPool[random_int(0, count($kwPool) - 1)];
    } else {
        $params['category_ids'] = $categoryIds;
    }
    $params['sign'] = aliexpressSign($params, $appSecret, $signMethod);

    // TOP: enviar todos os parâmetros no body (POST), conforme documentação e exemplos que funcionam.
    $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init(ALIEXPRESS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        $err = 'AliExpress API HTTP ' . $httpCode;
        return null;
    }

    $json = @json_decode($res, true);
    if (!$json) {
        $err = 'Resposta da API não é JSON válido';
        return null;
    }

    if (isset($json['error_response'])) {
        $sub = $json['error_response'];
        $err = $sub['sub_msg'] ?? $sub['msg'] ?? 'Erro na API AliExpress';
        return null;
    }

    // Dois formatos: TOP (eco.taobao) usa aliexpress_affiliate_product_query_response; api-sg/sync usa resp_result na raiz
    $resp = $json['aliexpress_affiliate_product_query_response'] ?? null;
    if ($resp !== null) {
        $respResult = $resp['resp_result'] ?? $resp;
    } elseif (isset($json['resp_result'])) {
        $respResult = $json['resp_result'];
    } else {
        $keys = array_keys($json);
        $err = 'Resposta sem resultado de produtos. Chaves recebidas: ' . (count($keys) ? implode(', ', $keys) : '(vazio)');
        return null;
    }
    if ((int)($respResult['resp_code'] ?? 0) !== 200) {
        $err = $respResult['resp_msg'] ?? 'API retornou erro';
        return null;
    }
    $result = $respResult['result'] ?? [];
    $products = $result['products']['product'] ?? $result['products'] ?? [];
    if (!is_array($products)) {
        $products = $products ? [$products] : [];
    }

    $list = [];
    foreach ($products as $p) {
        if (!is_array($p)) {
            continue;
        }
        $nome = trim((string) ($p['product_title'] ?? $p['productTitle'] ?? ''));
        $precoStr = $p['target_sale_price'] ?? $p['sale_price'] ?? $p['target_app_sale_price']
            ?? $p['targetSalePrice'] ?? $p['salePrice'] ?? $p['targetAppSalePrice'] ?? '';
        if ($precoStr === '' && isset($p['sale_price'])) {
            $precoStr = (string) $p['sale_price'];
        }
        $preco = null;
        if (is_numeric($precoStr)) {
            $preco = (float) $precoStr;
        } elseif ($precoStr !== '') {
            $preco = (float) preg_replace('/[^\d.,]/', '', str_replace(',', '.', $precoStr));
        }
        $precoOrigStr = $p['target_original_price'] ?? $p['original_price'] ?? $p['targetOriginalPrice'] ?? $p['originalPrice'] ?? '';
        $precoOriginal = $precoOrigStr !== '' && is_numeric(preg_replace('/[^\d.,]/', '', $precoOrigStr))
            ? (float) preg_replace('/[^\d.,]/', '', str_replace(',', '.', $precoOrigStr)) : 0.0;
        $img = trim((string) ($p['product_main_image_url'] ?? $p['productMainImageUrl'] ?? $p['product_image_url'] ?? $p['productImageUrl'] ?? ''));
        if ($img === '' && !empty($p['product_small_image_urls']['string'][0])) {
            $img = trim((string) $p['product_small_image_urls']['string'][0]);
        } elseif ($img === '' && !empty($p['productSmallImageUrls'][0])) {
            $img = trim((string) $p['productSmallImageUrls'][0]);
        }
        if ($img !== '' && function_exists('achadinhosNormalizarUrlImagemProduto')) {
            $img = achadinhosNormalizarUrlImagemProduto($img);
        }
        $url = trim((string) ($p['code_promotionurl'] ?? $p['codePromotionUrl'] ?? ''));
        if ($url === '') {
            $url = trim((string) ($p['promotion_link'] ?? $p['promotionLink'] ?? ''));
        }
        if ($url !== '' && strpos($url, 'http') !== 0) {
            $url = 'https:' . ltrim($url, ':');
        }
        $discount = isset($p['discount']) ? (int) preg_replace('/\D/', '', (string) $p['discount']) : null;

        if ($nome !== '' && $preco !== null && $preco > 0 && $img !== '' && $url !== '') {
            $list[] = [
                'nome'           => $nome,
                'preco_atual'    => $preco,
                'preco_anterior' => $precoOriginal > 0 ? $precoOriginal : null,
                'imagem'         => $img,
                'url'            => $url,
                'desconto'       => $discount,
            ];
        }
    }
    return $list;
}

/**
 * @return array{nome:string,preco:float,precoAnt:float,precoTexto:string,img:string,url:string,desconto:int,mensagem:string,imgB64:?string}
 */
function aliexpressMontarEnvioPartirProduto(array $p, string $baseEncurtador): array {
    $nome = $p['nome'];
    $preco = $p['preco_atual'];
    $precoAnt = (float) ($p['preco_anterior'] ?? 0);
    $img = function_exists('achadinhosNormalizarUrlImagemProduto')
        ? achadinhosNormalizarUrlImagemProduto((string) ($p['imagem'] ?? ''))
        : trim((string) ($p['imagem'] ?? ''));
    $url = $p['url'];
    $desconto = $p['desconto'] ?? null;
    if (($desconto === null || (int) $desconto === 0) && $precoAnt > 0 && $preco > 0 && $precoAnt > $preco && function_exists('calcularDesconto')) {
        $desconto = (int) round(calcularDesconto($precoAnt, $preco));
    }
    $desconto = $desconto !== null ? (int) $desconto : 0;
    $precoTexto = $precoAnt > 0
        ? ('De R$ ' . number_format($precoAnt, 2, ',', '.') . ' por R$ ' . number_format($preco, 2, ',', '.'))
        : ('R$ ' . number_format($preco, 2, ',', '.'));
    if ($desconto > 0) {
        $precoTexto .= ', ' . $desconto . '%';
    }
    $baseEncurtador = trim($baseEncurtador);
    $linkParaMensagem = $url;
    if ($baseEncurtador !== '' && function_exists('encurtarLinkNossoSite')) {
        try {
            $linkCurto = encurtarLinkNossoSite($url, $baseEncurtador);
            if ($linkCurto !== $url && strpos($linkCurto, $baseEncurtador) !== false) {
                $linkParaMensagem = $linkCurto;
            }
        } catch (Throwable $e) {
            $linkParaMensagem = $url;
        }
    }
    $mensagem = montarMensagemAliExpressWhatsApp($nome, $precoTexto, $linkParaMensagem);
    $imgB64 = function_exists('baixarEConverterImagemBase64') ? baixarEConverterImagemBase64($img) : null;

    return [
        'nome' => $nome,
        'preco' => $preco,
        'precoAnt' => $precoAnt,
        'precoTexto' => $precoTexto,
        'img' => $img,
        'url' => $url,
        'desconto' => $desconto,
        'mensagem' => $mensagem,
        'imgB64' => $imgB64,
    ];
}

function runAutomacaoAliExpress($forcarExecucao = false, $apenasGrupoId = null) {
    $details = [];
    $errors = [];

    $schemaAe = __DIR__ . '/../core/db/SchemaHelper.php';
    if (is_file($schemaAe)) {
        require_once $schemaAe;
        if (function_exists('garantirColunaGruposWhatsappAliexpressCategoria')) {
            garantirColunaGruposWhatsappAliexpressCategoria();
        }
    }

    $ativa = $forcarExecucao || (getConfig('aliexpress_automacao_ativa', '0') === '1');
    $appKey = trim(getConfig('aliexpress_app_key', ''));
    $appSecret = trim(getConfig('aliexpress_app_secret', ''));

    $openaiKey = trim(getConfig('openai_api_key', ''));
    if ($openaiKey === '') {
        $openaiKey = trim(getConfig('aliexpress_openai_api_key', ''));
    }

    $evUrl = rtrim(getConfig('aliexpress_evolution_url', ''), '/');
    $evInst = getConfig('aliexpress_evolution_instancia', '');
    $evKey = getConfig('aliexpress_evolution_apikey', '');
    $evGrupos = getConfig('aliexpress_evolution_grupos', '');
    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));
    $idsTeste = ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) ? [(int) $apenasGrupoId] : null;
    $gruposFixos = function_exists('getGruposFixosPorLoja')
        ? getGruposFixosPorLoja('aliexpress', $idsTeste)
        : [];

    $delay = max(1, min(120, (int) getConfig('aliexpress_delay_entre_envios', '10')));
    $publicarSite = getConfig('aliexpress_site_publicar', '1') === '1';

    $gruposDoBanco = $gruposFixos;
    $aliexpressGruposIds = getConfig('aliexpress_grupos_ids', '');
    if ($gruposDoBanco === [] && trim($aliexpressGruposIds) === '' && $grupos !== []) {
        foreach ($grupos as $g) {
            $gruposDoBanco[] = [
                'id' => 0,
                'nome' => 'Grupo Padrão',
                'grupo_id' => $g,
                'evolution_conta_id' => 0,
                'evolution' => [
                    'url_base' => $evUrl,
                    'instancia' => $evInst,
                    'api_key' => $evKey,
                ],
                'intervalo_minutos' => null,
                'aliexpress_affiliate_category_id' => 0,
                'post_hora_inicio' => null,
                'post_hora_fim' => null,
            ];
        }
    }

    $dispatchesTree = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
        'whatsapp' => [],
        'telegram' => [],
    ];
    $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTree['whatsapp']);
    $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);

    $temEvolution = $gruposDoBanco !== [] || $useWaDispatch || ($evUrl !== '' && $evInst !== '' && $evKey !== '' && $grupos !== []);

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação AliExpress desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if ($appKey === '' || $appSecret === '') {
        $errors[] = 'AliExpress: preencha App Key e App Secret na página AliExpress.';
    }
    if ($openaiKey === '') {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    if (!$temEvolution) {
        $errors[] = 'AliExpress: cadastre grupos em Grupos (loja AliExpress) ou configure envio por dispatch.';
    }
    if ($errors !== []) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios.', 'details' => $details, 'errors' => $errors];
    }

    $baseEncurtador = aliexpressResolverBaseUrlEncurtador();

    $grupoCatPorId = [];
    foreach ($gruposDoBanco as $gi) {
        $gid = (int) $gi['id'];
        if ($gid > 0) {
            $grupoCatPorId[$gid] = (int) ($gi['aliexpress_affiliate_category_id'] ?? 0);
        }
    }

    $resolverCategoriaAliexpressGrupoDb = static function (int $gDb) use (&$grupoCatPorId): int {
        if ($gDb <= 0) {
            return 0;
        }
        if (array_key_exists($gDb, $grupoCatPorId)) {
            return $grupoCatPorId[$gDb];
        }
        try {
            $pdo = getDB();
            $st = $pdo->prepare('SELECT COALESCE(aliexpress_affiliate_category_id, 0) AS c FROM grupos_whatsapp WHERE id = ? LIMIT 1');
            $st->execute([$gDb]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $c = $r ? (int) $r['c'] : 0;
            $grupoCatPorId[$gDb] = $c;

            return $c;
        } catch (Exception $e) {
            $grupoCatPorId[$gDb] = 0;

            return 0;
        }
    };

    $productCache = [];
    $nProdutosApiTotal = 0;

    $obterProdutoParaCategoria = function (int $catId, string &$errOut) use ($appKey, $appSecret, &$productCache, &$nProdutosApiTotal): ?array {
        $k = $catId === 0 ? '__all__' : (string) $catId;
        if (!array_key_exists($k, $productCache)) {
            $err = '';
            $list = aliexpressAffiliateProductQuery($appKey, $appSecret, $catId === 0 ? '0' : $k, 1, 50, $err);
            if ($list === null) {
                $productCache[$k] = ['__err__' => $err !== '' ? $err : 'Falha na API AliExpress.'];
            } else {
                $productCache[$k] = $list;
                $nProdutosApiTotal += count($list);
            }
        }
        if (isset($productCache[$k]['__err__'])) {
            $errOut = (string) $productCache[$k]['__err__'];
            return null;
        }
        $list = $productCache[$k];
        if ($list === []) {
            $errOut = $catId === 0
                ? 'Nenhum produto com link de afiliado (busca geral).'
                : 'Nenhum produto com link de afiliado para esta categoria.';
            return null;
        }
        shuffle($list);

        return $list[0];
    };

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];
    $processados = 0;
    $primeiroTelegram = null;

    $evoFallbackStatus = $gruposDoBanco !== [] ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

    $processarProdutoGrupo = function (array $p, array $grupoInfo) use (
        $baseEncurtador,
        $publicarSite,
        &$details,
        &$errosProduto,
        &$primeiroTelegram,
        &$enviados,
        $forcarExecucao,
        $delay
    ): void {
        $pl = aliexpressMontarEnvioPartirProduto($p, $baseEncurtador);
        $nome = $pl['nome'];
        $preco = $pl['preco'];
        $precoAnt = $pl['precoAnt'];
        $precoTexto = $pl['precoTexto'];
        $img = $pl['img'];
        $url = $pl['url'];
        $desconto = $pl['desconto'];
        $mensagem = $pl['mensagem'];
        $imgB64 = $pl['imgB64'];

        if ($img === '' || $imgB64 === null || $imgB64 === '') {
            $errosProduto[] = 'AliExpress ' . ($grupoInfo['nome'] ?? 'grupo') . ': produto sem foto válida (baixar imagem falhou).';
            return;
        }

        if ($publicarSite && function_exists('salvarProdutoNoSite')) {
            $errProd = '';
            $precoOrig = $precoAnt > 0 ? $precoAnt : null;
            $id = salvarProdutoNoSite($nome, $precoTexto, $url, $img, $errProd, $preco, $precoOrig, $desconto, 'aliexpress');
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
            } elseif ($errProd !== '') {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        $grupoId = $grupoInfo['grupo_id'];
        $grupoIdDb = (int) ($grupoInfo['id'] ?? 0);
        if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem')
            && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
            return;
        }
        if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio')
            && !grupoPodeReceberEnvio($grupoIdDb, 'aliexpress', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
            return;
        }
        $evo = $grupoInfo['evolution'];
        $err = '';
        $ok = enviarWhatsAppMensagem($evo, $grupoId, $mensagem, $imgB64 ?? '', $err);
        if ($ok) {
            $enviados++;
            if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                registrarEnvioGrupo($grupoIdDb, 'aliexpress');
            }
            if ($primeiroTelegram === null) {
                $primeiroTelegram = ['mensagem' => $mensagem, 'img' => $img, 'imgB64' => $imgB64];
            }
        } else {
            $errosProduto[] = 'WhatsApp ' . ($grupoInfo['nome'] ?? $grupoId) . ': ' . $err;
        }
    };

    if ($useWaDispatch) {
        if (!function_exists('dispatch_expandir_linhas_por_grupo_prioridade')) {
            $errosProduto[] = 'Dispatch WhatsApp indisponível.';
        } else {
            $destinos = dispatch_expandir_linhas_por_grupo_prioridade($dispatchesTree['whatsapp']);
            $nDest = count($destinos);
            $msgs = array_fill(0, $nDest, '');
            $imgsB64 = array_fill(0, $nDest, '');
            for ($i = 0; $i < $nDest; $i++) {
                $gjid = $destinos[$i]['grupo_id'];
                $gDb = function_exists('dispatch_grupo_whatsapp_id_por_jid') ? dispatch_grupo_whatsapp_id_por_jid($gjid) : 0;
                if ($gDb <= 0) {
                    $errosProduto[] = 'AliExpress (dispatch) grupo ' . $gjid . ': cadastre o grupo em Grupos (loja AliExpress).';
                    continue;
                }
                $cat = $resolverCategoriaAliexpressGrupoDb($gDb);
                $errP = '';
                $prod = $obterProdutoParaCategoria($cat, $errP);
                if ($prod === null) {
                    $errosProduto[] = 'AliExpress (dispatch) ' . $gjid . ': ' . $errP;
                    continue;
                }
                $pl = aliexpressMontarEnvioPartirProduto($prod, $baseEncurtador);
                if (trim((string) ($pl['img'] ?? '')) === '' || ($pl['imgB64'] ?? '') === null || ($pl['imgB64'] ?? '') === '') {
                    $errosProduto[] = 'AliExpress (dispatch) grupo ' . $gjid . ': produto sem foto válida.';
                    continue;
                }
                $msgs[$i] = $pl['mensagem'];
                $imgsB64[$i] = (string) $pl['imgB64'];
                if ($publicarSite && function_exists('salvarProdutoNoSite')) {
                    $errProd = '';
                    $precoOrig = $pl['precoAnt'] > 0 ? $pl['precoAnt'] : null;
                    $sid = salvarProdutoNoSite($pl['nome'], $pl['precoTexto'], $pl['url'], $pl['img'], $errProd, $pl['preco'], $precoOrig, $pl['desconto'], 'aliexpress');
                    if ($sid) {
                        $details['produtos_site'][] = ['id' => $sid, 'nome' => mb_substr($pl['nome'], 0, 50)];
                    } elseif ($errProd !== '') {
                        $errosProduto[] = 'Site: ' . $errProd;
                    }
                }
                if ($primeiroTelegram === null) {
                    $primeiroTelegram = [
                        'mensagem' => $pl['mensagem'],
                        'img' => $pl['img'],
                        'imgB64' => $pl['imgB64'] ?? null,
                    ];
                }
                $processados++;
            }
            dispatch_executar_whatsapp_destinos(
                $dispatchesTree['whatsapp'],
                'aliexpress',
                (int) $delay,
                '',
                static function ($idx, $total) use ($msgs) {
                    return $msgs[$idx] ?? '';
                },
                $errosProduto,
                $enviados,
                $evoFallbackStatus,
                null,
                $forcarExecucao,
                static function ($idx, $total) use ($imgsB64) {
                    return $imgsB64[$idx] ?? '';
                }
            );
        }
    } else {
        if ($gruposDoBanco === []) {
            $errors[] = 'AliExpress: cadastre pelo menos um grupo com loja AliExpress em Grupos.';
        } else {
            $nGr = count($gruposDoBanco);
            $ig = 0;
            foreach ($gruposDoBanco as $grupoInfo) {
                $cat = (int) ($grupoInfo['aliexpress_affiliate_category_id'] ?? 0);
                $errP = '';
                $prod = $obterProdutoParaCategoria($cat, $errP);
                if ($prod === null) {
                    $errosProduto[] = ($grupoInfo['nome'] ?? 'Grupo') . ': ' . $errP;
                    continue;
                }
                $processarProdutoGrupo($prod, $grupoInfo);
                $processados++;
                $ig++;
                if ($ig < $nGr) {
                    sleep((int) $delay);
                }
            }
        }
    }

    if ($primeiroTelegram !== null && function_exists('enviarTelegram')) {
        $m = $primeiroTelegram['mensagem'];
        $iu = $primeiroTelegram['img'] ?? null;
        $ib64 = $primeiroTelegram['imgB64'] ?? null;
        $tgB64 = ($ib64 !== null && $ib64 !== '') ? (string) $ib64 : null;
        if ($useTgDispatch) {
            dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $m, $iu !== '' ? $iu : null, $errosProduto, $tgB64);
        } else {
            enviarTelegramFluxoLoja('aliexpress', $m, $iu !== '' ? $iu : null, $errosProduto, $tgB64);
        }
    }

    if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta') && $primeiroTelegram !== null) {
        $fallback = $evoFallbackStatus ?? ($gruposDoBanco[0]['evolution'] ?? null);
        $evoStatus = getEvolutionParaStatus($fallback, 'aliexpress');
        if ($evoStatus) {
            $errSt = '';
            $iu = $primeiroTelegram['img'] ?? null;
            enviarWhatsAppStatusPorConta($evoStatus, $primeiroTelegram['mensagem'], $iu !== '' ? $iu : null, $errSt);
            if ($errSt !== '' && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                $errosProduto[] = 'Status: ' . $errSt;
            }
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_api'] = $nProdutosApiTotal;
    $details['produtos_validos'] = $processados;
    $details['produtos_processados'] = $processados;
    $details['mensagens_enviadas'] = $enviados;

    $nSite = count($details['produtos_site'] ?? []);
    if ($enviados > 0 || $nSite > 0) {
        $msg = 'Automação AliExpress concluída.';
        if ($enviados > 0) {
            $msg .= ' ' . $enviados . ' mensagem(ns) enviada(s).';
        }
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) publicados no site.';
        }

        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }

    if ($errors !== []) {
        return ['success' => false, 'message' => 'Automação executada com erros.', 'details' => $details, 'errors' => $errors];
    }

    return [
        'success' => true,
        'message' => 'Nenhum envio nesta execução (janela de horário, intervalo ou categorias).',
        'details' => $details,
        'errors' => $errors,
    ];
}
