<?php
/**
 * Automação Magalu (Minha Loja Magazine Voce): apenas scraping da sua loja → ofertas → copy com IA → Evolution (WhatsApp) e opcional site.
 * Sem Lomadee: só acessa a URL da sua loja (magazinevoce.com.br), extrai ofertas e usa o link do produto da Magalu.
 * Requer: automacao-ml.php (gerarCopyOpenAI, formatarMensagemWhatsApp, enviarWhatsAppEvolution, baixarEConverterImagemBase64, salvarProdutoNoSite).
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_MAGALU_LOJA_LOADED')) {
    define('AUTOMACAO_MAGALU_LOJA_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

/**
 * Retorna a chave canônica do link para comparação e gravação em magalu_links_enviados.
 * Mesmo produto com http/https, host em maiúsculas ou sufixos /es/xxx gera sempre a mesma chave.
 */
function linkCanonicoMagalu($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    $url = normalizarLinkMagazineVoce($url);
    $url = preg_replace('#[#?].*$#', '', $url);
    $url = rtrim($url, '/');
    $p = parse_url($url);
    if (!isset($p['host'])) return $url;
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'https';
    if ($scheme !== 'https' && $scheme !== 'http') $scheme = 'https';
    $host = strtolower($p['host']);
    $path = isset($p['path']) ? $p['path'] : '/';
    // Magazine Voce: /loja/produto-slug/p/ID/es/xxx — remover sufixos após /p/ID para reconhecer mesmo produto
    if (preg_match('#^(/.+?/p/[a-z0-9]+)#i', $path, $m)) {
        $path = rtrim($m[1], '/');
    }
    return $scheme . '://' . $host . $path;
}

/**
 * Remove slug duplicado na URL (ex.: /magazineinovapub/magazineinovapub/ ou /magazineinovapub/faixa/.../magazineinovapub/p/ -> único slug).
 */
function normalizarLinkMagazineVoce($url) {
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
    if ($path === '') {
        return $url;
    }
    $parts = explode('/', $path);
    $first = $parts[0] ?? '';
    $out = [];
    $slugJaInserido = false;
    foreach ($parts as $i => $seg) {
        if ($seg === $first && $first !== '') {
            if (!$slugJaInserido) {
                $out[] = $seg;
                $slugJaInserido = true;
            }
            continue;
        }
        $out[] = $seg;
    }
    $newPath = '/' . implode('/', $out);
    $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . ':' : '';
    $host = isset($parsed['host']) ? '//' . $parsed['host'] : '';
    return $scheme . $host . $newPath . $query . $fragment;
}

/**
 * Busca ofertas na página da loja Magazine Voce (scraping).
 * Retorna array de ['nome'=>string, 'preco'=>string, 'preco_num'=>float, 'imagem'=>string, 'link'=>string].
 */
