<?php
/**
 * Automação Magalu (Magazine Luiza): API Lomadee (produtos + shorten) → IA (copy) → Evolution (WhatsApp) e site.
 * Substitui o fluxo n8n. Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_MAGALU_LOADED')) {
    define('AUTOMACAO_MAGALU_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

const LOMADEE_BASE = 'https://api-beta.lomadee.com.br';

function runAutomacaoMagalu($forcarExecucao = false, $apenasGrupoId = null) {
    $details = [];
    $errors = [];

    $ativa        = $forcarExecucao || (getConfig('magalu_automacao_ativa', '0') === '1');
    $lomadeeKey   = trim(getConfig('magalu_lomadee_api_key', ''));
    // Usar chave OpenAI global, se não houver, usar da loja (compatibilidade)
    $openaiKey = trim(getConfig('openai_api_key', ''));
    if (empty($openaiKey)) {
        $openaiKey = trim(getConfig('magalu_openai_api_key', ''));
    }
    $openaiModel  = trim(getConfig('magalu_openai_model', 'gpt-4o-mini'));
    $openaiPrompt = getConfig('magalu_openai_prompt', '');
    $evUrl        = rtrim(getConfig('magalu_evolution_url', ''), '/');
    $evInst       = getConfig('magalu_evolution_instancia', '');
    $evKey        = getConfig('magalu_evolution_apikey', '');
    $evGrupos     = getConfig('magalu_evolution_grupos', '');
    $qtd          = max(1, min(10, (int) getConfig('magalu_produtos_por_execucao', '1')));
    $delay        = max(1, min(120, (int) getConfig('magalu_delay_entre_envios', '10')));
    $publicarSite = getConfig('magalu_site_publicar', '1') === '1';

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));
    $idsTeste = ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) ? [(int) $apenasGrupoId] : null;
    $gruposFixos = function_exists('getGruposFixosPorLoja')
        ? getGruposFixosPorLoja('magalu', $idsTeste)
        : [];

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Magalu desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($lomadeeKey)) {
        $errors[] = 'Magalu: preencha a API Key da Lomadee.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    $temEvolution = !empty($gruposFixos) || (!empty($evUrl) && !empty($evInst) && !empty($evKey) && !empty($grupos));
    if (!$temEvolution) {
        $errors[] = 'Magalu: selecione uma conta e grupos na página Magalu (Evolution API).';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Magalu.', 'details' => $details, 'errors' => $errors];
    }

    // 1) GET /affiliate/products — buscar várias páginas para diversificar (evitar só ar-condicionado/uma categoria)
    $paginasParaBuscar = [1, 2, 3, 4, 5]; // páginas diferentes = mix de categorias da API
    shuffle($paginasParaBuscar);
    $produtos = [];
    $maxRequisicoes = 5; // rate limit ~10/min; 5 páginas é seguro
    foreach (array_slice($paginasParaBuscar, 0, $maxRequisicoes) as $page) {
        $url = LOMADEE_BASE . '/affiliate/products?limit=50&page=' . (int) $page;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-api-key: ' . $lomadeeKey],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) {
            continue;
        }
        $json = @json_decode($res, true);
        $lista = [];
        if (isset($json['data']) && is_array($json['data'])) {
            $lista = $json['data'];
        } elseif (isset($json['data']['items']) && is_array($json['data']['items'])) {
            $lista = $json['data']['items'];
        } elseif (isset($json['data']['products']) && is_array($json['data']['products'])) {
            $lista = $json['data']['products'];
        } elseif (isset($json['products']) && is_array($json['products'])) {
            $lista = $json['products'];
        } elseif (isset($json['items']) && is_array($json['items'])) {
            $lista = $json['items'];
        } elseif (is_array($json)) {
            $lista = $json;
        }
        foreach ($lista as $item) {
            $produtos[] = $item;
        }
        if ($page > 1) {
            usleep(200000); // 0.2s entre requisições para respeitar rate limit
        }
    }
    if (empty($produtos)) {
        $errors[] = 'Lomadee: nenhum produto retornado (tente novamente).';
        return ['success' => false, 'message' => 'Falha ao buscar produtos na API Lomadee.', 'details' => $details, 'errors' => $errors];
    }

    $validos = [];
    $urlsVistos = []; // evita duplicata quando o mesmo produto vem em mais de uma página
    foreach ($produtos as $item) {
        $p = (is_array($item) && isset($item['product'])) ? $item['product'] : $item;
        if (!is_array($p)) {
            continue;
        }
        $nome = trim((string) ($p['name'] ?? $p['title'] ?? $p['productName'] ?? $p['nome'] ?? ''));
        $meta = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];
        $opts = is_array($p['options'] ?? null) ? $p['options'] : [];
        $opt0 = isset($opts[0]) && is_array($opts[0]) ? $opts[0] : [];
        $pricing0 = isset($opt0['pricing'][0]) && is_array($opt0['pricing'][0]) ? $opt0['pricing'][0] : [];
        $precoRaw = $p['price'] ?? $p['currentPrice'] ?? $p['salePrice'] ?? $p['preco'] ?? $p['priceValue']
            ?? ($meta['price'] ?? $meta['currentPrice'] ?? $meta['salePrice'] ?? $meta['minPrice'] ?? $meta['value'] ?? null)
            ?? (is_array($meta['pricing'] ?? null) ? ($meta['pricing']['price'] ?? $meta['pricing']['currentPrice'] ?? null) : null)
            ?? ($opt0['price'] ?? $opt0['currentPrice'] ?? $opt0['value'] ?? $opt0['salePrice'] ?? null)
            ?? ($pricing0['price'] ?? $pricing0['listPrice'] ?? null);
        $preco = $precoRaw !== null && $precoRaw !== '' ? (float) $precoRaw : null;
        $img = trim((string) ($p['image'] ?? $p['imageUrl'] ?? $p['thumbnail'] ?? $p['imageLink'] ?? $p['img'] ?? $p['picture'] ?? $p['imagem'] ?? ''));
        if ($img === '' && !empty($p['images']) && is_array($p['images'])) {
            $firstImg = $p['images'][0];
            if (is_array($firstImg)) {
                $img = trim((string) ($firstImg['url'] ?? $firstImg['link'] ?? $firstImg['src'] ?? $firstImg['imageUrl'] ?? $firstImg['path'] ?? $firstImg['fullUrl'] ?? ''));
            } else {
                $img = trim((string) $firstImg);
            }
        }
        if ($img === '' && !empty($opt0['images']) && is_array($opt0['images']) && isset($opt0['images'][0])) {
            $oi = $opt0['images'][0];
            $img = is_array($oi) ? trim((string) ($oi['url'] ?? $oi['link'] ?? $oi['src'] ?? '')) : trim((string) $oi);
        }
        $url = trim((string) ($p['url'] ?? $p['link'] ?? $p['productUrl'] ?? $p['productLink'] ?? $p['href'] ?? $p['linkUrl'] ?? ''));
        if ($url !== '' && strpos($url, 'http') !== 0) {
            $url = (strpos($url, '//') === 0) ? 'https:' . $url : $url;
        }
        $urlNorm = preg_replace('#[#?].*$#', '', rtrim($url, '/'));
        if (isset($urlsVistos[$urlNorm])) {
            continue;
        }
        if ($nome !== '' && $preco !== null && $preco > 0 && $img !== '' && $url !== '' && strpos($url, 'http') === 0) {
            $urlsVistos[$urlNorm] = true;
            $precoAnt = null;
            if (isset($p['oldPrice']) || isset($p['previousPrice'])) {
                $precoAnt = (float) ($p['oldPrice'] ?? $p['previousPrice'] ?? 0);
            } elseif (!empty($pricing0['listPrice']) && (float)$pricing0['listPrice'] > $preco) {
                $precoAnt = (float) $pricing0['listPrice'];
            }
            $validos[] = [
                'nome'             => $nome,
                'preco_atual'      => $preco,
                'preco_anterior'   => $precoAnt,
                'imagem'           => $img,
                'url'              => $url,
                'desconto'         => isset($p['discount']) ? (int) $p['discount'] : null,
                'organizationId'   => (string) ($p['organizationId'] ?? ''),
            ];
        }
    }

    $details['produtos_api'] = count($produtos);
    $details['paginas_buscadas'] = $maxRequisicoes;
    $details['produtos_validos'] = count($validos);
    if (empty($validos) && !empty($produtos)) {
        $first = is_array($produtos[0]) && isset($produtos[0]['product']) ? $produtos[0]['product'] : $produtos[0];
        if (is_array($first)) {
            $details['exemplo_chaves_primeiro_item'] = array_keys($first);
            $details['exemplo_metadata'] = $first['metadata'] ?? null;
            $details['exemplo_options'] = isset($first['options'][0]) ? $first['options'][0] : ($first['options'] ?? null);
            $details['exemplo_images'] = isset($first['images'][0]) ? $first['images'][0] : ($first['images'] ?? null);
        }
    }
    if (empty($validos)) {
        return ['success' => false, 'message' => 'Nenhum produto válido (nome, preço, imagem, url) na API Lomadee.', 'details' => $details, 'errors' => $errors];
    }

    shuffle($validos);
    $validos = array_slice($validos, 0, $qtd);

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];

    foreach ($validos as $idx => $p) {
        $nome   = $p['nome'];
        $preco  = $p['preco_atual'];
        $precoAnt = $p['preco_anterior'];
        $img    = $p['imagem'];
        $url    = $p['url'];
        $desconto = $p['desconto'];

        if ($desconto === null && $precoAnt > 0 && $preco > 0 && $precoAnt > $preco && function_exists('calcularDesconto')) {
            $desconto = (int) round(calcularDesconto($precoAnt, $preco));
        }
        $desconto = $desconto !== null ? (int) $desconto : 0;

        // 2) POST /affiliate/shortener/url (link de afiliado)
        $organizationId = $p['organizationId'] ?? '';
        $linkAfiliado = lomadeeShorten($url, $lomadeeKey, $organizationId, $err);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . $err;
            continue;
        }
        if (empty($linkAfiliado)) {
            $linkAfiliado = $url;
        }

        // 3) Preparar prompt e OpenAI
        $precoTexto = $precoAnt > 0
            ? ('De R$ ' . number_format($precoAnt, 2, ',', '.') . ' por R$ ' . number_format($preco, 2, ',', '.'))
            : ('R$ ' . number_format($preco, 2, ',', '.'));
        if ($desconto > 0) {
            $precoTexto .= ', ' . $desconto . '%';
        }
        $promptUser = $nome . ', ' . $precoTexto . ', ' . $linkAfiliado;

        $copy = gerarCopyMagaluOpenAI($promptUser, $openaiKey, $openaiModel, $err, $openaiPrompt);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . $err;
            continue;
        }
        $mensagem = formatarMensagemMagaluWhatsApp($copy, $linkAfiliado);

        // 4) Imagem → Base64
        $imgB64 = baixarEConverterImagemBase64($img);

        // 5) Publicar no site
        $categoriaId = null;
        if ($publicarSite) {
            $precoOrig = $precoAnt > 0 ? $precoAnt : null;
            $id = salvarProdutoNoSite($nome, '', $linkAfiliado, $img, $errProd, $preco, $precoOrig, $desconto, 'magalu', null, null, null, false);
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
                // Buscar categoria_id do produto salvo
                try {
                    $pdo = getDB();
                    $stCat = $pdo->prepare("SELECT categoria_id FROM produtos WHERE id = ?");
                    $stCat->execute([$id]);
                    $catRow = $stCat->fetch();
                    if ($catRow) {
                        $categoriaId = (int)$catRow['categoria_id'];
                    }
                } catch (Exception $e) {
                    // Ignorar erro
                }
            } elseif (!empty($errProd)) {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        // 6) Evolution: usar apenas grupos configurados na página Magalu (conta + grupos da loja)
        $gruposDoBanco = [];
        $magaluGruposIds = getConfig('magalu_grupos_ids', '');
        
        // Se há grupos fixos, filtrar pelos grupos selecionados na página Magalu
        if (!empty($gruposFixos)) {
            // Se há grupos selecionados na página Magalu, filtrar apenas esses
            if (trim($magaluGruposIds) !== '') {
                $idsPermitidos = array_flip(array_map('intval', explode(',', $magaluGruposIds)));
                foreach ($gruposFixos as $g) {
                    if (isset($idsPermitidos[$g['id']])) {
                        $gruposDoBanco[] = $g;
                    }
                }
            } else {
                // Se não há grupos selecionados, usar todos os grupos fixos
                $gruposDoBanco = $gruposFixos;
            }
        } elseif ($categoriaId && function_exists('buscarGruposPorCategoria')) {
            // Se não há grupos fixos mas há categoria, buscar grupos por categoria
            $gruposPorCategoria = buscarGruposPorCategoria($categoriaId);
            // Se a loja tem grupos definidos (magalu_grupos_ids), enviar só para os que estão nessa lista E na categoria
            if (trim($magaluGruposIds) !== '') {
                $idsPermitidos = array_flip(array_map('intval', explode(',', $magaluGruposIds)));
                foreach ($gruposPorCategoria as $g) {
                    if (isset($idsPermitidos[$g['id']])) {
                        $gruposDoBanco[] = $g;
                    }
                }
            } else {
                $gruposDoBanco = $gruposPorCategoria;
            }
        }
        
        // Se não encontrou grupos no banco, usar grupos padrão das configurações (compatibilidade)
        // Quando há grupos selecionados na página Magalu (magalu_grupos_ids), não usar o fallback legado para não enviar a grupos não selecionados
        if (empty($gruposDoBanco) && trim($magaluGruposIds) === '' && !empty($grupos)) {
            foreach ($grupos as $g) {
                $gruposDoBanco[] = [
                    'id' => 0,
                    'nome' => 'Grupo Padrão',
                    'grupo_id' => $g,
                    'evolution_conta_id' => 0,
                    'evolution' => [
                        'url_base' => $evUrl,
                        'instancia' => $evInst,
                        'api_key' => $evKey
                    ]
                ];
            }
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

        if ($useWaDispatch) {
            dispatch_executar_whatsapp_destinos(
                $dispatchesTree['whatsapp'],
                'magalu',
                (int) $delay,
                $imgB64,
                function ($idx, $total) use ($mensagemOriginal, $promptUser, $openaiKey, $openaiModel, $openaiPrompt, $linkAfiliado) {
                    if ($total > 1 && $idx > 0) {
                        $errVar = '';
                        $copyVariada = gerarCopyMagaluOpenAI($promptUser, $openaiKey, $openaiModel, $errVar, $openaiPrompt);
                        if (empty($errVar) && !empty($copyVariada)) {
                            return formatarMensagemMagaluWhatsApp($copyVariada, $linkAfiliado);
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
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, 'magalu', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                    continue;
                }
                $evo = $grupoInfo['evolution'];

                if (count($gruposDoBanco) > 1 && $grupoIdx > 0) {
                    $copyVariada = gerarCopyMagaluOpenAI($promptUser, $openaiKey, $openaiModel, $err, $openaiPrompt);
                    if (empty($err) && !empty($copyVariada)) {
                        $mensagem = formatarMensagemMagaluWhatsApp($copyVariada, $linkAfiliado);
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
                        registrarEnvioGrupo($grupoIdDb, 'magalu');
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
                dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagemOriginal, !empty($img) ? $img : null, $errosProduto, $tgB64);
            } else {
                enviarTelegramFluxoLoja('magalu', $mensagemOriginal, !empty($img) ? $img : null, $errosProduto, $tgB64);
            }
        }

        if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
            $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
            $evoStatus = getEvolutionParaStatus($fallback, 'magalu');
            if ($evoStatus) {
                $errSt = '';
                enviarWhatsAppStatusPorConta($evoStatus, $mensagemOriginal, !empty($img) ? $img : null, $errSt);
                if (!empty($errSt) && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                    $errosProduto[] = 'Status: ' . $errSt;
                }
            }
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = count($validos);
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0) {
        $msg = 'Automação Magalu concluída. ' . $enviados . ' mensagem(ns) enviada(s).';
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        }
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

/**
 * Busca produtos na API Lomadee (Magalu) e retorna no formato da automação "Minha Loja".
 * Se $linkLoja estiver preenchido, usa esse link para todos (para comissão na sua loja).
 * Retorna array de ['nome'=>string, 'preco'=>string, 'preco_num'=>float, 'imagem'=>string, 'link'=>string].
 */
