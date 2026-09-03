<?php
/**
 * Automação Amazon: PA-API 5 (SearchItems) → OpenAI (copy) → Evolution (WhatsApp), Telegram e site.
 * Requer credenciais Product Advertising API + Associate Tag do mesmo marketplace.
 * Documentação: https://webservices.amazon.com/paapi5/documentation/
 */
if (!defined('AUTOMACAO_AMAZON_LOADED')) {
    define('AUTOMACAO_AMAZON_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

/** @return array{host: string, region: string, marketplace: string} */
function amazonPaapiResolverLocale(string $regiaoConfig): array {
    $r = strtolower(trim($regiaoConfig));
    if ($r === 'com') {
        return [
            'host' => 'webservices.amazon.com',
            'region' => 'us-east-1',
            'marketplace' => 'www.amazon.com',
        ];
    }

    return [
        'host' => 'webservices.amazon.com.br',
        'region' => 'us-east-1',
        'marketplace' => 'www.amazon.com.br',
    ];
}

/**
 * Assina e envia POST PA-API 5 (SigV4, serviço ProductAdvertisingAPI).
 *
 * @return array{ok: bool, http: int, body: string, curl_err: string}
 */
function amazonPaapi5Post(string $accessKey, string $secretKey, string $host, string $region, string $payloadJson, string $operation = 'SearchItems'): array {
    $pathMap = [
        'SearchItems' => '/paapi5/searchitems',
        'GetItems' => '/paapi5/getitems',
    ];
    $path = $pathMap[$operation] ?? '/paapi5/searchitems';
    $service = 'ProductAdvertisingAPI';
    $target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.' . $operation;

    $amzdate = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $payloadJson);

    $headers = [
        'content-encoding' => 'amz-1.0',
        'content-type' => 'application/json; charset=utf-8',
        'host' => $host,
        'x-amz-date' => $amzdate,
        'x-amz-target' => $target,
    ];
    ksort($headers);

    $canonicalHeaders = '';
    $signedHeaderNames = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        $canonicalHeaders .= $lk . ':' . trim((string) $v) . "\n";
        $signedHeaderNames[] = $lk;
    }
    $signedHeaders = implode(';', $signedHeaderNames);

    $canonicalRequest = "POST\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $datestamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = $algorithm . "\n{$amzdate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $datestamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true));

    $authorization = $algorithm
        . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $hdrLines = [
        'Content-Type: application/json; charset=utf-8',
        'Content-Encoding: amz-1.0',
        'Host: ' . $host,
        'X-Amz-Date: ' . $amzdate,
        'X-Amz-Target: ' . $target,
        'Authorization: ' . $authorization,
    ];

    $url = 'https://' . $host . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdrLines,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $body !== false && $http >= 200 && $http < 300,
        'http' => $http,
        'body' => is_string($body) ? $body : '',
        'curl_err' => (string) $curlErr,
    ];
}

/**
 * Extrai preço de item PA-API (Offers legado e OffersV2 — preferência OffersV2).
 * Em OffersV2, SavingBasis e Savings ficam dentro de Listings[].Price (não em Saving solto).
 *
 * @return array{amount: float|null, original: float|null, desconto: int, buying_display: string, list_display: string}
 */
