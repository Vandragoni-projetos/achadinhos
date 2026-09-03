<?php
/**
 * Automação Shopee: API Afiliados → filtrar/randomizar → OpenAI (copy) → Evolution (WhatsApp) e site.
 * Substitui o fluxo n8n. Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_SHOPEE_LOADED')) {
    define('AUTOMACAO_SHOPEE_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';
require_once __DIR__ . '/shopee-ofertas-categorias-brasil.php';

// keyword substitui categoryId removido na API BR; campos extras alinhados à doc pública (affiliateshopee).
const SHOPEE_GRAPHQL_NODES = 'productName,imageUrl,commissionRate,commission,price,priceMin,priceMax,priceDiscountRate,sales,ratingStar,productLink,offerLink';
const SHOPEE_GRAPHQL_QUERY = 'query productOfferV2($page:Int,$limit:Int){productOfferV2(page:$page,limit:$limit){nodes{' . SHOPEE_GRAPHQL_NODES . '}}}';

const SHOPEE_GRAPHQL_QUERY_KEYWORD = 'query productOfferV2($page:Int,$limit:Int,$keyword:String!){productOfferV2(page:$page,limit:$limit,keyword:$keyword){nodes{' . SHOPEE_GRAPHQL_NODES . '}}}';

/**
 * @param array<string, mixed> $e item de json['errors']
 */
function shopeeExtrairCodigoErroGraphql(array $e): ?int {
    $ext = $e['extensions'] ?? null;
    if (!is_array($ext)) {
        return null;
    }
    $c = $ext['code'] ?? null;
    if (is_int($c)) {
        return $c;
    }
    if (is_string($c) && ctype_digit($c)) {
        return (int) $c;
    }

    return null;
}

/**
 * Mensagem amigável para códigos documentados da API Afiliados Shopee.
 */
function shopeeMensagemErroApiPortugues(?int $codigo, string $mensagemOriginal): string {
    if ($codigo === 10035) {
        return 'código 10035: sua conta ainda não tem acesso à Open API de Afiliados Shopee (ou o acesso expirou). Solicite/ative em https://affiliate.shopee.com.br/open_api — use a Central de Ajuda / e-mail do programa. Confira também se App ID e Secret são os da mesma conta aprovada.';
    }
    if ($codigo === 10020) {
        return 'código 10020: assinatura inválida. Verifique App ID, Secret e se o relógio do servidor está correto (timestamp da requisição).';
    }
    if ($codigo === 10030) {
        return 'código 10030: limite de requisições. Aguarde e reduza a frequência de execução.';
    }
    if (stripos($mensagemOriginal, 'do not have access') !== false || stripos($mensagemOriginal, '10035') !== false) {
        return 'código 10035: sem permissão para a Open API de Afiliados. Veja https://affiliate.shopee.com.br/open_api e o suporte Shopee Afiliados.';
    }

    return $mensagemOriginal;
}

/**
 * Grupos cuja categoria Shopee (cadastro) combina com os pools de busca do produto (como mlFiltraGruposPorPoolsOfertas no ML).
 *
 * @param array<int, array<string, mixed>> $gruposFixos
 * @param array<int, string>               $poolsProd chaves de pool ('' = todas)
 */