function buscarProdutosLomadeeParaMagaluLoja($apiKey, $qtd, $linkLoja, &$err) {
    $err = '';
    $apiKey = trim((string) $apiKey);
    if ($apiKey === '') {
        $err = 'API Key Lomadee não configurada.';
        return [];
    }
    $qtd = max(1, min(20, (int) $qtd));
    $url = LOMADEE_BASE . '/affiliate/products?limit=50&page=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $err = 'Lomadee HTTP ' . $httpCode . (strlen($res) > 0 && strlen($res) < 500 ? ': ' . trim($res) : '');
        return [];
    }
    $json = @json_decode($res, true);
    if (!is_array($json)) {
        $err = 'Lomadee: resposta inválida (não é JSON).';
        return [];
    }
    $lista = [];
    if (isset($json['data']) && is_array($json['data'])) {
        $lista = $json['data'];
    } elseif (isset($json['data']['items']) && is_array($json['data']['items'])) {
        $lista = $json['data']['items'];
    } elseif (isset($json['data']['products']) && is_array($json['data']['products'])) {
        $lista = $json['data']['products'];
    } elseif (isset($json['data']['content']) && is_array($json['data']['content'])) {
        $lista = $json['data']['content'];
    } elseif (isset($json['products']) && is_array($json['products'])) {
        $lista = $json['products'];
    } elseif (isset($json['items']) && is_array($json['items'])) {
        $lista = $json['items'];
    } elseif (isset($json['content']) && is_array($json['content'])) {
        $lista = $json['content'];
    }
    $linkFinal = (trim((string) $linkLoja) !== '') ? rtrim($linkLoja, '/') . '/' : '';
    $out = [];
    $seen = [];
    foreach ($lista as $item) {
        $p = (is_array($item) && isset($item['product'])) ? $item['product'] : $item;
        if (!is_array($p)) continue;
        $nome = trim((string) ($p['name'] ?? $p['title'] ?? $p['productName'] ?? $p['nome'] ?? ''));
        $meta = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];
        $opts = is_array($p['options'] ?? null) ? $p['options'] : [];
        $opt0 = isset($opts[0]) && is_array($opts[0]) ? $opts[0] : [];
        $pricing0 = isset($opt0['pricing'][0]) && is_array($opt0['pricing'][0]) ? $opt0['pricing'][0] : [];
        $precoRaw = $p['price'] ?? $p['currentPrice'] ?? $p['salePrice'] ?? $p['preco'] ?? $p['priceValue']
            ?? ($meta['price'] ?? $meta['currentPrice'] ?? $meta['salePrice'] ?? $meta['minPrice'] ?? $meta['value'] ?? null)
            ?? (is_array($meta['pricing'] ?? null) ? ($meta['pricing']['price'] ?? $meta['pricing']['currentPrice'] ?? null) : null)
            ?? ($opt0['price'] ?? $opt0['currentPrice'] ?? $opt0['value'] ?? $opt0['salePrice'] ?? null)
            ?? ($pricing0['price'] ?? $pricing0['listPrice'] ?? null);
        $preco = $precoRaw !== null && $precoRaw !== '' ? (float) $precoRaw : null;
        $precoAnt = null;
        if (isset($p['oldPrice']) || isset($p['previousPrice'])) {
            $precoAnt = (float) ($p['oldPrice'] ?? $p['previousPrice'] ?? 0);
        } elseif (!empty($pricing0['listPrice']) && (float)$pricing0['listPrice'] > ($preco ?? 0)) {
            $precoAnt = (float) $pricing0['listPrice'];
        }
        $desconto = isset($p['discount']) ? (int) $p['discount'] : null;
        if ($desconto === null && $precoAnt > 0 && $preco > 0 && $precoAnt > $preco) {
            $desconto = (int) round((1 - $preco / $precoAnt) * 100);
        }
        $img = trim((string) ($p['image'] ?? $p['imageUrl'] ?? $p['thumbnail'] ?? $p['imageLink'] ?? $p['img'] ?? $p['picture'] ?? $p['imagem'] ?? ''));
        if ($img === '' && !empty($p['images']) && is_array($p['images']) && isset($p['images'][0])) {
            $firstImg = $p['images'][0];
            $img = is_array($firstImg) ? trim((string) ($firstImg['url'] ?? $firstImg['link'] ?? $firstImg['src'] ?? $firstImg['imageUrl'] ?? $firstImg['path'] ?? $firstImg['fullUrl'] ?? '')) : trim((string) $firstImg);
        }
        if ($img === '' && !empty($opt0['images']) && is_array($opt0['images']) && isset($opt0['images'][0])) {
            $oi = $opt0['images'][0];
            $img = is_array($oi) ? trim((string) ($oi['url'] ?? $oi['link'] ?? $oi['src'] ?? '')) : trim((string) $oi);
        }
        if ($nome === '' || $preco === null || $preco <= 0) continue;
        if ($img === '') $img = 'https://via.placeholder.com/300?text=Produto';
        $urlProd = trim((string) ($p['url'] ?? $p['link'] ?? $p['productUrl'] ?? $p['productLink'] ?? $p['href'] ?? ''));
        if ($urlProd !== '' && strpos($urlProd, 'http') !== 0) {
            $urlProd = (strpos($urlProd, '//') === 0 ? 'https:' : '') . $urlProd;
        }
        $key = $nome . '|' . $preco;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $link = ($urlProd !== '' && strpos($urlProd, 'http') === 0) ? $urlProd : $linkFinal;
        $out[] = [
            'nome'           => $nome,
            'preco'          => 'R$ ' . number_format($preco, 2, ',', '.'),
            'preco_num'      => $preco,
            'preco_anterior' => $precoAnt > 0 ? $precoAnt : null,
            'desconto'       => $desconto !== null ? $desconto : 0,
            'imagem'         => $img,
            'link'           => $link,
        ];
        if (count($out) >= $qtd) break;
    }
    if (empty($out)) {
        $err = 'Lomadee: nenhum produto válido na resposta (nome+preço obrigatórios).';
    }
    return $out;
}