function extrairOfertasMagazineVoce($storeUrl, &$err) {
    $err = '';
    $storeUrl = trim($storeUrl);
    if ($storeUrl === '' || strpos($storeUrl, 'magazinevoce.com.br') === false) {
        $err = 'URL da loja deve ser do magazinevoce.com.br';
        return [];
    }
    $baseUrl = preg_replace('#/[^/]*$#', '/', $storeUrl);
    if (substr($baseUrl, -1) !== '/') {
        $baseUrl .= '/';
    }

    $slug = '';
    if (preg_match('#magazinevoce\.com\.br/([^/?]+)#', $storeUrl, $sm)) {
        $slug = $sm[1];
    }
    $domainBase = (parse_url($storeUrl, PHP_URL_SCHEME) ?: 'https') . '://' . (parse_url($storeUrl, PHP_URL_HOST) ?: 'www.magazinevoce.com.br');

    $proxyUrl = trim((string) getConfig('magalu_scraper_api_key', ''));
    $usarProxy = $proxyUrl !== '';

    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
    ];
    $baseHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
    ];
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => 'gzip, deflate, br',
        CURLOPT_USERAGENT => $userAgents[0],
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, ['Referer: https://www.magazineluiza.com.br/']),
        CURLOPT_REFERER => 'https://www.magazineluiza.com.br/',
    ];

    // Priorizar URL raiz da loja (mais confiável); subpáginas como /destaques/ às vezes retornam estrutura diferente ou CAPTCHA
    $urlRaiz = rtrim($domainBase, '/') . '/' . $slug . '/';
    $urlsToTry = [$urlRaiz, $baseUrl . 'ofertas/', $baseUrl . 'destaques/', $baseUrl . 'mais-vendidos/', rtrim($storeUrl, '/') . '/'];
    $urlsToTry = array_unique(array_filter($urlsToTry));
    $produtos = [];
    $byLink = [];
    $buildId = null;

    $curlOptsJson = $curlOpts;
    $curlOptsJson[CURLOPT_HTTPHEADER] = array_merge($curlOpts[CURLOPT_HTTPHEADER] ?? [], ['Accept: application/json']);

    foreach ($urlsToTry as $idx => $url) {
        $fetchUrl = $url;
        if ($usarProxy) {
            $fetchUrl = 'https://api.scraperapi.com?api_key=' . urlencode($proxyUrl) . '&url=' . urlencode($url);
        }
        $ua = $userAgents[$idx % count($userAgents)];
        $headers = array_merge($baseHeaders, ['Referer: https://www.magazineluiza.com.br/']);
        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => 'gzip, deflate, br',
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_REFERER => 'https://www.magazineluiza.com.br/',
        ]);
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 400 || $html === false || strlen($html) < 500) {
            continue;
        }
        if (stripos($html, 'Captcha Magalu') !== false || (stripos($html, 'captcha') !== false && stripos($html, 'robot') !== false)) {
            continue;
        }
        if ($idx > 0) usleep(400000);

        $encontrados = [];
        if (empty($buildId) && preg_match('#/_next/data/([a-zA-Z0-9_-]+)/#', $html, $bm)) {
            $buildId = $bm[1];
        }
        if (empty($buildId) && preg_match('#"buildId"\s*:\s*"([^"]+)"#', $html, $bm)) {
            $buildId = $bm[1];
        }
        if (preg_match('#<script\s+id="__NEXT_DATA__"[^>]*>\s*(.*?)</script>#s', $html, $m)) {
            $json = @json_decode(trim($m[1]), true);
            if ($json && is_array($json)) {
                if (empty($buildId) && !empty($json['buildId'])) {
                    $buildId = $json['buildId'];
                }
                $encontrados = extrairProdutosNextData($json, $domainBase);
            }
        }
        if (empty($encontrados) && preg_match_all('#<script[^>]*type=["\']application/json["\'][^>]*>(.*?)</script>#s', $html, $scriptMatches)) {
            foreach ($scriptMatches[1] as $jsonStr) {
                $json = @json_decode(trim($jsonStr), true);
                if ($json && is_array($json)) {
                    $encontrados = extrairProdutosNextData($json, $domainBase);
                    if (!empty($encontrados)) break;
                }
            }
        }
        if (empty($encontrados) && $slug !== '' && preg_match_all('#<a\s+[^>]*href=["\']([^"\']*' . preg_quote($slug, '#') . '[^"\']+)["\'][^>]*>(.*?)</a>#si', $html, $linkMatches, PREG_SET_ORDER)) {
            foreach ($linkMatches as $lm) {
                $href = trim($lm[1]);
                $bloco = $lm[2];
                if (strpos($href, 'http') !== 0) {
                    $href = (strpos($href, '//') === 0 ? 'https:' : rtrim($domainBase, '/') . '/') . ltrim($href, '/');
                }
                $href = normalizarLinkMagazineVoce($href);
                $hrefNorm = preg_replace('#[#?].*$#', '', rtrim($href, '/'));
                if (strpos($hrefNorm, 'magazinevoce.com.br') === false || strpos($hrefNorm, $slug) === false) continue;
                $path = parse_url($hrefNorm, PHP_URL_PATH);
                if ($path === '/' . $slug . '/' || $path === '/' . $slug) continue;
                $nome = '';
                if (preg_match('/<img[^>]+alt=["\']([^"\']+)["\']/', $bloco, $alt)) {
                    $nome = trim(html_entity_decode($alt[1], ENT_QUOTES, 'UTF-8'));
                }
                if ($nome === '') $nome = 'Produto';
                $img = '';
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $bloco, $src)) {
                    $img = trim($src[1]);
                    if (strpos($img, 'http') !== 0) $img = (strpos($img, '//') === 0 ? 'https:' : $baseUrl) . ltrim($img, '/');
                }
                $precoNum = null;
                if (preg_match('/R\$\s*[\d.,]+/', $bloco, $p)) {
                    $precoStr = str_replace(',', '.', preg_replace('/[^\d,]/', '', $p[0]));
                    if ($precoStr !== '') $precoNum = (float) $precoStr;
                }
                $encontrados[] = ['nome' => $nome, 'preco' => $precoNum !== null ? 'R$ ' . number_format($precoNum, 2, ',', '.') : '', 'preco_num' => $precoNum, 'imagem' => $img, 'link' => $href];
            }
        }
        foreach ($encontrados as $p) {
            $link = $p['link'] ?? '';
            $linkNorm = preg_replace('#[#?].*$#', '', rtrim(normalizarLinkMagazineVoce($link), '/'));
            if ($linkNorm !== '' && !isset($byLink[$linkNorm])) {
                $byLink[$linkNorm] = $p;
            }
        }
    }

    // Buscar mais produtos via _next/data (Next.js retorna JSON puro, às vezes com mais itens)
    if ($slug !== '' && $buildId !== null) {
        $dataPaths = [$slug, $slug . '/ofertas', $slug . '/destaques', $slug . '/mais-vendidos', $slug . '/index', 'index'];
        $dataBase = rtrim($domainBase, '/') . '/_next/data/' . $buildId . '/';
        foreach ($dataPaths as $dp) {
            $dataUrl = $dataBase . $dp . '.json';
            $fetchDataUrl = $usarProxy ? ('https://api.scraperapi.com?api_key=' . urlencode($proxyUrl) . '&url=' . urlencode($dataUrl)) : $dataUrl;
            $ch = curl_init($fetchDataUrl);
            curl_setopt_array($ch, $curlOpts);
            curl_setopt($ch, CURLOPT_REFERER, $baseUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($curlOpts[CURLOPT_HTTPHEADER] ?? [], ['Accept: application/json']));
            $jsonRaw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $jsonRaw && strlen($jsonRaw) > 50) {
                $json = @json_decode($jsonRaw, true);
                if ($json && is_array($json)) {
                    $encontrados = extrairProdutosNextData($json, $domainBase);
                    foreach ($encontrados as $p) {
                        $link = $p['link'] ?? '';
                        $linkNorm = preg_replace('#[#?].*$#', '', rtrim(normalizarLinkMagazineVoce($link), '/'));
                        if ($linkNorm !== '' && !isset($byLink[$linkNorm])) {
                            $byLink[$linkNorm] = $p;
                        }
                    }
                }
            }
            usleep(300000);
        }
    }

    // Tentar também URLs alternativas (pode ser várias, separadas por vírgula ou quebra de linha)
    $urlsAlt = preg_split('/[\s,;]+/', trim((string) getConfig('magalu_loja_url_alternativa', '')), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($urlsAlt as $urlAlt) {
        $urlAlt = trim($urlAlt);
        if ($urlAlt === '' || (strpos($urlAlt, 'magazinevoce.com.br') === false && strpos($urlAlt, 'magazineluiza.com.br') === false)) continue;
        $fetchAltUrl = $usarProxy ? ('https://api.scraperapi.com?api_key=' . urlencode($proxyUrl) . '&url=' . urlencode($urlAlt)) : $urlAlt;
        $ch = curl_init($fetchAltUrl);
        curl_setopt_array($ch, $curlOpts);
        curl_setopt($ch, CURLOPT_REFERER, $baseUrl);
        $htmlAlt = curl_exec($ch);
        $codeAlt = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codeAlt < 200 || $codeAlt >= 400 || !$htmlAlt || strlen($htmlAlt) < 500) continue;
        if (empty($buildId) && preg_match('#"buildId"\s*:\s*"([^"]+)"#', $htmlAlt, $bm)) $buildId = $bm[1];
        $encontrados = [];
        if (preg_match('#<script\s+id="__NEXT_DATA__"[^>]*>\s*(.*?)</script>#s', $htmlAlt, $m)) {
            $json = @json_decode(trim($m[1]), true);
            if ($json && is_array($json)) {
                if (empty($buildId) && !empty($json['buildId'])) $buildId = $json['buildId'];
                $encontrados = extrairProdutosNextData($json, $domainBase);
            }
        }
        if (empty($encontrados) && $slug !== '' && preg_match_all('#<a\s+[^>]*href=["\']([^"\']*(?:' . preg_quote($slug, '#') . '|magazinevoce\.com\.br)[^"\']+)["\'][^>]*>(.*?)</a>#si', $htmlAlt, $linkMatches, PREG_SET_ORDER)) {
            foreach ($linkMatches as $lm) {
                $href = normalizarLinkMagazineVoce(trim($lm[1]));
                if (strpos($href, 'http') !== 0) $href = rtrim($domainBase, '/') . '/' . ltrim($href, '/');
                $hrefNorm = preg_replace('#[#?].*$#', '', rtrim($href, '/'));
                if (strpos($hrefNorm, 'magazinevoce.com.br') === false) continue;
                $path = parse_url($hrefNorm, PHP_URL_PATH);
                if ($path === '/' . $slug . '/' || $path === '/' . $slug) continue;
                $nome = preg_match('/<img[^>]+alt=["\']([^"\']+)["\']/', $lm[2], $alt) ? trim(html_entity_decode($alt[1], ENT_QUOTES, 'UTF-8')) : 'Produto';
                $encontrados[] = ['nome' => $nome, 'preco' => '', 'preco_num' => null, 'imagem' => '', 'link' => $href];
            }
        }
        foreach ($encontrados as $p) {
            $linkNorm = preg_replace('#[#?].*$#', '', rtrim(normalizarLinkMagazineVoce($p['link'] ?? ''), '/'));
            if ($linkNorm !== '' && !isset($byLink[$linkNorm])) $byLink[$linkNorm] = $p;
        }
        usleep(400000);
    }

    $produtos = array_values($byLink);

    if (empty($produtos)) {
        $err = 'A Magalu está bloqueando com CAPTCHA. Em Lojas → Magalu, configure a API key do ScraperAPI.com (plano gratuito disponível) para contornar.';
        return [];
    }

    return $produtos;
}