function amazonItemExtrairPrecos(array $item): array {
    $amount = null;
    $original = null;
    $desconto = 0;
    $buyingDisplay = '';
    $listDisplay = '';

    $listings = $item['Offers']['Listings'] ?? [];
    if (isset($listings[0]['Price']['Amount'])) {
        $amount = (float) $listings[0]['Price']['Amount'];
    }
    if (isset($listings[0]['Price']['DisplayAmount'])) {
        $buyingDisplay = trim((string) $listings[0]['Price']['DisplayAmount']);
    }
    if (isset($listings[0]['SavingBasis']['Amount'])) {
        $original = (float) $listings[0]['SavingBasis']['Amount'];
    }
    if (isset($listings[0]['SavingBasis']['DisplayAmount'])) {
        $listDisplay = trim((string) $listings[0]['SavingBasis']['DisplayAmount']);
    }

    $ov2 = $item['OffersV2']['Listings'] ?? [];
    $pick = null;
    foreach ($ov2 as $listing) {
        if (!is_array($listing)) {
            continue;
        }
        if (!empty($listing['IsBuyBoxWinner'])) {
            $pick = $listing;
            break;
        }
    }
    if ($pick === null && isset($ov2[0]) && is_array($ov2[0])) {
        $pick = $ov2[0];
    }

    if (is_array($pick)) {
        $priceBlock = $pick['Price'] ?? [];
        if (is_array($priceBlock)) {
            if (isset($priceBlock['Money']['Amount'])) {
                $amount = (float) $priceBlock['Money']['Amount'];
            }
            if ($buyingDisplay === '' && !empty($priceBlock['Money']['DisplayAmount'])) {
                $buyingDisplay = trim((string) $priceBlock['Money']['DisplayAmount']);
            }
            $sb = $priceBlock['SavingBasis'] ?? [];
            if (is_array($sb) && isset($sb['Money']['Amount'])) {
                $original = (float) $sb['Money']['Amount'];
            }
            if ($listDisplay === '' && is_array($sb) && !empty($sb['Money']['DisplayAmount'])) {
                $listDisplay = trim((string) $sb['Money']['DisplayAmount']);
            }
            $sav = $priceBlock['Savings'] ?? [];
            if (is_array($sav) && isset($sav['Percentage'])) {
                $desconto = max(0, (int) $sav['Percentage']);
            }
        }
    }

    if ($desconto === 0 && $original !== null && $amount !== null && $original > $amount && function_exists('calcularDesconto')) {
        $desconto = (int) round(calcularDesconto($original, $amount));
    }

    return [
        'amount' => $amount,
        'original' => $original,
        'desconto' => $desconto,
        'buying_display' => $buyingDisplay,
        'list_display' => $listDisplay,
    ];
}

/**
 * Normaliza item SearchItems para uso interno.
 *
 * @return array{nome: string, url: string, img: string, asin: string, preco: ?float, preco_original: ?float, desconto: int, preco_display: string, preco_original_display: string}|null
 */
function amazonNormalizarItemBusca(array $item): ?array {
    $asin = trim((string) ($item['ASIN'] ?? ''));
    $url = trim((string) ($item['DetailPageURL'] ?? ''));
    $nome = trim((string) ($item['ItemInfo']['Title']['DisplayValue'] ?? ''));
    if ($asin === '' || $url === '' || $nome === '') {
        return null;
    }

    $img = '';
    if (!empty($item['Images']['Primary']['Large']['URL'])) {
        $img = trim((string) $item['Images']['Primary']['Large']['URL']);
    } elseif (!empty($item['Images']['Primary']['Medium']['URL'])) {
        $img = trim((string) $item['Images']['Primary']['Medium']['URL']);
    } elseif (!empty($item['Images']['Primary']['Small']['URL'])) {
        $img = trim((string) $item['Images']['Primary']['Small']['URL']);
    }
    if ($img !== '' && function_exists('achadinhosNormalizarUrlImagemProduto')) {
        $img = achadinhosNormalizarUrlImagemProduto($img);
    }

    $px = amazonItemExtrairPrecos($item);

    return [
        'nome' => $nome,
        'url' => $url,
        'img' => $img,
        'asin' => $asin,
        'preco' => $px['amount'],
        'preco_original' => $px['original'],
        'desconto' => $px['desconto'],
        'preco_display' => $px['buying_display'],
        'preco_original_display' => $px['list_display'],
    ];
}

/**
 * URL curta de afiliado: só /dp/ASIN?tag= (sem linkCode, th, psc).
 * Links amzn.to exigem fluxo manual no Central de Associados; aqui reduzimos ao mínimo rastreável.
 */
function amazonUrlAfiliadoCurta(string $asin, string $partnerTag, array $locale): string {
    $tag = trim($partnerTag);
    $asin = trim($asin);
    if ($tag === '' || $asin === '') {
        return '';
    }
    $mk = $locale['marketplace'] ?? '';
    $host = (stripos((string) $mk, '.br') !== false) ? 'https://www.amazon.com.br' : 'https://www.amazon.com';

    return $host . '/dp/' . rawurlencode($asin) . '?tag=' . rawurlencode($tag);
}