function shopeeFiltraGruposPorPoolsOfertas(array $gruposFixos, array $poolsProd): array {
    return array_values(array_filter($gruposFixos, function ($g) use ($poolsProd) {
        $gCat = shopeeNormalizarCategoriaOfertasGrupo((string) ($g['shopee_ofertas_categoria'] ?? ''));
        foreach ($poolsProd as $poolProd) {
            $poolProd = (string) $poolProd;
            $poolKey = shopeeNormalizarCategoriaOfertasGrupo($poolProd);
            if ($gCat === '' && $poolKey === '') {
                return true;
            }
            if ($gCat !== '' && $poolKey !== '' && $gCat === $poolKey) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Preço na API costuma vir em centavos (inteiro); floats tratamos como reais.
 *
 * @return array{preco: float, preco_original: ?float, desconto: int, preco_str: string, preco_original_str: string, percentual_str: string}
 */
function shopeeNodePrecosParaExibicao(array $n): array {
    $raw = (float) ($n['price'] ?? 0);
    if ($raw <= 0) {
        $raw = (float) ($n['priceMin'] ?? 0);
    }
    if ($raw <= 0) {
        $raw = (float) ($n['priceMax'] ?? 0);
    }
    $preco = $raw;
    if ($preco > 0 && $preco == floor($preco) && $preco >= 100) {
        $preco = $preco / 100.0;
    }
    $preco = round($preco, 2);
    $discRaw = (float) ($n['priceDiscountRate'] ?? 0);
    $preco_original = null;
    $desconto = 0;
    if ($discRaw > 0 && $preco > 0) {
        $discPct = $discRaw <= 1.5 ? ($discRaw * 100) : $discRaw;
        $preco_original = $preco / (1 - $discPct / 100);
        $desconto = (int) round($discPct);
    }
    return [
        'preco' => $preco,
        'preco_original' => $preco_original !== null ? round($preco_original, 2) : null,
        'desconto' => $desconto,
        'preco_str' => $preco > 0 ? 'R$ ' . number_format($preco, 2, ',', '.') : '',
        'preco_original_str' => ($preco_original !== null && $preco_original > 0)
            ? 'R$ ' . number_format($preco_original, 2, ',', '.') : '',
        'percentual_str' => $desconto > 0 ? (string) $desconto : '',
    ];
}

/**
 * Busca nós productOfferV2.
 * $categoryId null ou ≤0 = sem filtro (todas).
 * $categoryId >0 = busca por keyword: rótulo em shopeeOfertasCategoriasBrasilLista() para esse ID; ID desconhecido = busca ampla + aviso em $errors.
 *
 * @return list<array<string,mixed>>
 */
function shopeeGraphqlFetchProductNodes(string $appId, string $secret, ?int $categoryId, array &$errors): array {
    $poolLabel = '';
    $keyword = null;
    if ($categoryId !== null && $categoryId > 0) {
        $poolLabel = ' (categoria ' . $categoryId . ')';
        $lista = shopeeOfertasCategoriasBrasilLista();
        $rotulo = $lista[(string) $categoryId] ?? null;
        if ($rotulo !== null && $rotulo !== '') {
            $keyword = $rotulo;
        } else {
            $errors[] = 'Shopee: ID de categoria ' . $categoryId . ' sem mapeamento para palavra-chave em config/shopee-ofertas-categorias-brasil.php; usando ofertas gerais para este grupo.';
        }
    }
    if ($keyword !== null) {
        $payload = [
            'query' => SHOPEE_GRAPHQL_QUERY_KEYWORD,
            'variables' => ['page' => 1, 'limit' => 50, 'keyword' => $keyword],
        ];
    } else {
        $payload = [
            'query' => SHOPEE_GRAPHQL_QUERY,
            'variables' => ['page' => 1, 'limit' => 50],
        ];
    }
    $payloadStr = json_encode($payload);
    $timestamp = (string) time();
    $signStr = $appId . $timestamp . $payloadStr . $secret;
    $signature = hash('sha256', $signStr);

    $ch = curl_init('https://open-api.affiliate.shopee.com.br/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadStr,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: SHA256 Credential=' . $appId . ', Timestamp=' . $timestamp . ', Signature=' . $signature,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errors[] = 'Shopee API HTTP ' . $httpCode . $poolLabel;
        return [];
    }

    $json = @json_decode($res, true);
    if (!empty($json['errors']) && is_array($json['errors'])) {
        foreach ($json['errors'] as $e) {
            if (!is_array($e)) {
                $errors[] = 'Shopee API: ' . (string) $e;
                continue;
            }
            $rawMsg = (string) ($e['message'] ?? json_encode($e));
            $code = shopeeExtrairCodigoErroGraphql($e);
            $msg = shopeeMensagemErroApiPortugues($code, $rawMsg);
            $errors[] = 'Shopee API: ' . $msg;
        }
        return [];
    }

    return $json['data']['productOfferV2']['nodes'] ?? [];
}

function runAutomacaoShopee($forcarExecucao = false, $apenasGrupoId = null) {
    // #region agent log (sentinela temporária df3052)
    if (function_exists('achadinhos_agent_debug_sentinela')) {
        achadinhos_agent_debug_sentinela('runAutomacaoShopee');
    }
    // #endregion
    $details = [];
    $errors = [];

    $ativa      = $forcarExecucao || (getConfig('shopee_automacao_ativa', '0') === '1');
    $appId      = trim(getConfig('shopee_app_id', ''));
    $secret     = trim(getConfig('shopee_secret', ''));
    // Usar chave OpenAI global, se não houver, usar da loja (compatibilidade)
    $openaiKey = trim(getConfig('openai_api_key', ''));
    if (empty($openaiKey)) {
        $openaiKey = trim(getConfig('shopee_openai_api_key', ''));
    }
    $openaiModel = trim(getConfig('shopee_openai_model', 'gpt-4o-mini'));
    $openaiPrompt = getConfig('shopee_openai_prompt', '');
    $evUrl      = rtrim(getConfig('shopee_evolution_url', ''), '/');
    $evInst     = getConfig('shopee_evolution_instancia', '');
    $evKey      = getConfig('shopee_evolution_apikey', '');
    $evGrupos   = getConfig('shopee_evolution_grupos', '');
    $qtd        = max(1, min(10, (int)getConfig('shopee_produtos_por_execucao', '1')));
    $delay      = max(1, min(120, (int)getConfig('shopee_delay_entre_envios', '10')));
    $publicarSite = getConfig('shopee_site_publicar', '1') === '1';

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));
    $idsTeste = ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) ? [(int) $apenasGrupoId] : null;
    $gruposFixos = function_exists('getGruposFixosPorLoja')
        ? getGruposFixosPorLoja('shopee', $idsTeste)
        : [];

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Shopee desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($appId) || empty($secret)) {
        $errors[] = 'Shopee: preencha App ID e Secret.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    $temEvolution = !empty($gruposFixos) || (!empty($evUrl) && !empty($evInst) && !empty($evKey) && !empty($grupos));
    if (!$temEvolution) {
        $errors[] = 'Shopee: nenhum grupo com envio configurado. Em Grupos, defina a loja «Shopee», conta WhatsApp e grupo ativo; ou preencha URL/instância/chave e JIDs na página Shopee (modo legado).';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Shopee.', 'details' => $details, 'errors' => $errors];
    }

    $schemaPath = __DIR__ . '/../core/db/SchemaHelper.php';
    if (is_file($schemaPath)) {
        require_once $schemaPath;
        if (function_exists('garantirColunaGruposWhatsappAutomacaoLoja')) {
            garantirColunaGruposWhatsappAutomacaoLoja();
        }
        if (function_exists('garantirColunaGruposWhatsappShopeeOfertasCategoria')) {
            garantirColunaGruposWhatsappShopeeOfertasCategoria();
        }
    }

    /** @var list<array{n: array, grupos: array}> */
    $workQueue = [];
    $fetchErrors = [];
    $pdo = getDB();

    if (!empty($gruposFixos)) {
        $poolsMap = [];
        foreach ($gruposFixos as $gf) {
            $ck = (string) ($gf['shopee_ofertas_categoria'] ?? '');
            if (!isset($poolsMap[$ck])) {
                $poolsMap[$ck] = [];
            }
            $poolsMap[$ck][] = $gf;
        }
        $poolKeys = array_keys($poolsMap);
        $details['shopee_pools_categoria'] = $poolKeys;

        $agregarPorLink = [];
        foreach ($poolKeys as $pk) {
            $catId = ($pk !== '' && ctype_digit($pk)) ? (int) $pk : null;
            $nodes = shopeeGraphqlFetchProductNodes($appId, $secret, $catId, $fetchErrors);
            $details['produtos_api_pool_' . ($pk === '' ? 'todas' : $pk)] = count($nodes);
            foreach ($nodes as $n) {
                $nome = trim((string) ($n['productName'] ?? ''));
                $img = function_exists('achadinhosNormalizarUrlImagemProduto')
                    ? achadinhosNormalizarUrlImagemProduto((string) ($n['imageUrl'] ?? ''))
                    : trim((string) ($n['imageUrl'] ?? ''));
                $link = trim((string) ($n['offerLink'] ?? ''));
                if ($link === '' || strpos($link, 'https://') !== 0) {
                    $link = trim((string) ($n['productLink'] ?? ''));
                }
                if ($nome === '' || $img === '' || $link === '' || strpos($link, 'https://') !== 0) {
                    continue;
                }
                $n['imageUrl'] = $img;
                if (!isset($agregarPorLink[$link])) {
                    $n['offerLink'] = $link;
                    $agregarPorLink[$link] = ['n' => $n, 'pools' => []];
                }
                if (!in_array($pk, $agregarPorLink[$link]['pools'], true)) {
                    $agregarPorLink[$link]['pools'][] = $pk;
                }
            }
        }

        $details['shopee_links_unicos'] = count($agregarPorLink);
        $repetidosIgnorados = 0;
        $candidatos = [];
        foreach ($agregarPorLink as $link => $row) {
            $jaPub = false;
            try {
                $st = $pdo->prepare('SELECT 1 FROM produtos_ja_publicados WHERE link_origem IN (?, ?) LIMIT 1');
                $st->execute([$link, rtrim($link, '/')]);
                $jaPub = (bool) $st->fetch();
            } catch (Exception $e) {
                // tabela pode não existir
            }
            if ($jaPub) {
                $repetidosIgnorados++;
                continue;
            }
            $candidatos[] = $row;
        }
        $details['repetidos_ignorados'] = $repetidosIgnorados;
        $details['shopee_candidatos_apos_dedup'] = count($candidatos);
        shuffle($candidatos);
        $maxCand = min(count($candidatos), max((int) $qtd * 15, 60));
        $candidatos = array_slice($candidatos, 0, $maxCand);

        foreach ($candidatos as $row) {
            if (count($workQueue) >= $qtd) {
                break;
            }
            $gruposFiltrados = shopeeFiltraGruposPorPoolsOfertas($gruposFixos, $row['pools']);
            if (empty($gruposFiltrados)) {
                continue;
            }
            $workQueue[] = ['n' => $row['n'], 'grupos' => $gruposFiltrados];
        }

        if (empty($workQueue) && !empty($fetchErrors)) {
            foreach ($fetchErrors as $fe) {
                $errors[] = $fe;
            }
        }
    } else {
        $nodes = shopeeGraphqlFetchProductNodes($appId, $secret, null, $errors);
        $details['produtos_api'] = count($nodes);
        if (empty($nodes) && !empty($errors)) {
            return ['success' => false, 'message' => 'Falha ao buscar produtos na API Shopee.', 'details' => $details, 'errors' => $errors];
        }
        if (empty($nodes)) {
            $errors[] = 'A API retornou 0 produtos. Confirme no Programa de Afiliados Shopee se há ofertas disponíveis e se App ID/Secret estão corretos.';
            return ['success' => false, 'message' => 'Nenhum produto retornado pela API Shopee.', 'details' => $details, 'errors' => $errors];
        }

        $validos = [];
        foreach ($nodes as $n) {
            $nome = trim((string) ($n['productName'] ?? ''));
            $img = function_exists('achadinhosNormalizarUrlImagemProduto')
                ? achadinhosNormalizarUrlImagemProduto((string) ($n['imageUrl'] ?? ''))
                : trim((string) ($n['imageUrl'] ?? ''));
            $link = trim((string) ($n['offerLink'] ?? ''));
            if ($link === '' || strpos($link, 'https://') !== 0) {
                $link = trim((string) ($n['productLink'] ?? ''));
            }
            if ($nome === '' || $img === '' || $link === '' || strpos($link, 'https://') !== 0) {
                continue;
            }
            $jaPub = false;
            try {
                $st = $pdo->prepare('SELECT 1 FROM produtos_ja_publicados WHERE link_origem IN (?, ?) LIMIT 1');
                $st->execute([$link, rtrim($link, '/')]);
                $jaPub = (bool) $st->fetch();
            } catch (Exception $e) {
            }
            if ($jaPub) {
                continue;
            }
            $n['offerLink'] = $link;
            $n['imageUrl'] = $img;
            $validos[] = $n;
        }
        $details['produtos_validos'] = count($validos);
        if (empty($validos)) {
            return ['success' => false, 'message' => 'Nenhum produto válido (nome, imagem, link) após filtro ou já enviados.', 'details' => $details, 'errors' => $errors];
        }

        shuffle($validos);
        $validos = array_slice($validos, 0, $qtd);

        $gruposLegacy = [];
        $shopeeGruposIds = getConfig('shopee_grupos_ids', '');
        if (trim($shopeeGruposIds) === '' && !empty($grupos)) {
            foreach ($grupos as $g) {
                $gruposLegacy[] = [
                    'id' => 0,
                    'nome' => 'Grupo Padrão',
                    'grupo_id' => $g,
                    'evolution_conta_id' => 0,
                    'evolution' => [
                        'url_base' => $evUrl,
                        'instancia' => $evInst,
                        'api_key' => $evKey,
                    ],
                ];
            }
        }

        foreach ($validos as $n) {
            $workQueue[] = ['n' => $n, 'grupos' => $gruposLegacy];
        }
    }

    if (empty($workQueue)) {
        $fetchErrors = array_values(array_unique($fetchErrors));
        $msg = 'Nenhum produto para enviar.';
        $tem10035 = false;
        foreach ($fetchErrors as $fe) {
            if (strpos($fe, '10035') !== false || stripos($fe, 'código 10035') !== false || stripos($fe, 'Open API de Afiliados') !== false) {
                $tem10035 = true;
                break;
            }
        }
        if ($tem10035) {
            $msg = 'Acesso à API de Afiliados Shopee negado (código 10035). Não é falha de categoria de grupo: a conta precisa estar autorizada na Open API. Solicite em https://affiliate.shopee.com.br/open_api (Central de Ajuda / e-mail) e confira App ID e Secret.';
            $umaLinha10035 = null;
            foreach ($fetchErrors as $fe) {
                if (strpos($fe, '10035') !== false || stripos($fe, 'código 10035') !== false) {
                    $umaLinha10035 = $fe;
                    break;
                }
            }
            $fetchErrors = $umaLinha10035 !== null ? [$umaLinha10035] : $fetchErrors;
        } elseif (!empty($gruposFixos) && !empty($fetchErrors)) {
            $msg = 'A API Shopee não retornou ofertas ou houve erro na consulta. Veja os detalhes abaixo; categorias dos grupos só afetam a busca quando o acesso à API está ok.';
        } elseif (!empty($gruposFixos)) {
            $msg = 'Nenhuma oferta válida nas categorias dos grupos (ou todas já enviadas). Verifique palavras-chave em config/shopee-ofertas-categorias-brasil.php e ofertas no painel de afiliado.';
        }

        return ['success' => false, 'message' => $msg, 'details' => $details, 'errors' => array_merge($errors, $fetchErrors)];
    }

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];

    foreach ($workQueue as $wq) {
        $n = $wq['n'];
        $productName = trim((string) ($n['productName'] ?? ''));
        $imageUrl    = function_exists('achadinhosNormalizarUrlImagemProduto')
            ? achadinhosNormalizarUrlImagemProduto((string) ($n['imageUrl'] ?? ''))
            : trim((string) ($n['imageUrl'] ?? ''));
        $offerLink   = trim((string) ($n['offerLink'] ?? ''));
        if ($offerLink === '' || strpos($offerLink, 'https://') !== 0) {
            $offerLink = trim((string) ($n['productLink'] ?? ''));
        }
        if ($productName === '' || $offerLink === '' || strpos($offerLink, 'https://') !== 0) {
            continue;
        }
        if ($imageUrl === '') {
            $errosProduto[] = 'Shopee: oferta sem URL de imagem válida (' . mb_substr($productName, 0, 35) . '...).';
            continue;
        }

        $imgB64 = baixarEConverterImagemBase64($imageUrl);
        if ($imgB64 === null || $imgB64 === '') {
            $errosProduto[] = 'Shopee: não foi possível baixar a foto do produto (' . mb_substr($productName, 0, 35) . '...).';
            continue;
        }

        $px = shopeeNodePrecosParaExibicao($n);
        $price = $px['preco'];
        $preco_original = $px['preco_original'];
        $desconto = $px['desconto'];
        $precoStr = $px['preco_str'];
        $precoOriginalStr = $px['preco_original_str'];
        $percentualStr = $px['percentual_str'];

        $sales = (int) ($n['sales'] ?? 0);
        $ratingStar = (float) ($n['ratingStar'] ?? 0);
        $vendasStr = (string) $sales;
        $avaliacaoStr = $ratingStar > 0 ? number_format($ratingStar, 1, '.', '') : '';

        $categoriaId = null;
        if ($publicarSite) {
            $errCat = '';
            $categoriaId = obterOuCriarCategoriaParaProduto($productName, $errCat, 'shopee', $precoStr);
            if (!empty($errCat)) {
                $errosProduto[] = 'Categoria: ' . $errCat;
            }
        }

        // OpenAI – copy Shopee para WhatsApp
        $copy = gerarCopyShopeeOpenAI($productName, $precoOriginalStr, $precoStr, $percentualStr, $offerLink, $vendasStr, $avaliacaoStr, $openaiKey, $openaiModel, $err, $openaiPrompt);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($productName, 0, 40) . '...": ' . $err;
            continue;
        }
        $mensagem = formatarMensagemShopeeWhatsApp($copy, $offerLink);

        if ($publicarSite) {
            $id = salvarProdutoNoSite(
                $productName,
                $precoStr,
                $offerLink,
                $imageUrl,
                $errProd,
                $price > 0 ? $price : null,
                $preco_original,
                $desconto > 0 ? $desconto : null,
                'shopee',
                null,
                null,
                $categoriaId
            );
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($productName, 0, 50)];
            } elseif (!empty($errProd)) {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        $gruposDoBanco = $wq['grupos'];
        if (empty($gruposDoBanco)) {
            continue;
        }

        // Enviar para cada grupo (uma oferta diferente por grupo se houver múltiplos) ou dispatch opcional
        $mensagemOriginal = $mensagem;
        $dispatchesTree = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
            'whatsapp' => [],
            'telegram' => [],
        ];
        $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTree['whatsapp']);
        $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);
        $evoFallbackStatus = !empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

        // #region agent log
        static $achadinhosDbgShopeeOnce = false;
        if (!$achadinhosDbgShopeeOnce && function_exists('achadinhos_agent_debug_ndjson')) {
            $achadinhosDbgShopeeOnce = true;
            $suffix = "\n\n#️⃣ *Aproveite enquanto está disponível!👇*\n\n🔗 ";
            $bodyLen = max(0, mb_strlen($mensagem) - mb_strlen($suffix . $offerLink));
            $dexp = function_exists('dispatch_expandir_linhas_por_grupo_prioridade')
                ? dispatch_expandir_linhas_por_grupo_prioridade($dispatchesTree['whatsapp'])
                : [];
            achadinhos_agent_debug_ndjson(
                'automacao-shopee.php:antes_envio_wa',
                'Shopee copy vs mensagem e dispatch',
                [
                    'copy_len' => mb_strlen($copy),
                    'mensagem_len' => mb_strlen($mensagem),
                    'corpo_estimado_len' => $bodyLen,
                    'useWaDispatch' => $useWaDispatch,
                    'dispatch_destinos_count' => count($dexp),
                    'imgB64_len' => strlen((string) ($imgB64 ?? '')),
                ],
                'SH-A'
            );
        }
        // #endregion

        if ($useWaDispatch) {
            dispatch_executar_whatsapp_destinos(
                $dispatchesTree['whatsapp'],
                'shopee',
                (int) $delay,
                $imgB64 ?? '',
                function ($idx, $total) use (
                    $mensagemOriginal,
                    $productName,
                    $precoOriginalStr,
                    $precoStr,
                    $percentualStr,
                    $offerLink,
                    $vendasStr,
                    $avaliacaoStr,
                    $openaiKey,
                    $openaiModel,
                    $openaiPrompt
                ) {
                    if ($total > 1 && $idx > 0) {
                        $errVar = '';
                        $copyVariada = gerarCopyShopeeOpenAI(
                            $productName,
                            $precoOriginalStr,
                            $precoStr,
                            $percentualStr,
                            $offerLink,
                            $vendasStr,
                            $avaliacaoStr,
                            $openaiKey,
                            $openaiModel,
                            $errVar,
                            $openaiPrompt
                        );
                        if (empty($errVar) && !empty($copyVariada)) {
                            return formatarMensagemShopeeWhatsApp($copyVariada, $offerLink);
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
                $grupoIdDb = (int)($grupoInfo['id'] ?? 0);
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
                    continue;
                }
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, 'shopee', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                    continue;
                }
                $evo = $grupoInfo['evolution'];

                if (count($gruposDoBanco) > 1 && $grupoIdx > 0) {
                    $copyVariada = gerarCopyShopeeOpenAI(
                        $productName,
                        $precoOriginalStr,
                        $precoStr,
                        $percentualStr,
                        $offerLink,
                        $vendasStr,
                        $avaliacaoStr,
                        $openaiKey,
                        $openaiModel,
                        $err,
                        $openaiPrompt
                    );
                    if (empty($err) && !empty($copyVariada)) {
                        $mensagem = formatarMensagemShopeeWhatsApp($copyVariada, $offerLink);
                    } else {
                        $mensagem = $mensagemOriginal;
                    }
                } else {
                    $mensagem = $mensagemOriginal;
                }

                $ok = enviarWhatsAppMensagem($evo, $grupoId, $mensagem, $imgB64, $err);
                if ($ok) {
                    $enviados++;
                    if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                        registrarEnvioGrupo($grupoIdDb, 'shopee');
                    }
                } else {
                    $errosProduto[] = "WhatsApp grupo " . ($grupoInfo['nome'] ?? $grupoId) . ": " . $err;
                }
                if (count($gruposDoBanco) > 1 && $grupoIdx < count($gruposDoBanco) - 1) {
                    sleep((int)$delay);
                }
            }
        }

        if (function_exists('enviarTelegram')) {
            $tgB64 = ($imgB64 !== null && $imgB64 !== '') ? (string) $imgB64 : null;
            if ($useTgDispatch) {
                dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagemOriginal, $imageUrl ?? null, $errosProduto, $tgB64);
            } else {
                enviarTelegramFluxoLoja('shopee', $mensagemOriginal, $imageUrl ?? null, $errosProduto, $tgB64);
            }
        }

        if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
            $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
            $evoStatus = getEvolutionParaStatus($fallback, 'shopee');
            if ($evoStatus) {
                $errSt = '';
                enviarWhatsAppStatusPorConta($evoStatus, $mensagemOriginal, $imageUrl ?? null, $errSt);
                if (!empty($errSt) && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                    $errosProduto[] = 'Status: ' . $errSt;
                }
            }
        }

        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO produtos_ja_publicados (link_origem) VALUES (?)');
            $ins->execute([$offerLink]);
        } catch (Exception $e) {
            // tabela pode não existir
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = count($workQueue);
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0) {
        $msg = 'Automação Shopee concluída. ' . $enviados . ' mensagem(ns) enviada(s) ao(s) grupo(s) WhatsApp.';
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        }
        if ($errors !== []) {
            $msg .= ' Atenção: há aviso(s)/erro(s) abaixo (ex.: Status/Stories ou Telegram) — confira se o post chegou no grupo.';
        }
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