/**
 * Extrai preço (e opcionalmente preço anterior) da página do produto (quando a listagem não traz valor).
 * Retorna ['preco'=>string, 'preco_num'=>float, 'preco_anterior'=>float|null] ou null se não encontrar.
 */
function extrairPrecoDaPaginaProduto($urlProduto) {
    $urlProduto = trim($urlProduto);
    if ($urlProduto === '' || (strpos($urlProduto, 'magazinevoce.com.br') === false && strpos($urlProduto, 'magazineluiza.com.br') === false)) {
        return null;
    }
    $ch = curl_init($urlProduto);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => 'gzip, deflate, br',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        ],
    ];
    curl_setopt_array($ch, $opts);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$html || strlen($html) < 500) {
        return null;
    }

    $preco = null;
    $precoAnterior = null;

    // 1) __NEXT_DATA__
    if (preg_match('#<script\s+id="__NEXT_DATA__"[^>]*>\s*(.*?)</script>#s', $html, $m)) {
        $json = @json_decode(trim($m[1]), true);
        if (is_array($json)) {
            $found = extrairPrecoDeJsonProduto($json);
            if ($found !== null) {
                $preco = $found['preco_num'] ?? null;
                $precoAnterior = $found['preco_anterior'] ?? null;
            }
        }
    }

    // 2) Regex no HTML: data-price, "price": 123.45, "sellingPrice": 99.9 ou "19,90"
    if ($preco === null && preg_match_all('/(?:data-price|"price"|"salePrice"|"sellingPrice"|"currentPrice"|"bestPrice"|"lowPrice")\s*[=:]\s*([\d.,]+)/', $html, $matches)) {
        foreach ($matches[1] as $val) {
            $val = trim($val);
            if (strpos($val, ',') !== false) {
                $val = str_replace('.', '', $val);
                $val = str_replace(',', '.', $val);
            }
            if (preg_match('/^\d+\.?\d*$/', $val)) {
                $v = (float) $val;
                if ($v > 0 && $v < 1000000) {
                    $preco = $v;
                    break;
                }
            }
        }
    }
    if ($preco === null && preg_match('/R\$\s*([\d.,]+)/', $html, $m)) {
        $str = trim($m[1]);
        // Formato BR: 1.299,00 ou 19,90
        if (strpos($str, ',') !== false) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } else {
            $str = str_replace('.', '', $str);
        }
        if (preg_match('/^\d+\.?\d*$/', $str)) {
            $v = (float) $str;
            if ($v > 0 && $v < 1000000) {
                $preco = $v;
            }
        }
    }
    if ($preco === null) {
        return null;
    }
    return [
        'preco' => 'R$ ' . number_format($preco, 2, ',', '.'),
        'preco_num' => $preco,
        'preco_anterior' => $precoAnterior,
    ];
}

/**
 * Converte valor do JSON em float (aceita número ou string "R$ 19,90" / "19,90").
 */
function parsePrecoValor($raw) {
    if ($raw === null || $raw === '') return null;
    if (is_numeric($raw)) {
        $v = (float) $raw;
        return ($v > 0 && $v < 1000000) ? $v : null;
    }
    $str = preg_replace('/[^\d,.]/', '', str_replace(',', '.', (string) $raw));
    if ($str === '' || !preg_match('/[\d.]+/', $str)) return null;
    $v = (float) $str;
    return ($v > 0 && $v < 1000000) ? $v : null;
}