/**
 * Garante URL de produto com tag de associado (usa forma curta quando possível).
 */
function amazonGarantirUrlComTagAssociada(string $url, string $asin, string $partnerTag, array $locale): string {
    $curta = amazonUrlAfiliadoCurta($asin, $partnerTag, $locale);
    if ($curta !== '') {
        return $curta;
    }
    $tag = trim($partnerTag);
    if ($tag === '' || $asin === '') {
        return $url;
    }
    if (preg_match('/[?&]tag=/i', $url)) {
        return $url;
    }
    $mk = $locale['marketplace'] ?? '';
    $host = (stripos((string) $mk, '.br') !== false) ? 'https://www.amazon.com.br' : 'https://www.amazon.com';

    return $host . '/dp/' . rawurlencode($asin) . '?tag=' . rawurlencode($tag);
}

/**
 * Agrupa destinos Amazon pelo Browse Node (vazio = catálogo inteiro).
 *
 * @param list<array> $gruposFixos
 * @return array<string, list<array>>
 */
function amazonAgruparGruposPorBrowseNode(array $gruposFixos): array {
    $m = [];
    foreach ($gruposFixos as $g) {
        if (!is_array($g)) {
            continue;
        }
        $k = trim((string) ($g['amazon_ofertas_categoria'] ?? ''));
        if ($k !== '' && !preg_match('/^\d{1,20}$/', $k)) {
            $k = '';
        }
        if (!isset($m[$k])) {
            $m[$k] = [];
        }
        $m[$k][] = $g;
    }

    return $m;
}

/**
 * @param list<string> $resources
 * @return array{items: list<array>, raw_errors: list<string>}
 */