function lomadeeShorten($url, $apiKey, $organizationId, &$err) {
    $err = '';
    if ($organizationId === '') {
        $err = 'Lomadee shorten: organizationId ausente no produto.';
        return '';
    }
    $body = json_encode([
        'url'             => $url,
        'organizationId'  => $organizationId,
        'type'            => 'Custom',
    ]);
    $ch = curl_init(LOMADEE_BASE . '/affiliate/shortener/url');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'Lomadee shorten HTTP ' . $code;
        return '';
    }
    $j = @json_decode($res, true);
    if (!empty($j['type'][0]['shortUrls'][0])) return trim($j['type'][0]['shortUrls'][0]);
    if (isset($j['shortUrl']) && $j['shortUrl'] !== '') return trim($j['shortUrl']);
    if (isset($j['url']) && $j['url'] !== '') return trim($j['url']);
    if (isset($j['data']['shortUrl']) && $j['data']['shortUrl'] !== '') return trim($j['data']['shortUrl']);
    return '';
}

function gerarCopyMagaluOpenAI($promptUser, $apiKey, $model, &$err, $systemPrompt = '') {
    $err = '';
    $defaultSys = '## AGENTE DE COPYWRITING MAGALU - ALTA CONVERSÃO

**<AgentInstructions>**

**<Função>**
  **<Nome>** Especialista em Ofertas Magazine Luiza
  **<Descrição>** Cria textos persuasivos e urgentes para ofertas do Magalu no WhatsApp usando gatilhos mentais poderosos.

**<Meta>**
  **<Objetivo>** Gerar cliques imediatos através de urgência, escassez e formatação visual impactante.

**<TomDeVoz>**
  **<Estilo>** Alarmista positivo, Urgente, Exclusivo, Brasileiro
  **<Características>** Frases curtas, imperativos, transmite que a oportunidade vai acabar.

**<EstruturaDaMensagem>**
  **1.** Título URGENTE (Negrito + 2 Emojis relevantes)
  **2.** Nome do produto (Negrito + Itálico)
  **3.** Bloco de Preço (Antigo riscado com ❌ e Novo em destaque)
  **4.** Percentual de desconto (se houver)
  **5.** Descrição persuasiva (2 linhas em itálico - SEM bullet points)
  **6.** Call-to-action urgente
  **7.** Link de afiliado
  **8.** Footer de fechamento

**<InstruçõesDeFormatação>**

  **<Titulo>**
    Crie título de 3-5 palavras que gere choque/curiosidade.
    Exemplos:
    - **HOJE É O DIA! 🔥💰**
    - **APROVEITA AGORA! 😱⚡**
    - **MAGALU LIBEROU! 🎉🛒**

  **<Produto>**
    Nome completo em **_Negrito e Itálico_**

  **<Precos>**
    OBRIGATÓRIO seguir este layout EXATO:
    ❌ ~R$ [Preço Antigo]~ ❌
    💰 **POR APENAS R$ [Preço Novo]**
    💥 **[X]% DE DESCONTO**

  **<Descricao>**
    - NÃO use listas (✅, •, checkmarks)
    - Escreva 2 linhas em _itálico_
    - Foque no benefício/desejo que o produto resolve

  **<CTA>**
    Use ordem imperativa + emoji de ação:
    - 👉 **CLIQUE AQUI ANTES QUE ACABE:**
    - 🛒 **GARANTA O SEU AGORA:**
    - ⚡ **CORRE QUE ESTÁ ACABANDO:**

  **<Footer>**
    - 🔥 Oferta Exclusiva do Grupo
    - ⏰ Válido Somente Hoje
    - 🎯 Estoque Limitado

**<Restrições_CRÍTICAS>**
  - NUNCA use listas com marcadores na descrição
  - SEMPRE coloque ❌ antes E depois do preço antigo
  - CTA DEVE ser ordem imperativa e urgente
  - Use linguagem brasileira natural
  - INCLUA o link de afiliado no texto (o prompt do usuário contém: nome, preços, link).

**</AgentInstructions>';
    $sys = (trim((string)$systemPrompt) !== '') ? trim($systemPrompt) : $defaultSys;

    $body = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $promptUser],
        ],
        'temperature' => 0.4,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
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

/**
 * Converte markdown da IA para formatação WhatsApp e acrescenta o link de compra no final.
 */
function formatarMensagemMagaluWhatsApp($copy, $linkAfiliado = '') {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $t = preg_replace('/https?:\/\/[^\s]+/u', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/\*(.*?)\*/s', '_$1_', $t);
    $t = preg_replace('/~~(.*?)~~/s', '~$1~', $t);
    $t = trim($t);
    if ($linkAfiliado !== '') {
        $t .= "\n\n#️⃣ *Aproveite enquanto está disponível!*\n\n🔗 " . $linkAfiliado;
    }
    return $t;
}