/**
 * Percorre JSON (página do produto) e retorna preço de venda e, se houver, preço anterior (listPrice).
 * Prioriza chaves de preço promocional para o preço atual; listPrice/originalPrice só para "antes".
 */
function extrairPrecoDeJsonProduto($node) {
    if (!is_array($node)) {
        return null;
    }
    // Preço atual: priorizar chaves de OFERTA (preço de venda), depois "price"/"valor"
    $saleKeys = ['salePrice', 'currentPrice', 'sellingPrice', 'bestPrice', 'lowPrice'];
    $otherPriceKeys = ['price', 'valor'];
    $preco = null;
    foreach ($saleKeys as $key) {
        if (isset($node[$key])) {
            $preco = parsePrecoValor($node[$key]);
            if ($preco !== null) break;
        }
    }
    if ($preco === null) {
        foreach ($otherPriceKeys as $key) {
            if (isset($node[$key])) {
                $preco = parsePrecoValor($node[$key]);
                if ($preco !== null) break;
            }
        }
    }
    if ($preco === null && isset($node['offers']) && is_array($node['offers'])) {
        $offers = $node['offers'];
        $raw = $offers['lowPrice'] ?? $offers['price'] ?? (isset($offers[0]) ? ($offers[0]['lowPrice'] ?? $offers[0]['price'] ?? null) : null);
        $preco = parsePrecoValor($raw);
    }
    // Preço anterior: só listPrice / originalPrice (nunca usar como preço atual)
    $precoAnterior = null;
    foreach (['listPrice', 'originalPrice', 'previousPrice'] as $key) {
        if (isset($node[$key])) {
            $precoAnterior = parsePrecoValor($node[$key]);
            if ($precoAnterior !== null) break;
        }
    }
    // Se "antes" for igual ou menor que o atual, não exibir como desconto
    if ($precoAnterior !== null && $preco !== null && $precoAnterior <= $preco) {
        $precoAnterior = null;
    }
    if ($preco !== null) {
        return ['preco_num' => $preco, 'preco_anterior' => $precoAnterior];
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            $found = extrairPrecoDeJsonProduto($v);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Obtém valor aninhado por path (ex: "props.pageProps.products").
 */
function getNestedValue($arr, $path) {
    $keys = explode('.', $path);
    $cur = $arr;
    foreach ($keys as $k) {
        if (!is_array($cur) || !isset($cur[$k])) return null;
        $cur = $cur[$k];
    }
    return $cur;
}

/**
 * Converte item de API/JSON em formato de produto.
 */
function itemParaProduto($item, $domainBase) {
    $urlRaw = $item['url'] ?? $item['link'] ?? $item['href'] ?? '';
    $url = is_array($urlRaw) ? (string) ($urlRaw[0] ?? reset($urlRaw) ?? '') : (string) $urlRaw;
    if ($url === '' || (strpos($url, 'magazinevoce') === false && strpos($url, 'magazineluiza') === false && !preg_match('#^/[a-z0-9_-]+/#', $url))) {
        return null;
    }
    if (strpos($url, 'http') !== 0) {
        $url = rtrim($domainBase, '/') . '/' . ltrim($url, '/');
    }
    $url = normalizarLinkMagazineVoce($url);
    $nome = trim((string) ($item['name'] ?? $item['title'] ?? $item['nome'] ?? ''));
    if ($nome === '') return null;
    $preco = null;
    $priceRaw = $item['price'] ?? $item['salePrice'] ?? $item['currentPrice'] ?? $item['listPrice'] ?? $item['valor'] ?? null;
    if ($priceRaw !== null) {
        $preco = is_numeric($priceRaw) ? (float) $priceRaw : (float) preg_replace('/[^\d,.]/', '', str_replace(',', '.', (string) $priceRaw));
    }
    $precoAnterior = null;
    if (isset($item['listPrice']) || isset($item['originalPrice'])) {
        $prev = $item['listPrice'] ?? $item['originalPrice'];
        $precoAnterior = is_numeric($prev) ? (float) $prev : (float) preg_replace('/[^\d,.]/', '', str_replace(',', '.', (string) $prev));
    }
    $img = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? '';
    $img = is_array($img) ? (string) ($img['url'] ?? $img[0]['url'] ?? $img[0] ?? '') : (string) $img;
    $prod = ['nome' => $nome, 'preco' => $preco !== null ? 'R$ ' . number_format($preco, 2, ',', '.') : '', 'preco_num' => $preco, 'imagem' => $img, 'link' => $url];
    if ($precoAnterior !== null && $preco !== null && $precoAnterior > $preco) $prod['preco_anterior'] = $precoAnterior;
    return $prod;
}

/**
 * Extrai produtos do JSON __NEXT_DATA__ (estrutura comum em lojas Next.js).
 * Primeiro tenta caminhos conhecidos (pageProps, products, items); depois varre recursivamente.
 */
function extrairProdutosNextData($json, $domainBase) {
    $produtos = [];
    $caminhosConhecidos = [
        'props.pageProps.products', 'props.pageProps.data', 'props.pageProps.items',
        'props.pageProps.initialData', 'props.pageProps.store.products', 'props.pageProps.store.items',
        'props.pageProps.catalog', 'props.pageProps.ofertas', 'pageProps.products', 'pageProps.data',
        'data.products', 'data.items', 'products', 'items', 'produtos',
    ];
    foreach ($caminhosConhecidos as $path) {
        $val = getNestedValue($json, $path);
        if (is_array($val)) {
            foreach ($val as $item) {
                if (is_array($item) && (isset($item['name']) || isset($item['title']) || isset($item['url']) || isset($item['link']))) {
                    $p = itemParaProduto($item, $domainBase);
                    if ($p !== null) {
                        $produtos[] = $p;
                    }
                }
            }
        }
    }
    if (!empty($produtos)) {
        return $produtos;
    }
    $walk = function ($node) use (&$walk, $domainBase, &$produtos) {
        if (!is_array($node)) return;
        $urlRaw = $node['url'] ?? $node['link'] ?? '';
        $url = is_array($urlRaw) ? (string) ($urlRaw[0] ?? reset($urlRaw) ?? '') : (string) $urlRaw;
        $urlOk = $url !== '' && (strpos($url, 'magazinevoce') !== false || preg_match('#^/[a-z0-9_-]+/#', $url) || preg_match('#^[a-z0-9_-]+/#', $url));
        if ((isset($node['name']) || isset($node['title'])) && $urlOk) {
            $link = $url;
            if (strpos($link, 'http') !== 0) {
                $link = rtrim($domainBase, '/') . '/' . ltrim($link, '/');
            }
            $link = normalizarLinkMagazineVoce($link);
            $preco = null;
            $priceRaw = $node['price'] ?? $node['salePrice'] ?? $node['currentPrice'] ?? $node['listPrice'] ?? $node['originalPrice']
                ?? $node['sellingPrice'] ?? $node['bestPrice'] ?? $node['valor'] ?? null;
            if ($priceRaw === null && is_array($node['offers'] ?? null)) {
                $offers = $node['offers'];
                $priceRaw = $offers['price'] ?? ($offers[0]['price'] ?? ($offers[0]['lowPrice'] ?? null)) ?? null;
            }
            if ($priceRaw === null) {
                $priceRaw = $node['priceFormatted'] ?? null;
            }
            if ($priceRaw !== null) {
                if (is_numeric($priceRaw)) {
                    $preco = (float) $priceRaw;
                } else {
                    $str = (string) $priceRaw;
                    $str = preg_replace('/[^\d,.]/', '', str_replace(',', '.', $str));
                    if ($str !== '') {
                        $preco = (float) $str;
                    }
                }
            }
            if ($preco === null && !empty($node['priceFormatted'])) {
                $pf = (string) $node['priceFormatted'];
                if (preg_match('/[\d]+[.,][\d]+/', $pf, $pm)) {
                    $preco = (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $pm[0]));
                }
            }
            $precoAnterior = null;
            $prevRaw = $node['listPrice'] ?? $node['originalPrice'] ?? $node['previousPrice'] ?? $node['listPriceFormatted'] ?? null;
            if ($prevRaw !== null) {
                if (is_numeric($prevRaw)) {
                    $precoAnterior = (float) $prevRaw;
                } else {
                    $s = preg_replace('/[^\d,.]/', '', str_replace(',', '.', (string) $prevRaw));
                    if ($s !== '') $precoAnterior = (float) $s;
                }
            }
            // Só usar preço anterior e desconto quando anterior for MAIOR que o atual (desconto real)
            if ($precoAnterior !== null && $preco !== null && $precoAnterior <= $preco) {
                $precoAnterior = null;
            }
            if ($precoAnterior !== null && $preco !== null && $precoAnterior > $preco) {
                $descontoPct = (int) round((1 - $preco / $precoAnterior) * 100);
            } else {
                $descontoPct = isset($node['discount']) ? (int) $node['discount'] : (isset($node['percentage']) ? (int) $node['percentage'] : null);
            }
            $imgRaw = $node['image'] ?? $node['imageUrl'] ?? $node['thumbnail'] ?? '';
            $img = '';
            if (is_array($imgRaw)) {
                $img = (string) ($imgRaw['url'] ?? $imgRaw[0]['url'] ?? $imgRaw[0] ?? '');
            } else {
                $img = (string) $imgRaw;
            }
            $prod = [
                'nome' => trim((string) ($node['name'] ?? $node['title'] ?? '')),
                'preco' => $preco !== null ? 'R$ ' . number_format($preco, 2, ',', '.') : '',
                'preco_num' => $preco,
                'imagem' => $img,
                'link' => $link,
            ];
            if ($precoAnterior !== null) $prod['preco_anterior'] = $precoAnterior;
            if (isset($descontoPct) && $descontoPct > 0) $prod['desconto'] = $descontoPct;
            $produtos[] = $prod;
            return;
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($json);
    return $produtos;
}

function runAutomacaoMagaluLoja($forcarExecucao = false) {
    $details = [];
    $errors = [];

    $ativa = $forcarExecucao || (getConfig('magalu_automacao_ativa', '0') === '1');
    $lojaUrl = is_string($u = getConfig('magalu_loja_url', '')) ? trim($u) : '';
    $openaiKey = trim(getConfig('openai_api_key', ''));
    if (empty($openaiKey)) {
        $openaiKey = trim(getConfig('magalu_openai_api_key', ''));
    }
    $openaiModel = trim(getConfig('magalu_openai_model', 'gpt-4o-mini'));
    $openaiPrompt = getConfig('magalu_openai_prompt', '');
    $qtd = max(1, min(10, (int) getConfig('magalu_produtos_por_execucao', '1')));
    $delay = max(1, min(120, (int) getConfig('magalu_delay_entre_envios', '10')));
    $publicarSite = getConfig('magalu_site_publicar', '1') === '1';

    $gruposFixos = function_exists('getGruposFixosPorLoja') ? getGruposFixosPorLoja('magalu') : [];

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Magalu desativada.', 'details' => $details, 'errors' => $errors];
    }
    if ($lojaUrl === '' || strpos($lojaUrl, 'magazinevoce.com.br') === false) {
        $errors[] = 'Informe a URL da sua loja Magazine Voce (ex: https://www.magazinevoce.com.br/sualoja/).';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API em Configurações.';
    }
    if (empty($gruposFixos)) {
        $errors[] = 'Selecione uma conta Evolution e ao menos um grupo na página Magalu.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Magalu.', 'details' => $details, 'errors' => $errors];
    }

    // Normalizar para URL raiz da loja — subpáginas (/destaques/, /ofertas/) costumam retornar CAPTCHA
    if (preg_match('#magazinevoce\.com\.br/([^/?]+)#', $lojaUrl, $sm)) {
        $parsed = parse_url($lojaUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'www.magazinevoce.com.br';
        $lojaUrl = $scheme . '://' . $host . '/' . $sm[1] . '/';
    }

    $produtos = extrairOfertasMagazineVoce($lojaUrl, $errScrape);
    if (!empty($errScrape)) {
        $errors[] = $errScrape;
    }

    if (empty($produtos)) {
        $msg = $errScrape ?: 'Nenhum produto extraído. A loja pode carregar ofertas via JavaScript (SPA) ou exibir verificação de segurança (CAPTCHA) para acesso automatizado.';
        $details['scrape_error'] = $msg;
        return ['success' => false, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }

    // Filtrar produtos com nome, preço e link válidos (apenas links da Magazine Voce / sua loja)
    $validos = [];
    foreach ($produtos as $p) {
        $nome = trim(is_array($p['nome'] ?? null) ? '' : (string) ($p['nome'] ?? ''));
        $linkRaw = $p['link'] ?? '';
        $link = is_array($linkRaw) ? (string) (reset($linkRaw) ?? '') : trim((string) $linkRaw);
        $precoNum = $p['preco_num'] ?? null;
        $imgRaw = $p['imagem'] ?? '';
        $img = is_array($imgRaw) ? (string) (reset($imgRaw) ?? '') : trim((string) $imgRaw);
        $linkMagalu = (strpos($link, 'magazinevoce.com.br') !== false || strpos($link, 'magazineluiza.com.br') !== false);
        if ($nome === '' || $link === '' || !$linkMagalu) {
            continue;
        }
        if ($precoNum === null) {
            $precoNum = 0;
        }
        $link = normalizarLinkMagazineVoce($link);
        $validos[] = [
                'nome' => $nome,
                'preco' => $p['preco'] ?? 'R$ ' . number_format($precoNum, 2, ',', '.'),
                'preco_num' => $precoNum,
                'preco_anterior' => $p['preco_anterior'] ?? null,
                'desconto' => $p['desconto'] ?? null,
                'imagem' => $img,
                'link' => $link,
            ];
    }
    if (empty($validos)) {
        return ['success' => false, 'message' => 'Nenhum produto válido extraído da loja.', 'details' => $details, 'errors' => $errors];
    }

    // Tabela de links já enviados — só considerar "já enviado" nos últimos X dias (resfriamento), senão a lista esgota
    $diasEvitarRepetir = max(0, (int) getConfig('magalu_dias_evitar_repetir', '1'));
    $linksJaEnviados = [];
    $carregarLinksEnviados = function ($dias) {
        $set = [];
        try {
            $pdo = getDB();
            if ($dias > 0) {
                $stmt = $pdo->prepare("SELECT link FROM magalu_links_enviados WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$dias]);
            } else {
                $stmt = $pdo->prepare("SELECT link FROM magalu_links_enviados");
                $stmt->execute();
            }
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rowLink) {
                $canon = linkCanonicoMagalu($rowLink);
                if ($canon !== '') $set[$canon] = true;
            }
        } catch (Throwable $e) {
            // ignora
        }
        return $set;
    };

    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS magalu_links_enviados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            link VARCHAR(1000) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_link (link(255)),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignora
    }

    $linksJaEnviados = $diasEvitarRepetir > 0
        ? $carregarLinksEnviados($diasEvitarRepetir)
        : $carregarLinksEnviados(0);

    $validosNovos = [];
    foreach ($validos as $v) {
        $canon = linkCanonicoMagalu($v['link']);
        if ($canon !== '' && !isset($linksJaEnviados[$canon])) {
            $validosNovos[] = $v;
        }
    }
    $validos = $validosNovos;

    // Se não sobrou nenhum produto e estamos considerando mais de 1 dia, tentar só últimos 1 dia (fallback para não travar a cron)
    if (empty($validos) && $diasEvitarRepetir > 1) {
        $linksJaEnviados = $carregarLinksEnviados(1);
        $validosNovos = [];
        foreach ($produtos as $v) {
            $nome = trim(is_array($v['nome'] ?? null) ? '' : (string) ($v['nome'] ?? ''));
            $linkRaw = $v['link'] ?? '';
            $link = is_array($linkRaw) ? (string) (reset($linkRaw) ?? '') : trim((string) $linkRaw);
            if ($nome === '' || $link === '') continue;
            $linkMagalu = (strpos($link, 'magazinevoce.com.br') !== false || strpos($link, 'magazineluiza.com.br') !== false);
            if (!$linkMagalu) continue;
            $link = normalizarLinkMagazineVoce($link);
            $canon = linkCanonicoMagalu($link);
            if ($canon !== '' && !isset($linksJaEnviados[$canon])) {
                $precoNum = $v['preco_num'] ?? null;
                if ($precoNum === null) $precoNum = 0;
                $validosNovos[] = [
                    'nome' => $nome,
                    'preco' => $v['preco'] ?? 'R$ ' . number_format($precoNum, 2, ',', '.'),
                    'preco_num' => $precoNum,
                    'preco_anterior' => $v['preco_anterior'] ?? null,
                    'desconto' => $v['desconto'] ?? null,
                    'imagem' => is_array($v['imagem'] ?? null) ? (string) (reset($v['imagem']) ?? '') : trim((string) ($v['imagem'] ?? '')),
                    'link' => $link,
                ];
            }
        }
        $validos = $validosNovos;
    }

    // Última chance: se ainda não sobrou nenhum (ex.: todos enviados nas últimas 24h), ignorar lista de enviados e pegar da listagem
    if (empty($validos)) {
        $validosNovos = [];
        foreach ($produtos as $v) {
            $nome = trim(is_array($v['nome'] ?? null) ? '' : (string) ($v['nome'] ?? ''));
            $linkRaw = $v['link'] ?? '';
            $link = is_array($linkRaw) ? (string) (reset($linkRaw) ?? '') : trim((string) $linkRaw);
            if ($nome === '' || $link === '') continue;
            if (strpos($link, 'magazinevoce.com.br') === false && strpos($link, 'magazineluiza.com.br') === false) continue;
            $link = normalizarLinkMagazineVoce($link);
            $precoNum = $v['preco_num'] ?? null;
            if ($precoNum === null) $precoNum = 0;
            $validosNovos[] = [
                'nome' => $nome,
                'preco' => $v['preco'] ?? 'R$ ' . number_format($precoNum, 2, ',', '.'),
                'preco_num' => $precoNum,
                'preco_anterior' => $v['preco_anterior'] ?? null,
                'desconto' => $v['desconto'] ?? null,
                'imagem' => is_array($v['imagem'] ?? null) ? (string) (reset($v['imagem']) ?? '') : trim((string) ($v['imagem'] ?? '')),
                'link' => $link,
            ];
        }
        $validos = $validosNovos;
    }

    if (empty($validos)) {
        return ['success' => false, 'message' => 'Nenhum produto válido na listagem da loja. Verifique a URL da loja em Lojas → Magalu.', 'details' => $details, 'errors' => $errors];
    }

    // Excluir o último produto enviado (evitar repetir o mesmo nas execuções seguidas)
    // Usar config como fonte principal (mais confiável); fallback na tabela
    $ultimoLinkEnviado = trim((string) getConfig('magalu_ultimo_link_enviado', ''));
    if ($ultimoLinkEnviado === '') {
        try {
            $pdoLinks = getDB();
            $stmt = $pdoLinks->prepare("SELECT link FROM magalu_links_enviados ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $ultimoLinkEnviado = linkCanonicoMagalu($row['link']);
            }
        } catch (Throwable $e) {
            // ignora
        }
    } else {
        $ultimoLinkEnviado = linkCanonicoMagalu($ultimoLinkEnviado);
    }
    $ultimoNomeEnviado = trim((string) getConfig('magalu_ultimo_nome_enviado', ''));
    $normalizarNome = function ($n) {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $n), 'UTF-8'));
    };
    if ($ultimoLinkEnviado !== '' || $ultimoNomeEnviado !== '') {
        $validosAntes = $validos;
        $ultimoNomeNorm = $ultimoNomeEnviado !== '' ? $normalizarNome($ultimoNomeEnviado) : '';
        $validos = array_values(array_filter($validos, function ($p) use ($ultimoLinkEnviado, $ultimoNomeNorm, $normalizarNome) {
            if ($ultimoLinkEnviado !== '' && linkCanonicoMagalu($p['link'] ?? '') === $ultimoLinkEnviado) {
                return false;
            }
            if ($ultimoNomeNorm !== '' && $normalizarNome($p['nome'] ?? '') === $ultimoNomeNorm) {
                return false;
            }
            return true;
        }));
        if (count($validos) < count($validosAntes)) {
            $details['ultimo_produto_excluido'] = true;
        }
        if (empty($validos) && count($validosAntes) >= 1) {
            return ['success' => true, 'message' => 'Produto(s) disponível(is) já foi(foram) enviado(s) recentemente. Nenhuma mensagem enviada para evitar repetição.', 'details' => $details, 'errors' => $errors];
        }
    }

    // Ordenar e embaralhar para variar
    usort($validos, function ($a, $b) {
        return strcmp(linkCanonicoMagalu($a['link'] ?? ''), linkCanonicoMagalu($b['link'] ?? ''));
    });
    shuffle($validos);
    $validos = array_slice($validos, 0, $qtd);

    $details['produtos_extraidos'] = count($produtos);
    $details['produtos_processados'] = count($validos);
    $details['produtos_site'] = [];
    $enviados = 0;
    $errosProduto = [];
    $magaluGruposIds = getConfig('magalu_grupos_ids', '');

    foreach ($validos as $p) {
        $nome = $p['nome'];
        $precoTexto = $p['preco'];
        $precoNum = $p['preco_num'];
        $img = $p['imagem'];
        $link = $p['link'];

        // Se o preço veio zerado da listagem, tentar extrair da página do produto
        if ($precoNum <= 0) {
            $dadosPreco = extrairPrecoDaPaginaProduto($link);
            if ($dadosPreco !== null && isset($dadosPreco['preco_num']) && $dadosPreco['preco_num'] > 0) {
                $precoTexto = $dadosPreco['preco'];
                $precoNum = $dadosPreco['preco_num'];
                if (isset($dadosPreco['preco_anterior']) && $dadosPreco['preco_anterior'] > 0) {
                    $p['preco_anterior'] = $dadosPreco['preco_anterior'];
                }
            }
        }

        $copy = gerarCopyOpenAI($nome, $precoTexto, $link, $openaiKey, $openaiModel, $err, $openaiPrompt, '');
        if ($copy === '' || !empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . ($err ?: 'OpenAI sem resposta');
            continue;
        }

        // Não usar preço anterior quando for igual ao atual (evitar mostrar o mesmo valor riscado)
        if (isset($p['preco_anterior']) && $p['preco_anterior'] <= $precoNum) {
            $p['preco_anterior'] = null;
        }
        // Substituir placeholders que a IA às vezes deixa literalmente
        $precoExibir = $precoNum > 0 ? $precoTexto : 'Confira no link';
        $copy = str_replace('[Preço Novo]', $precoExibir, $copy);
        $copy = str_replace('R$ [Preço Novo]', $precoExibir, $copy);
        $descontoPct = 0;
        if ($precoNum > 0 && isset($p['preco_anterior']) && $p['preco_anterior'] > 0 && $p['preco_anterior'] > $precoNum) {
            $descontoPct = (int) round((1 - $precoNum / $p['preco_anterior']) * 100);
        }
        if (isset($p['desconto']) && $p['desconto'] !== null && $p['desconto'] > 0 && $precoNum > 0) {
            $descontoPct = (int) $p['desconto'];
        }
        // Se não há desconto, remover o bloco "💥 [X]% DE DESCONTO" antes de substituir [X]%, para não exibir "0% DE DESCONTO"
        if ($descontoPct <= 0) {
            $copy = preg_replace('/\s*💥\s*\[X\]%\s*DE\s*DESCONTO\s*/iu', "\n", $copy);
            $copy = preg_replace('/\s*\[X\]%\s*DE\s*DESCONTO\s*/iu', "\n", $copy);
        }
        $copy = str_replace('[X]%', $descontoPct . '%', $copy);
        if ($precoNum <= 0) {
            $copy = preg_replace('/❌\s*R\$\s*[\d.,]+\s*❌/u', '', $copy);
            $copy = preg_replace('/💰\s*POR APENAS\s*R\$\s*0[,.]00.*/u', '💰 *Confira o preço no link*', $copy);
            $copy = preg_replace('/💥\s*\d+%\s*DE DESCONTO\s*/u', '', $copy);
        }
        // Se não há desconto real: remover linha do preço riscado e qualquer menção a "0% DE DESCONTO"
        if ($descontoPct <= 0) {
            $copy = preg_replace('/^[^\n]*❌[^\n]*R\$\s*[\d.,]+[^\n]*\r?\n?/mu', '', $copy);
            $copy = preg_replace('/❌\s*R\$\s*[\d.,]+\s*❌/u', '', $copy);
            // Remover qualquer linha que contenha "0%" e "DE DESCONTO" (com ou sem emoji; com ou sem ^)
            $copy = preg_replace('/[^\n]*0\s*%\s*DE\s*DESCONTO[^\n]*(\r?\n)?/iu', '', $copy);
            $copy = preg_replace('/[^\n]*💥[^\n]*0\s*%[^\n]*DESCONTO[^\n]*(\r?\n)?/u', '', $copy);
            $copy = str_replace(["💥 0% DE DESCONTO\n", "💥 0% DE DESCONTO\r\n", "💥 0% DE DESCONTO"], '', $copy);
            $copy = preg_replace('/💥\s*0\s*%\s*DE\s*DESCONTO\s*(\r?\n)?/u', '', $copy);
            $copy = preg_replace("/\n{3,}/", "\n\n", $copy);
            $copy = trim(preg_replace("/\n\s*\n\s*\n/", "\n\n", $copy));
        }

        $link = normalizarLinkMagazineVoce($link);
        $mensagem = formatarMensagemWhatsApp($copy, $link, true, '');

        $imgB64 = null;
        if ($img !== '') {
            $imgB64 = baixarEConverterImagemBase64($img);
        }

        if ($publicarSite) {
            $errProd = '';
            $desconto = 0;
            salvarProdutoNoSite($nome, '', $link, $img, $errProd, $precoNum, null, $desconto, 'magalu', null, null, null, false);
            if (!empty($errProd)) {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        $gruposDoBanco = [];
        if (!empty($gruposFixos)) {
            if (trim($magaluGruposIds) !== '') {
                $idsPermitidos = array_flip(array_map('intval', explode(',', $magaluGruposIds)));
                foreach ($gruposFixos as $g) {
                    if (isset($idsPermitidos[$g['id']])) {
                        $gruposDoBanco[] = $g;
                    }
                }
            } else {
                $gruposDoBanco = $gruposFixos;
            }
        }

        $enviadoEsteProduto = false;
        $dispatchesTree = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
            'whatsapp' => [],
            'telegram' => [],
        ];
        $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTree['whatsapp']);
        $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);
        $evoFallbackStatus = !empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

        if ($useWaDispatch) {
            $envAntes = $enviados;
            dispatch_executar_whatsapp_destinos(
                $dispatchesTree['whatsapp'],
                'magalu',
                (int) $delay,
                $imgB64,
                function ($idx, $total) use ($mensagem) {
                    return $mensagem;
                },
                $errosProduto,
                $enviados,
                $evoFallbackStatus,
                null,
                $forcarExecucao
            );
            if ($enviados > $envAntes) {
                $enviadoEsteProduto = true;
            }
        } else {
            foreach ($gruposDoBanco as $grupoInfo) {
                $grupoId = $grupoInfo['grupo_id'];
                $grupoIdDb = (int)($grupoInfo['id'] ?? 0);
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
                    continue;
                }
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, 'magalu', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                    continue;
                }
                $evo = $grupoInfo['evolution'];
                $ok = enviarWhatsAppMensagem($evo, $grupoId, $mensagem, $imgB64, $err);
                if ($ok) {
                    $enviados++;
                    $enviadoEsteProduto = true;
                    if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                        registrarEnvioGrupo($grupoIdDb, 'magalu');
                    }
                } else {
                    $errosProduto[] = 'WhatsApp ' . ($grupoInfo['nome'] ?? $grupoId) . ': ' . $err;
                }
                if (count($gruposDoBanco) > 1) {
                    sleep((int) $delay);
                }
            }
        }

        if (function_exists('enviarTelegram')) {
            $tgB64 = ($imgB64 !== null && $imgB64 !== '') ? (string) $imgB64 : null;
            if ($useTgDispatch) {
                dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagem, !empty($img) ? $img : null, $errosProduto, $tgB64);
            } else {
                enviarTelegramFluxoLoja('magalu', $mensagem, !empty($img) ? $img : null, $errosProduto, $tgB64);
            }
        }

        if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
            $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
            $evoStatus = getEvolutionParaStatus($fallback, 'magalu');
            if ($evoStatus) {
                $errSt = '';
                enviarWhatsAppStatusPorConta($evoStatus, $mensagem, !empty($img) ? $img : null, $errSt);
                if (!empty($errSt) && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                    $errosProduto[] = 'Status: ' . $errSt;
                }
            }
        }
        if ($enviadoEsteProduto) {
            $linkParaSalvar = linkCanonicoMagalu($link);
            if ($linkParaSalvar !== '') {
                setConfig('magalu_ultimo_link_enviado', $linkParaSalvar);
                setConfig('magalu_ultimo_nome_enviado', $nome);
                try {
                    $pdo = getDB();
                    $stmt = $pdo->prepare("INSERT INTO magalu_links_enviados (link) VALUES (?)");
                    $stmt->execute([$linkParaSalvar]);
                } catch (Throwable $e) {
                    // ignora falha ao salvar na tabela
                }
            }
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['mensagens_enviadas'] = $enviados;

    if ($enviados > 0) {
        return ['success' => true, 'message' => 'Automação Magalu concluída. ' . $enviados . ' mensagem(ns) enviada(s).', 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}