function amazonPaapiSearchItems(
    string $accessKey,
    string $secretKey,
    string $partnerTag,
    string $keywords,
    array $locale,
    int $itemCount,
    array $resources,
    string &$errOut,
    ?string $browseNodeId = null
): array {
    $errOut = '';
    $payload = [
        'Keywords' => $keywords,
        'Marketplace' => $locale['marketplace'],
        'PartnerTag' => $partnerTag,
        'PartnerType' => 'Associates',
        'SearchIndex' => 'All',
        'ItemCount' => max(1, min(10, $itemCount)),
        'Resources' => $resources,
    ];
    $bn = $browseNodeId !== null ? trim($browseNodeId) : '';
    if ($bn !== '' && preg_match('/^\d{1,20}$/', $bn)) {
        $payload['BrowseNodeId'] = $bn;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $errOut = 'Falha ao montar JSON da requisição PA-API.';

        return ['items' => [], 'raw_errors' => []];
    }

    $res = amazonPaapi5Post($accessKey, $secretKey, $locale['host'], $locale['region'], $json, 'SearchItems');
    if ($res['curl_err'] !== '') {
        $errOut = 'cURL PA-API: ' . $res['curl_err'];

        return ['items' => [], 'raw_errors' => []];
    }

    $data = json_decode($res['body'], true);
    $apiErrors = [];
    if (is_array($data) && !empty($data['Errors'])) {
        foreach ($data['Errors'] as $e) {
            if (is_array($e)) {
                $apiErrors[] = (string) ($e['Code'] ?? '') . ': ' . (string) ($e['Message'] ?? '');
            } else {
                $apiErrors[] = (string) $e;
            }
        }
    }

    if (!$res['ok']) {
        $errOut = 'PA-API HTTP ' . $res['http'] . ($apiErrors !== [] ? ' — ' . implode(' | ', $apiErrors) : '') . ' — ' . mb_substr($res['body'], 0, 400);

        return ['items' => [], 'raw_errors' => $apiErrors];
    }

    $items = $data['SearchResult']['Items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    return ['items' => $items, 'raw_errors' => $apiErrors];
}

/**
 * PA-API GetItems (até 10 ASINs) — útil quando SearchItems não devolve ofertas/preço.
 *
 * @param list<string> $itemIds
 * @param list<string> $resources
 * @return list<array>
 */
function amazonPaapiGetItems(
    string $accessKey,
    string $secretKey,
    string $partnerTag,
    array $locale,
    array $itemIds,
    array $resources,
    string &$errOut
): array {
    $errOut = '';
    $ids = array_values(array_unique(array_filter(array_map('trim', $itemIds))));
    $ids = array_slice($ids, 0, 10);
    if ($ids === []) {
        return [];
    }
    $payload = [
        'ItemIds' => $ids,
        'Marketplace' => $locale['marketplace'],
        'PartnerTag' => $partnerTag,
        'PartnerType' => 'Associates',
        'Resources' => $resources,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $errOut = 'Falha ao montar JSON GetItems.';

        return [];
    }
    $res = amazonPaapi5Post($accessKey, $secretKey, $locale['host'], $locale['region'], $json, 'GetItems');
    if ($res['curl_err'] !== '') {
        $errOut = 'cURL PA-API GetItems: ' . $res['curl_err'];

        return [];
    }
    $data = json_decode($res['body'], true);
    $apiErrors = [];
    if (is_array($data) && !empty($data['Errors'])) {
        foreach ($data['Errors'] as $e) {
            if (is_array($e)) {
                $apiErrors[] = (string) ($e['Code'] ?? '') . ': ' . (string) ($e['Message'] ?? '');
            }
        }
    }
    if (!$res['ok']) {
        $errOut = 'GetItems HTTP ' . $res['http'] . ($apiErrors !== [] ? ' — ' . implode(' | ', $apiErrors) : '');

        return [];
    }
    $items = $data['ItemsResult']['Items'] ?? [];

    return is_array($items) ? $items : [];
}

/**
 * Palavra-chave alinhada ao departamento do grupo + BrowseNodeId na PA-API.
 */
function amazonKeywordsParaBrowseCluster(string $browseKey): string {
    if ($browseKey === '') {
        return amazonEscolherKeywordBusca();
    }
    $amf = __DIR__ . '/amazon-ofertas-browse-nodes-br.php';
    if (is_file($amf) && !function_exists('amazonOfertasBrowseNodesBrasilLista')) {
        require_once $amf;
    }
    if (!function_exists('amazonOfertasBrowseNodesBrasilLista')) {
        return amazonEscolherKeywordBusca();
    }
    $lista = amazonOfertasBrowseNodesBrasilLista();
    $label = $lista[$browseKey] ?? '';
    if ($label === '' || $label === 'Todas as categorias') {
        return amazonEscolherKeywordBusca();
    }
    $parte = preg_split('/\s*[—–-]\s*/u', $label, 2);
    $parte = isset($parte[0]) ? trim($parte[0]) : '';
    if ($parte === '') {
        return amazonEscolherKeywordBusca();
    }

    return mb_strtolower($parte, 'UTF-8');
}

function amazonMesclarOffersNoItem(array $base, array $fromGet): array {
    if ($fromGet === []) {
        return $base;
    }
    if (!empty($fromGet['OffersV2']) && empty($base['OffersV2'])) {
        $base['OffersV2'] = $fromGet['OffersV2'];
    }
    $listO = $fromGet['Offers']['Listings'] ?? null;
    if (is_array($listO) && $listO !== []) {
        $have = $base['Offers']['Listings'] ?? [];
        if (!is_array($have) || $have === []) {
            if (!isset($base['Offers']) || !is_array($base['Offers'])) {
                $base['Offers'] = [];
            }
            $base['Offers']['Listings'] = $listO;
        }
    }

    return $base;
}

/**
 * Para itens sem preço numérico na SearchItems, tenta GetItems em lote (OffersV2).
 *
 * @param list<array> $itemsRaw
 * @return list<array>
 */
function amazonItensEnriquecerComGetItems(
    string $accessKey,
    string $secretKey,
    string $partnerTag,
    array $locale,
    array $itemsRaw,
    array $resourcesOffers
): array {
    $needIdx = [];
    foreach ($itemsRaw as $idx => $it) {
        if (!is_array($it)) {
            continue;
        }
        $asin = trim((string) ($it['ASIN'] ?? ''));
        if ($asin === '') {
            continue;
        }
        $ex = amazonItemExtrairPrecos($it);
        if (($ex['amount'] === null || $ex['amount'] <= 0) && $ex['buying_display'] === '') {
            $needIdx[$asin] = (int) $idx;
        }
    }
    if ($needIdx === []) {
        return $itemsRaw;
    }
    foreach (array_chunk(array_keys($needIdx), 10) as $chunk) {
        $errGi = '';
        $got = amazonPaapiGetItems($accessKey, $secretKey, $partnerTag, $locale, $chunk, $resourcesOffers, $errGi);
        $byAsin = [];
        foreach ($got as $gi) {
            if (is_array($gi) && !empty($gi['ASIN'])) {
                $byAsin[(string) $gi['ASIN']] = $gi;
            }
        }
        foreach ($chunk as $asin) {
            if (!isset($byAsin[$asin], $needIdx[$asin])) {
                continue;
            }
            $i = $needIdx[$asin];
            if (isset($itemsRaw[$i]) && is_array($itemsRaw[$i])) {
                $itemsRaw[$i] = amazonMesclarOffersNoItem($itemsRaw[$i], $byAsin[$asin]);
            }
        }
    }

    return $itemsRaw;
}

/** Palavras-chave padrão (Brasil) quando o utilizador não configurou. */
function amazonPalavrasChavePadraoBr(): array {
    return [
        'ofertas do dia',
        'eletrônicos',
        'casa e cozinha',
        'livros mais vendidos',
        'beleza',
        'esporte e lazer',
    ];
}

function amazonEscolherKeywordBusca(): string {
    $raw = trim((string) getConfig('amazon_search_keywords', ''));
    $linhas = array_values(array_filter(array_map('trim', preg_split('/\R+/u', $raw))));
    if ($linhas === []) {
        $linhas = amazonPalavrasChavePadraoBr();
    }
    shuffle($linhas);

    return $linhas[0];
}

function runAutomacaoAmazon($forcarExecucao = false, $apenasGrupoId = null) {
    $details = [];
    $errors = [];

    $ativa = $forcarExecucao || (getConfig('amazon_automacao_ativa', '0') === '1');
    $accessKey = trim(getConfig('amazon_access_key', ''));
    $secretKey = trim(getConfig('amazon_secret_key', ''));
    $associateTag = trim(getConfig('amazon_associate_tag', ''));
    $regionCfg = trim(getConfig('amazon_region', 'com.br'));

    $openaiKey = trim(getConfig('openai_api_key', ''));
    if ($openaiKey === '') {
        $openaiKey = trim(getConfig('amazon_openai_api_key', ''));
    }
    $openaiModel = trim(getConfig('amazon_openai_model', 'gpt-4o-mini'));
    $openaiPrompt = getConfig('amazon_openai_prompt', '');

    $qtd = max(1, min(10, (int) getConfig('amazon_produtos_por_execucao', '1')));
    $delay = max(1, min(120, (int) getConfig('amazon_delay_entre_envios', '10')));
    $publicarSite = getConfig('amazon_site_publicar', '1') === '1';

    $idsTeste = ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) ? [(int) $apenasGrupoId] : null;
    $gruposFixos = function_exists('getGruposFixosPorLoja')
        ? getGruposFixosPorLoja('amazon', $idsTeste)
        : [];

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Amazon desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if ($accessKey === '' || $secretKey === '' || $associateTag === '') {
        $errors[] = 'Amazon: preencha Access Key, Secret Key e Associate Tag (mesmo marketplace da PA-API).';
    }
    if ($openaiKey === '') {
        $errors[] = 'OpenAI: informe a chave da API (Configurações → OpenAI).';
    }
    if ($gruposFixos === []) {
        $errors[] = 'Amazon: cadastre pelo menos um grupo com loja «Amazon», conta WhatsApp e horários em Grupos.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios (Amazon + Grupos).', 'details' => $details, 'errors' => $errors];
    }

    $locale = amazonPaapiResolverLocale($regionCfg);
    $details['amazon_marketplace'] = $locale['marketplace'];

    $resourcesFull = [
        'Images.Primary.Large',
        'Images.Primary.Medium',
        'ItemInfo.Title',
        'BrowseNodeInfo.BrowseNodes',
        'Offers.Listings.Price',
        'Offers.Listings.SavingBasis',
        'OffersV2.Listings.Price',
        'OffersV2.Listings.Condition',
        'OffersV2.Listings.Availability',
    ];
    $resourcesLeve = [
        'Images.Primary.Large',
        'Images.Primary.Medium',
        'ItemInfo.Title',
        'BrowseNodeInfo.BrowseNodes',
    ];
    $resourcesGetItems = [
        'ItemInfo.Title',
        'BrowseNodeInfo.BrowseNodes',
        'Offers.Listings.Price',
        'Offers.Listings.SavingBasis',
        'OffersV2.Listings.Price',
        'OffersV2.Listings.Condition',
        'OffersV2.Listings.Availability',
    ];

    $pdo = getDB();
    $diasEvitar = max(0, (int) getConfig('amazon_dias_evitar_repetir', '1'));
    $catFix = (int) getConfig('amazon_site_categoria_id', '-1');
    $categoriaIdForcado = $catFix > 0 ? $catFix : null;

    $clusters = amazonAgruparGruposPorBrowseNode($gruposFixos);
    $details['amazon_browse_clusters'] = [];

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];
    $processados = 0;
    $algumClusterOk = false;

    foreach ($clusters as $browseKey => $gruposCluster) {
        if ($gruposCluster === []) {
            continue;
        }
        $browseNodeApi = ($browseKey !== '') ? $browseKey : null;
        $keywords = amazonKeywordsParaBrowseCluster($browseKey);

        $errApi = '';
        $search = amazonPaapiSearchItems($accessKey, $secretKey, $associateTag, $keywords, $locale, 10, $resourcesFull, $errApi, $browseNodeApi);
        $items = $search['items'];

        if ($items === []) {
            $errLeve = '';
            $search2 = amazonPaapiSearchItems($accessKey, $secretKey, $associateTag, $keywords, $locale, 10, $resourcesLeve, $errLeve, $browseNodeApi);
            $items = $search2['items'];
        }

        $items = amazonItensEnriquecerComGetItems($accessKey, $secretKey, $associateTag, $locale, $items, $resourcesGetItems);

        // Categoria: a PA-API já filtra com BrowseNodeId no SearchItems. Não filtramos de novo por BrowseNodeInfo:
        // a API muitas vezes devolve só nós “folha” sem ancestrais até o ID do departamento (ex. Pet shop), o que
        // zerava todos os resultados mesmo com busca correta.

        $details['amazon_browse_clusters'][] = [
            'browse_node' => $browseKey === '' ? '(todas)' : $browseKey,
            'keywords' => $keywords,
            'itens_api' => count($items),
            'grupos' => count($gruposCluster),
        ];

        if ($items === []) {
            $msgErr = $errApi;
            $errosProduto[] = 'Browse «' . ($browseKey === '' ? 'todas' : $browseKey) . '»: ' . ($msgErr !== '' ? $msgErr : 'sem resultados na PA-API.');
            continue;
        }

        $algumClusterOk = true;

        $validos = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $norm = amazonNormalizarItemBusca($it);
            if ($norm !== null && $norm['img'] !== '') {
                $validos[] = $norm;
            }
        }

        if ($validos === []) {
            $errosProduto[] = 'Browse «' . ($browseKey === '' ? 'todas' : $browseKey) . '»: itens sem dados utilizáveis.';
            continue;
        }

        shuffle($validos);

        $filtrados = [];
        foreach ($validos as $v) {
            if (count($filtrados) >= $qtd * 4) {
                break;
            }
            $linkNorm = $v['url'];
            if (preg_match('#^//#', $linkNorm)) {
                $linkNorm = 'https:' . $linkNorm;
            }
            $linkNorm = amazonGarantirUrlComTagAssociada($linkNorm, (string) ($v['asin'] ?? ''), $associateTag, $locale);
            $jaPublicado = false;
            try {
                if ($diasEvitar > 0) {
                    $st = $pdo->prepare('SELECT 1 FROM produtos_ja_publicados WHERE link_origem = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1');
                    $st->execute([$linkNorm, $diasEvitar]);
                } else {
                    $st = $pdo->prepare('SELECT 1 FROM produtos_ja_publicados WHERE link_origem = ? LIMIT 1');
                    $st->execute([$linkNorm]);
                }
                $jaPublicado = (bool) $st->fetch();
            } catch (Exception $e) {
            }
            if ($jaPublicado) {
                $details['repetidos_ignorados'] = ($details['repetidos_ignorados'] ?? 0) + 1;
                continue;
            }
            $filtrados[] = $v;
        }

        if ($filtrados === []) {
            continue;
        }

        $filtrados = array_slice($filtrados, 0, $qtd);

        foreach ($filtrados as $v) {
            $nome = $v['nome'];
            $url = $v['url'];
            if (preg_match('#^//#', $url)) {
                $url = 'https:' . $url;
            }
            $url = amazonGarantirUrlComTagAssociada($url, (string) ($v['asin'] ?? ''), $associateTag, $locale);
            $img = function_exists('achadinhosNormalizarUrlImagemProduto')
                ? achadinhosNormalizarUrlImagemProduto((string) ($v['img'] ?? ''))
                : trim((string) ($v['img'] ?? ''));
            if ($img === '') {
                $errosProduto[] = 'Amazon «' . ($browseKey === '' ? 'todas' : $browseKey) . '»: produto sem URL de imagem válida.';
                continue;
            }
            $imgB64 = baixarEConverterImagemBase64($img);
            if ($imgB64 === null || $imgB64 === '') {
                $errosProduto[] = 'Amazon: não foi possível baixar a foto de "' . mb_substr($nome, 0, 40) . '...".';
                continue;
            }
            $imgB64Envio = $imgB64;
            $preco = $v['preco'];
            $precoOrig = $v['preco_original'];
            $desconto = (int) $v['desconto'];
            $pd = trim((string) ($v['preco_display'] ?? ''));
            $pod = trim((string) ($v['preco_original_display'] ?? ''));

            if ($preco !== null && $preco > 0) {
                $precoStr = 'R$ ' . number_format($preco, 2, ',', '.');
                if ($precoOrig !== null && $precoOrig > $preco) {
                    $precoStr = 'De R$ ' . number_format($precoOrig, 2, ',', '.') . ' por R$ ' . number_format($preco, 2, ',', '.');
                }
            } elseif ($pd !== '') {
                $precoStr = ($pod !== '') ? ('De ' . $pod . ' por ' . $pd) : $pd;
            } else {
                $precoStr = 'Ver preço na Amazon';
            }
            if ($desconto > 0 && $precoStr !== 'Ver preço na Amazon') {
                $precoStr .= ' | ~' . $desconto . '% OFF';
            }

            $err = '';
            $copy = gerarCopyOpenAI($nome, $precoStr, $url, $openaiKey, $openaiModel, $err, $openaiPrompt, '');
            if ($err !== '') {
                $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . $err;
                continue;
            }
            $mensagem = formatarMensagemWhatsApp($copy, $url, true, '');
            $mensagemOriginal = $mensagem;

            $categoriaId = null;
            if ($publicarSite) {
                $id = salvarProdutoNoSite($nome, $precoStr, $url, $img, $errProd, $preco, $precoOrig, $desconto > 0 ? $desconto : null, 'amazon', null, null, $categoriaIdForcado);
                if ($id) {
                    $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
                    try {
                        $stCat = $pdo->prepare('SELECT categoria_id FROM produtos WHERE id = ?');
                        $stCat->execute([$id]);
                        $catRow = $stCat->fetch();
                        if ($catRow) {
                            $categoriaId = (int) $catRow['categoria_id'];
                        }
                    } catch (Exception $e) {
                    }
                } elseif (!empty($errProd)) {
                    $errosProduto[] = 'Site: ' . $errProd;
                }
            }

            $gruposDoBanco = $gruposCluster;

            $dispatchesTree = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
                'whatsapp' => [],
                'telegram' => [],
            ];
            $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTree['whatsapp']);
            $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);
            $evoFallbackStatus = !empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

            if ($useWaDispatch) {
                dispatch_executar_whatsapp_destinos(
                    $dispatchesTree['whatsapp'],
                    'amazon',
                    (int) $delay,
                    $imgB64Envio,
                    function ($idx, $total) use ($mensagemOriginal, $nome, $precoStr, $url, $openaiKey, $openaiModel, $openaiPrompt) {
                        if ($total > 1 && $idx > 0) {
                            $errVar = '';
                            $copyVariada = gerarCopyOpenAI($nome, $precoStr, $url, $openaiKey, $openaiModel, $errVar, $openaiPrompt, '');
                            if ($errVar === '' && $copyVariada !== '') {
                                return formatarMensagemWhatsApp($copyVariada, $url, true, '');
                            }
                        }

                        return $mensagemOriginal;
                    },
                    $errosProduto,
                    $enviados,
                    $evoFallbackStatus,
                    null,
                    $forcarExecucao
                );
            } else {
                foreach ($gruposDoBanco as $grupoIdx => $grupoInfo) {
                    $grupoId = $grupoInfo['grupo_id'];
                    $grupoIdDb = (int) ($grupoInfo['id'] ?? 0);
                    if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
                        continue;
                    }
                    if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, 'amazon', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                        continue;
                    }
                    $evo = $grupoInfo['evolution'];
                    $mensagem = $mensagemOriginal;
                    if (count($gruposDoBanco) > 1 && $grupoIdx > 0) {
                        $errV = '';
                        $copyVariada = gerarCopyOpenAI($nome, $precoStr, $url, $openaiKey, $openaiModel, $errV, $openaiPrompt, '');
                        if ($errV === '' && $copyVariada !== '') {
                            $mensagem = formatarMensagemWhatsApp($copyVariada, $url, true, '');
                        }
                    }
                    $ok = enviarWhatsAppMensagem($evo, $grupoId, $mensagem, $imgB64Envio !== '' ? $imgB64Envio : null, $err);
                    if ($ok) {
                        $enviados++;
                        if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                            registrarEnvioGrupo($grupoIdDb, 'amazon');
                        }
                    } else {
                        $errosProduto[] = 'WhatsApp grupo ' . ($grupoInfo['nome'] ?? $grupoId) . ': ' . $err;
                    }
                    if (count($gruposDoBanco) > 1 && $grupoIdx < count($gruposDoBanco) - 1) {
                        sleep((int) $delay);
                    }
                }
            }

            if (function_exists('enviarTelegram')) {
                $tgB64 = ($imgB64Envio !== null && $imgB64Envio !== '') ? (string) $imgB64Envio : null;
                if ($useTgDispatch) {
                    dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagemOriginal, $img, $errosProduto, $tgB64);
                } else {
                    enviarTelegramFluxoLoja('amazon', $mensagemOriginal, $img, $errosProduto, $tgB64);
                }
            }

            if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
                $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
                $evoStatus = getEvolutionParaStatus($fallback, 'amazon');
                if ($evoStatus) {
                    $errSt = '';
                    enviarWhatsAppStatusPorConta($evoStatus, $mensagemOriginal, $img, $errSt);
                    if ($errSt !== '' && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                        $errosProduto[] = 'Status: ' . $errSt;
                    }
                }
            }

            try {
                $linkNorm = $url;
                $ins = $pdo->prepare('INSERT IGNORE INTO produtos_ja_publicados (link_origem) VALUES (?)');
                $ins->execute([$linkNorm]);
            } catch (Exception $e) {
            }

            $processados++;
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = $processados;
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0 || $nSite > 0) {
        $msg = 'Automação Amazon concluída.';
        if ($enviados > 0) {
            $msg .= ' ' . $enviados . ' mensagem(ns) WhatsApp.';
        }
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) no site.';
        }

        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }

    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Amazon: nada enviado. ' . $errors[0], 'details' => $details, 'errors' => $errors];
    }

    return ['success' => false, 'message' => 'Amazon: nenhuma mensagem enviada.', 'details' => $details, 'errors' => $errors];
}