function gerarCopyShopeeOpenAI($nome, $precoOriginal, $preco, $percentualDesconto, $link, $vendas, $avaliacao, $apiKey, $model, &$err, $systemPrompt = '') {
    $err = '';
    $defaultSys = "Você é especialista em copywriting para WhatsApp da SHOPEE.\n\n" .
        "🎯 ESTRUTURA OBRIGATÓRIA:\n\n" .
        "1. 🔥 **TÍTULO EM NEGRITO E CAIXA ALTA** (com 2-3 emojis)\n" .
        "2. *Nome do produto em itálico*\n" .
        "3. ❌ ~~Preço original riscado~~ (se houver)\n" .
        "4. 💚 **Preço promocional em negrito**\n" .
        "5. 💥 **% OFF em negrito** (se houver)\n" .
        "6. ✅ 2-3 benefícios principais\n" .
        "7. 📊 Prova social (vendas/avaliação se > 0)\n" .
        "8. **👉 CALL-TO-ACTION em negrito**\n\n" .
        "⚠️ REGRAS CRÍTICAS:\n\n" .
        "- Máximo 12 linhas\n" .
        "- NUNCA coloque o link no texto - ele será adicionado automaticamente depois\n" .
        "- NUNCA use formatação [texto](https://...)\n" .
        "- Use apenas emojis, negrito e itálico\n" .
        "- Se preço original vazio, omita essa linha\n" .
        "- Se avaliação for N/A ou 0.0, omita prova social\n" .
        "- SEMPRE termine com frase de exclusividade em negrito";
    $sys = (trim((string)$systemPrompt) !== '') ? trim($systemPrompt) : $defaultSys;

    $user = "{$nome}, {$precoOriginal}, {$preco}, {$percentualDesconto}, {$link}, vendas: {$vendas}, avaliação: {$avaliacao}";

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.4,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI HTTP ' . $code . '. Verifique a chave e o modelo.';
        return '';
    }
    $j = @json_decode($res, true);
    $txt = trim($j['choices'][0]['message']['content'] ?? '');
    if ($txt === '') {
        $err = 'OpenAI respondeu vazio.';
        return '';
    }
    return $txt;
}

function formatarMensagemShopeeWhatsApp($copy, $linkAfiliado) {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $t = preg_replace('/https?:\/\/[^\s]+/u', '', $t);
    $t = preg_replace('/🔥\s*\*\*Oferta exclusiva.*?\*\*/iu', '', $t);
    $t = preg_replace('/👉\s*\*\*.*?garant[ea].*?\*\*/iu', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/\*(.*?)\*/s', '_$1_', $t);
    $t = trim($t);
    return $t . "\n\n#️⃣ *Aproveite enquanto está disponível!👇*\n\n🔗 " . $linkAfiliado;
}
