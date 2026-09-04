<?php
/**
 * Lógica da automação Mercado Livre
 * Scraping ofertas → createLink (afiliado) → OpenAI (copy) → Evolution (WhatsApp)
 * 
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_ML_LOADED')) {
    define('AUTOMACAO_ML_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Grupos cujo filtro de categoria nas ofertas ML combina com os pools de onde o produto veio.
 *
 * @param array<int, array<string, mixed>> $gruposFixos
 * @param array<int, string>               $poolsProd   '' = página sem category=
 */
function mlFiltraGruposPorPoolsOfertas(array $gruposFixos, array $poolsProd): array {
    return array_values(array_filter($gruposFixos, function ($g) use ($poolsProd) {
        $gCat = mercadolivreNormalizarCategoriaOfertas($g['ml_ofertas_categoria'] ?? '');
        foreach ($poolsProd as $poolProd) {
            $poolProd = (string) $poolProd;
            if ($gCat === '' && $poolProd === '') {
                return true;
            }
            if ($gCat !== '' && strcasecmp($gCat, $poolProd) === 0) {
                return true;
            }
        }
        return false;
    }));
}

function runAutomacaoML($forcarExecucao = false, $apenasGrupoId = null) {
    // #region agent log (sentinela temporária df3052)
    if (function_exists('achadinhos_agent_debug_sentinela')) {
        achadinhos_agent_debug_sentinela('runAutomacaoML');
    }
    // #endregion
    $details = [];
    $errors = [];
    $urlOfertasBase = 'https://www.mercadolivre.com.br/ofertas';

    $schemaMlPath = __DIR__ . '/../core/db/SchemaHelper.php';
    if (is_file($schemaMlPath)) {
        require_once $schemaMlPath;
        if (function_exists('garantirColunaGruposWhatsappMlOfertasCategoria')) {
            garantirColunaGruposWhatsappMlOfertasCategoria();
        }
        if (function_exists('garantirColunaGruposWhatsappPostHoras')) {
            garantirColunaGruposWhatsappPostHoras();
        }
        if (function_exists('garantirColunaGruposWhatsappAutomacaoLoja')) {
            garantirColunaGruposWhatsappAutomacaoLoja();
        }
    }

    // 1) Config e validações
    $ativa   = $forcarExecucao || (getConfig('ml_automacao_ativa', '0') === '1');
    $tag     = getConfig('ml_tag_afiliado', '');
    $csrf    = getConfig('ml_csrf_token', '');
    $cookie  = getConfig('ml_cookie', '');
    // Usar chave OpenAI global, se não houver, usar da loja (compatibilidade)
    $openaiKey = trim(getConfig('openai_api_key', ''));
    if (empty($openaiKey)) {
        $openaiKey = trim(getConfig('ml_openai_api_key', ''));
    }
    $openaiModel = getConfig('ml_openai_model', 'gpt-4.1-mini');
    $openaiPrompt = getConfig('ml_openai_prompt', '');
    $evUrl   = rtrim(getConfig('ml_evolution_url', ''), '/');
    $evInst  = getConfig('ml_evolution_instancia', '');
    $evKey   = getConfig('ml_evolution_apikey', '');
    $evGrupos = getConfig('ml_evolution_grupos', '');
    $contaId = (int) getConfig('ml_evolution_conta_id', '0');
    $gruposIdsConfig = getConfig('ml_grupos_ids', '');
    if ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) {
        $gruposIdsConfig = (string) (int) $apenasGrupoId;
    }
    $qtd     = max(1, min(10, (int)getConfig('ml_produtos_por_execucao', '1')));
    $delay   = max(1, min(120, (int)getConfig('ml_delay_entre_envios', '10')));
    $linkGrupoWhatsApp = trim(getConfig('ml_link_grupo_whatsapp', ''));

    // Grupos selecionados na página Mercado Livre: usar somente esses para envio.
    // Se houver conta selecionada, usa ela para todos; senão, usa a conta de cada grupo (evolution_conta_id).
    $gruposFixos = [];
    if (trim($gruposIdsConfig) !== '') {
        $pdo = getDB();
        $ids = array_values(array_filter(array_map('intval', explode(',', $gruposIdsConfig))));
        if (!empty($ids)) {
            static $mlEvoContaSql = null;
            if ($mlEvoContaSql === null) {
                try {
                    $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
                    $mlEvoContaSql = 'ext_ap';
                } catch (Exception $e) {
                    try {
                        $pdo->query('SELECT provedor, uazapi_admin_token FROM evolution_contas LIMIT 1');
                        $mlEvoContaSql = 'ext';
                    } catch (Exception $e2) {
                        $mlEvoContaSql = 'legacy';
                    }
                }
            }
            $sqlConta = 'SELECT url_base, instancia, api_key FROM evolution_contas WHERE id = ? AND ativo = 1';
            if ($mlEvoContaSql === 'ext_ap') {
                $sqlConta = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token, COALESCE(api_propria, 0) AS api_propria FROM evolution_contas WHERE id = ? AND ativo = 1';
            } elseif ($mlEvoContaSql === 'ext') {
                $sqlConta = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token FROM evolution_contas WHERE id = ? AND ativo = 1';
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtGr = $pdo->prepare(
                "SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, COALESCE(g.ml_ofertas_categoria, '') AS ml_ofertas_categoria, g.post_hora_inicio, g.post_hora_fim " .
                "FROM grupos_whatsapp g WHERE g.id IN ($placeholders) AND g.ativo = 1 AND COALESCE(g.automacao_loja, 'ml') = 'ml'"
            );
            $stmtGr->execute($ids);
            $contaRow = null;
            if ($contaId > 0) {
                $stmtConta = $pdo->prepare($sqlConta);
                $stmtConta->execute([$contaId]);
                $contaRow = $stmtConta->fetch();
            }
            while ($row = $stmtGr->fetch()) {
                $evo = null;
                if ($contaRow) {
                    $evo = [
                        'url_base' => rtrim($contaRow['url_base'], '/'),
                        'instancia' => $contaRow['instancia'],
                        'api_key' => $contaRow['api_key'],
                        'provedor' => ($mlEvoContaSql === 'ext' || $mlEvoContaSql === 'ext_ap') ? ($contaRow['provedor'] ?? 'evolution') : 'evolution',
                        'uazapi_admin_token' => ($mlEvoContaSql === 'ext' || $mlEvoContaSql === 'ext_ap') ? (string) ($contaRow['uazapi_admin_token'] ?? '') : '',
                        'api_propria' => $mlEvoContaSql === 'ext_ap' ? (int) ($contaRow['api_propria'] ?? 0) : 0,
                    ];
                } else {
                    $ecId = (int) $row['evolution_conta_id'];
                    if ($ecId > 0) {
                        $stmtE = $pdo->prepare($sqlConta);
                        $stmtE->execute([$ecId]);
                        $er = $stmtE->fetch();
                        if ($er) {
                            $evo = [
                                'url_base' => rtrim($er['url_base'], '/'),
                                'instancia' => $er['instancia'],
                                'api_key' => $er['api_key'],
                                'provedor' => ($mlEvoContaSql === 'ext' || $mlEvoContaSql === 'ext_ap') ? ($er['provedor'] ?? 'evolution') : 'evolution',
                                'uazapi_admin_token' => ($mlEvoContaSql === 'ext' || $mlEvoContaSql === 'ext_ap') ? (string) ($er['uazapi_admin_token'] ?? '') : '',
                                'api_propria' => $mlEvoContaSql === 'ext_ap' ? (int) ($er['api_propria'] ?? 0) : 0,
                            ];
                        }
                    }
                }
                if ($evo) {
                    $gruposFixos[] = [
                        'id' => (int)$row['id'],
                        'nome' => $row['nome'],
                        'grupo_id' => $row['grupo_id'],
                        'evolution_conta_id' => (int)$row['evolution_conta_id'],
                        'evolution' => $evo,
                        'intervalo_minutos' => isset($row['intervalo_minutos']) ? (int)$row['intervalo_minutos'] : null,
                        'ml_ofertas_categoria' => mercadolivreNormalizarCategoriaOfertas($row['ml_ofertas_categoria'] ?? ''),
                        'post_hora_inicio' => $row['post_hora_inicio'] ?? null,
                        'post_hora_fim' => $row['post_hora_fim'] ?? null,
                    ];
                }
            }
        }
    }
    if (empty($gruposFixos) && trim($gruposIdsConfig) === '' && function_exists('achadinhosMlIdsGruposComContaAtiva')) {
        $idsMlAuto = achadinhosMlIdsGruposComContaAtiva();
        if ($idsMlAuto !== [] && function_exists('getGruposFixosPorLoja')) {
            $gruposFixos = getGruposFixosPorLoja('ml', $idsMlAuto);
        }
    }

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($tag) || empty($csrf) || empty($cookie)) {
        $errors[] = 'Mercado Livre: preencha Tag, x-csrf-token e Cookie.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    $legacyEvGruposPreenchidos = trim(str_replace(["\r", "\n", ","], '', $evGrupos)) !== '';
    $legacyWhatsappCompleto = !empty($evUrl) && !empty($evInst) && !empty($evKey) && $legacyEvGruposPreenchidos;
    $temGruposMlCadastrados = false;
    if (empty($gruposFixos) && !$legacyWhatsappCompleto) {
        try {
            $pdoMl = getDB();
            $stMl = $pdoMl->query(
                "SELECT 1 FROM grupos_whatsapp g " .
                "INNER JOIN evolution_contas e ON g.evolution_conta_id = e.id " .
                "WHERE g.ativo = 1 AND e.ativo = 1 AND COALESCE(g.automacao_loja, 'ml') = 'ml' " .
                "AND TRIM(COALESCE(e.url_base, '')) <> '' AND TRIM(COALESCE(e.api_key, '')) <> '' LIMIT 1"
            );
            $temGruposMlCadastrados = (bool) $stMl->fetch();
        } catch (Exception $e) {
            $temGruposMlCadastrados = false;
        }
    }
    if (empty($gruposFixos) && !$legacyWhatsappCompleto && !$temGruposMlCadastrados) {
        $errors[] = 'WhatsApp (Evolution ou Uazapi): cadastre grupos em Grupos com conta ativa (envio usa a categoria do produto), ou associe grupos à loja no Mercado Livre, ou preencha URL, instância, API Key e IDs dos grupos na configuração legada.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Mercado Livre.', 'details' => $details, 'errors' => $errors];
    }

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));

    // 2) Fetch ofertas ML — um request por "pool" (todas as ofertas vs. ?category=MLB…)
    $poolsNecessarios = [];
    if (!empty($gruposFixos)) {
        foreach ($gruposFixos as $gf) {
            $pc = $gf['ml_ofertas_categoria'] ?? '';
            $poolsNecessarios[$pc === '' ? '' : $pc] = true;
        }
        $poolsNecessarios = array_keys($poolsNecessarios);
    } else {
        $poolsNecessarios = [''];
    }
    $details['ml_pools_ofertas'] = $poolsNecessarios;

    $agregarPorUrl = [];
    $bytesTotal = 0;
    foreach ($poolsNecessarios as $pool) {
        $urlFetch = $urlOfertasBase;
        if ($pool !== '') {
            $urlFetch = $urlOfertasBase . '?category=' . rawurlencode($pool);
        }
        $html = httpGet($urlFetch, $errors);
        if ($html === false || $html === '') {
            $errors[] = 'Não foi possível acessar ofertas ML' . ($pool !== '' ? (' (categoria ' . $pool . ')') : '') . '.';
            continue;
        }
        $bytesTotal += strlen($html);
        $chunk = extrairProdutosOfertas($html);
        foreach ($chunk as $p) {
            $u = trim($p['link_compra'] ?? '');
            if ($u === '') {
                continue;
            }
            $n = preg_match('#^//#', $u) ? 'https:' . $u : (preg_match('#^/#', $u) ? 'https://www.mercadolivre.com.br' . $u : $u);
            if (!isset($agregarPorUrl[$n])) {
                $agregarPorUrl[$n] = ['data' => $p, 'pools' => []];
            }
            if (!in_array($pool, $agregarPorUrl[$n]['pools'], true)) {
                $agregarPorUrl[$n]['pools'][] = $pool;
            }
        }
    }
    $details['ofertas_bytes'] = $bytesTotal;

    $produtos = [];
    foreach ($agregarPorUrl as $n => $row) {
        $item = $row['data'];
        $item['_ml_pools'] = $row['pools'];
        $produtos[] = $item;
    }
    $details['produtos_extraidos'] = count($produtos);
    if (empty($produtos)) {
        return ['success' => false, 'message' => 'Nenhum produto encontrado na página de ofertas (estrutura do HTML pode ter mudado ou falha ao baixar).', 'details' => $details, 'errors' => $errors];
    }

    shuffle($produtos);
    // Pegar bem mais candidatos: muitos são repetidos (já publicados) ou rejeitados pelo ML (111)
    $maxCand = min(count($produtos), max((int)$qtd * 10, 80));
    $produtos = array_slice($produtos, 0, $maxCand);

    $enviados = 0;
    $processados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];
    $details['repetidos_ignorados'] = 0;
    $details['ml_usou_link_sem_afiliado'] = 0;
    $details['ml_createLink_url_invalida_sem_api'] = 0;
    $pdo = getDB();
    foreach ($produtos as $idx => $p) {
        if ($processados >= $qtd) break;

        $linkCompra = trim($p['link_compra'] ?? '');
        $nome       = $p['nome'] ?? '';
        $preco      = $p['preco'] ?? '';
        $imagemUrl  = function_exists('achadinhosNormalizarUrlImagemProduto')
            ? achadinhosNormalizarUrlImagemProduto((string) ($p['imagem'] ?? ''))
            : trim((string) ($p['imagem'] ?? ''));
        if (empty($linkCompra) || empty($nome)) {
            $errosProduto[] = "Produto #" . ($idx+1) . ": sem link ou nome.";
            continue;
        }
        if ($imagemUrl === '') {
            $details['sem_foto_url_ignorados'] = ($details['sem_foto_url_ignorados'] ?? 0) + 1;
            continue;
        }

        // Normalizar URL para checagem (ex.: //... ou /path)
        $linkNorm = $linkCompra;
        if (preg_match('#^//#', $linkNorm)) $linkNorm = 'https:' . $linkNorm;
        elseif (preg_match('#^/#', $linkNorm)) $linkNorm = 'https://www.mercadolivre.com.br' . $linkNorm;

        // 3b) Não repetir o mesmo produto (link normalizado ou bruto, qualquer data)
        $linkChave = normalizarUrlMercadoLivre($linkNorm) ?: $linkNorm;
        $jaPublicado = false;
        try {
            $st = $pdo->prepare('SELECT 1 FROM produtos_ja_publicados WHERE link_origem IN (?, ?) LIMIT 1');
            $st->execute([$linkNorm, $linkChave]);
            $jaPublicado = (bool) $st->fetch();
        } catch (Exception $e) {
            error_log("Erro ao verificar produto repetido: " . $e->getMessage());
        }
        if ($jaPublicado) {
            $details['repetidos_ignorados'] = ($details['repetidos_ignorados'] ?? 0) + 1;
            continue;
        }

        $poolsProd = !empty($p['_ml_pools']) ? $p['_ml_pools'] : [''];
        $gruposDoBanco = [];
        if (!empty($gruposFixos)) {
            $gruposDoBanco = mlFiltraGruposPorPoolsOfertas($gruposFixos, $poolsProd);
            if (empty($gruposDoBanco)) {
                continue;
            }
        }

        // 4) createLink (afiliado) — pausa entre POSTs reais à API em createLinkAfiliadoML (rate limit ML)
        $apiCreateLinkChamada = false;
        $linkAfiliado = createLinkAfiliadoML($linkCompra, $tag, $csrf, $cookie, $err, $apiCreateLinkChamada);
        if (!empty($err)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": " . $err;
            continue;
        }
        if (empty($linkAfiliado)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": createLink não retornou link.";
            continue;
        }
        $normCmp = normalizarUrlMercadoLivre($linkNorm);
        if ($normCmp !== '' && rtrim(strtolower($linkAfiliado), '/') === rtrim(strtolower($normCmp), '/')) {
            $details['ml_usou_link_sem_afiliado'] = ($details['ml_usou_link_sem_afiliado'] ?? 0) + 1;
            if (!$apiCreateLinkChamada) {
                $details['ml_createLink_url_invalida_sem_api'] = ($details['ml_createLink_url_invalida_sem_api'] ?? 0) + 1;
            }
        }

        // 5b) Categoria e destinos WhatsApp (antes de OpenAI/site para não processar produto sem para onde enviar)
        $errCat = '';
        $categoriaId = obterOuCriarCategoriaParaProduto($nome, $errCat, 'ml', $preco);
        if (!empty($errCat)) {
            $errosProduto[] = 'Categoria: ' . $errCat;
        }
        if (empty($gruposFixos)) {
            $gruposDoBanco = [];
            if ($categoriaId) {
                $gruposPorCategoria = buscarGruposPorCategoria($categoriaId);
                $idsPermitidos = trim($gruposIdsConfig) !== '' ? array_flip(array_map('intval', explode(',', $gruposIdsConfig))) : null;
                if ($idsPermitidos !== null) {
                    foreach ($gruposPorCategoria as $g) {
                        if (isset($idsPermitidos[$g['id']])) {
                            $gruposDoBanco[] = $g;
                        }
                    }
                } else {
                    $gruposDoBanco = $gruposPorCategoria;
                }
            }
        }

        if (empty($gruposDoBanco) && trim($gruposIdsConfig) === '' && !empty($grupos)) {
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
                        ],
                        'intervalo_minutos' => null
                    ];
            }
        }

        $dispatchesTreePre = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
            'whatsapp' => [],
            'telegram' => [],
        ];
        $useWaDispatchPre = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTreePre['whatsapp']);
        $dexpPre = ($useWaDispatchPre && function_exists('dispatch_expandir_linhas_por_grupo_prioridade'))
            ? dispatch_expandir_linhas_por_grupo_prioridade($dispatchesTreePre['whatsapp'])
            : [];
        $nDestWaPre = count($dexpPre);

        $origemGruposMl = !empty($gruposFixos) ? 'gruposFixos_filtrados_pools' : 'categoria_ou_config_legada';
        if (!empty($gruposFixos) && empty($gruposDoBanco)) {
            $origemGruposMl = 'gruposFixos_vazio_apos_filtro_pools';
        }

        if (function_exists('achadinhos_agent_debug_ndjson')) {
            $resumoG = [];
            foreach (array_slice($gruposDoBanco, 0, 8) as $gx) {
                $resumoG[] = [
                    'id' => (int) ($gx['id'] ?? 0),
                    'nome' => mb_substr((string) ($gx['nome'] ?? ''), 0, 40),
                ];
            }
            achadinhos_agent_debug_ndjson(
                'automacao-ml.php:destinos_whatsapp',
                'ML destinos antes envio',
                [
                    'origem_grupos' => $origemGruposMl,
                    'gruposDoBanco_count' => count($gruposDoBanco),
                    'useWaDispatch' => $useWaDispatchPre,
                    'dispatch_destinos_count' => $nDestWaPre,
                    'resumo_grupos' => $resumoG,
                    'motivo_zero' => (!$useWaDispatchPre && count($gruposDoBanco) === 0)
                        ? 'sem_grupos_banco_e_dispatch_off'
                        : (($useWaDispatchPre && $nDestWaPre === 0) ? 'dispatch_sem_linhas' : ''),
                ],
                'ML-DEST'
            );
        }

        if (!$useWaDispatchPre && empty($gruposDoBanco)) {
            $errosProduto[] = 'WhatsApp: nenhum grupo de destino. Com dispatch desligado, cadastre grupos (Admin > Grupos, loja ML), selecione-os na página Mercado Livre ou use a configuração legada de IDs; com dispatch ligado, configure destinos em Dispatches.';

            continue;
        }
        if ($useWaDispatchPre && $nDestWaPre === 0) {
            $errosProduto[] = 'WhatsApp: dispatch ativo mas sem destinos (nenhuma linha em dispatches para WhatsApp).';

            continue;
        }

        // 5) Foto para WhatsApp: download com Referer/CDN ML; se falhar, envia só texto (OpenAI não usa imagem)
        $imgB64 = baixarEConverterImagemBase64($imagemUrl, $linkNorm);
        $fotoOkWa = ($imgB64 !== null && $imgB64 !== '');
        if (!$fotoOkWa) {
            $details['ml_whatsapp_sem_foto_fallback'] = ($details['ml_whatsapp_sem_foto_fallback'] ?? 0) + 1;
            if (getConfig('ml_whatsapp_exigir_foto', '1') === '1') {
                $errosProduto[] = 'WhatsApp: envio cancelado — imagem obrigatória e download falhou ou URL inválida (Mercado Livre → «Exigir foto no envio»).';

                continue;
            }
            $imgB64 = '';
        }

        // 6) OpenAI
        $copy = gerarCopyOpenAI($nome, $preco, $linkAfiliado, $openaiKey, $openaiModel, $err, $openaiPrompt, $linkGrupoWhatsApp);
        if (!empty($err)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": " . $err;
            continue;
        }
        $mensagem = formatarMensagemWhatsApp($copy, $linkAfiliado, true, $linkGrupoWhatsApp);

        // 6c) Publicar no site de ofertas (imagem, nome, link de afiliado)
        $publicarSite = getConfig('ml_site_publicar', '1') === '1';
        if ($publicarSite) {
            $id = salvarProdutoNoSite($nome, $preco, $linkAfiliado, $imagemUrl, $errProd, null, null, null, 'ml', null, null, $categoriaId, $fotoOkWa);
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
            } elseif (!empty($errProd)) {
                $errosProduto[] = "Site: " . $errProd;
            }
        }

        // 7) Evolution / dispatch (gruposDoBanco já montado em 5b)
        // Enviar para cada grupo (uma oferta diferente por grupo se houver múltiplos)
        // Respeita intervalo por grupo salvo execução forçada (Executar agora / forcar no cron)
        $mensagemOriginal = $mensagem;
        $dispatchesTree = $dispatchesTreePre;
        $useWaDispatch = $useWaDispatchPre;
        $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);
        $evoFallbackStatus = !empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

        if ($useWaDispatch) {
            dispatch_executar_whatsapp_destinos(
                $dispatchesTree['whatsapp'],
                'ml',
                (int) $delay,
                $imgB64,
                function ($idx, $total) use ($mensagemOriginal, $nome, $preco, $linkAfiliado, $openaiKey, $openaiModel, $openaiPrompt, $linkGrupoWhatsApp) {
                    if ($total > 1 && $idx > 0) {
                        $errVar = '';
                        $copyVariada = gerarCopyOpenAI($nome, $preco, $linkAfiliado, $openaiKey, $openaiModel, $errVar, $openaiPrompt, $linkGrupoWhatsApp);
                        if (empty($errVar) && !empty($copyVariada)) {
                            return formatarMensagemWhatsApp($copyVariada, $linkAfiliado, true, $linkGrupoWhatsApp);
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
                $grupoIdEvo = $grupoInfo['grupo_id'];
                $grupoIdDb = (int)($grupoInfo['id'] ?? 0);
                if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
                    continue;
                }
                if (!$forcarExecucao && $grupoIdDb > 0 && !grupoPodeReceberEnvio($grupoIdDb, 'ml', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                    continue; // intervalo não passou, pula este grupo
                }
                $evo = $grupoInfo['evolution'];

                // Se múltiplos grupos, regenerar copy para cada um (variação)
                if (count($gruposDoBanco) > 1 && $grupoIdx > 0) {
                    $copyVariada = gerarCopyOpenAI($nome, $preco, $linkAfiliado, $openaiKey, $openaiModel, $err, $openaiPrompt, $linkGrupoWhatsApp);
                    if (empty($err) && !empty($copyVariada)) {
                        $mensagem = formatarMensagemWhatsApp($copyVariada, $linkAfiliado, true, $linkGrupoWhatsApp);
                    } else {
                        $mensagem = $mensagemOriginal; // Fallback para mensagem original
                    }
                } else {
                    $mensagem = $mensagemOriginal;
                }

                $ok = enviarWhatsAppMensagem($evo, $grupoIdEvo, $mensagem, $imgB64, $err);

                if ($ok) {
                    $enviados++;
                    if ($grupoIdDb > 0) {
                        registrarEnvioGrupo($grupoIdDb, 'ml');
                    }
                } else {
                    $errosProduto[] = "WhatsApp grupo " . ($grupoInfo['nome'] ?? $grupoIdEvo) . ": " . $err;
                }
                if (count($gruposDoBanco) > 1 && $grupoIdx < count($gruposDoBanco) - 1) {
                    sleep((int)$delay);
                }
            }
        }

        // #region agent log
        static $achadinhosDbgMlWaOnce = false;
        if (!$achadinhosDbgMlWaOnce && function_exists('achadinhos_agent_debug_ndjson')) {
            $achadinhosDbgMlWaOnce = true;
            achadinhos_agent_debug_ndjson(
                'automacao-ml.php:apos_bloco_whatsapp',
                'ML após envio WA',
                [
                    'useWaDispatch' => $useWaDispatch,
                    'dispatch_habilitado' => function_exists('dispatch_habilitado') && dispatch_habilitado(),
                    'gruposDoBanco_count' => count($gruposDoBanco),
                    'dispatch_destinos_count' => count($dexpPre),
                    'mensagemOriginal_len' => strlen($mensagemOriginal),
                    'imgB64_len' => strlen((string) $imgB64),
                    'enviados_acum' => $enviados,
                ],
                'ML-B'
            );
        }
        // #endregion

        // Telegram: mesma imagem do WA (base64) evita falha sendPhoto por URL bloqueada no servidor do Telegram
        $tgB64 = ($imgB64 !== null && $imgB64 !== '') ? (string) $imgB64 : null;
        if (function_exists('enviarTelegram')) {
            if ($useTgDispatch) {
                dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagemOriginal, $imagemUrl ?? null, $errosProduto, $tgB64);
            } else {
                enviarTelegramFluxoLoja('ml', $mensagemOriginal, $imagemUrl ?? null, $errosProduto, $tgB64);
            }
        }

        // Status do WhatsApp (se configurado)
        if (function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
            $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
            $evoStatus = getEvolutionParaStatus($fallback, 'ml');
            if ($evoStatus) {
                $errSt = '';
                enviarWhatsAppStatusPorConta($evoStatus, $mensagemOriginal, $imagemUrl ?? null, $errSt);
                if (!empty($errSt) && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                    if (!isset($details['whatsapp_status_erros']) || !is_array($details['whatsapp_status_erros'])) {
                        $details['whatsapp_status_erros'] = [];
                    }
                    $details['whatsapp_status_erros'][] = $errSt;
                }
            }
        }

        // Registrar como já publicado para não repetir no site nem no WhatsApp
        try {
            $ins = $pdo->prepare("INSERT IGNORE INTO produtos_ja_publicados (link_origem) VALUES (?)");
            $ins->execute([$linkChave]);
        } catch (Exception $e) { /* tabela pode não existir; seguir */ }
        $processados++;
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = $processados;
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0) {
        $msg = 'Automação concluída. ' . $enviados . ' mensagem(ns) enviada(s).';
        if ($nSite > 0) $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    $msg = 'Nenhuma mensagem enviada. Verifique as configurações e os erros.';
    if (!empty($errors)) {
        $msg .= ' Exemplo: ' . $errors[0];
    }
    return ['success' => false, 'message' => $msg, 'details' => $details, 'errors' => $errors];
}

function httpGet($url, &$errors, $headers = []) {
    $ch = curl_init($url);
    if (!$ch) return false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/xhtml+xml'], $headers),
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errNo) {
        $errors[] = 'cURL: ' . $errNo;
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $errors[] = 'HTTP ' . $httpCode;
        return false;
    }
    return $body;
}

function extrairProdutosOfertas($html) {
    $out = [];
    $dom = @new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    // Card: .poly-card ou similar; dentro: .poly-card__content (nome, preço, link) e .poly-card__portada (img)
    $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-card__content ')]");
    if ($cards->length === 0) {
        $cards = $xpath->query("//*[contains(@class,'poly-card__content')]");
    }
    if ($cards->length === 0) {
        $cards = $xpath->query("//*[contains(@class,'andes-card')]");
    }
    for ($i = 0; $i < $cards->length; $i++) {
        $content = $cards->item($i);
        $card = $content->parentNode;
        $a = $xpath->query(".//h3//a | .//a[.//h3] | .//h2//a | .//a[.//h2]", $content)->item(0);
        $nome = $a ? trim($a->textContent) : '';
        $href = $a ? trim($a->getAttribute('href') ?: '') : '';
        if (empty($href) && $a) {
            $href = trim($a->getAttribute('href') ?: '');
        }
        $priceNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-component__price ')] | .//*[contains(@class,'price')] | .//*[contains(@class,'andes-money-amount')]", $content)->item(0);
        $preco = $priceNode ? trim($priceNode->textContent) : '';
        $imgUrl = '';
        $portadaImgs = $card
            ? $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-card__portada ')]//img | .//*[contains(@class,'poly-card__portada')]//img", $card)
            : null;
        if ($portadaImgs !== null && $portadaImgs->length > 0) {
            $pi = $portadaImgs->item(0);
            if ($pi) {
                $imgUrl = trim($pi->getAttribute('data-src') ?: $pi->getAttribute('src') ?: '');
                if ($imgUrl === '' && function_exists('achadinhosExtrairPrimeiraUrlSrcset')) {
                    $imgUrl = achadinhosExtrairPrimeiraUrlSrcset($pi->getAttribute('srcset') ?: '');
                }
            }
        }
        $img = null;
        if ($imgUrl === '') {
            $imgs = $xpath->query(".//img[@data-src or @src or @srcset]", $content);
            if ($imgs->length === 0 && $card) {
                $imgs = $xpath->query(".//img[@data-src or @src or @srcset]", $card);
            }
            $img = $imgs->length ? $imgs->item(0) : null;
            if ($img) {
                $imgUrl = trim($img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src') ?: '');
                if ($imgUrl === '' && function_exists('achadinhosExtrairPrimeiraUrlSrcset')) {
                    $imgUrl = achadinhosExtrairPrimeiraUrlSrcset($img->getAttribute('srcset') ?: '');
                }
            }
        }
        if (empty($nome) && empty($href)) continue;
        $out[] = ['nome' => $nome, 'preco' => $preco, 'link_compra' => $href, 'imagem' => $imgUrl];
    }
    return $out;
}

function createLinkAfiliadoML_acharLink($arr, $urlEnv, $urlEnvNorm) {
    if (is_string($arr) && preg_match('#^https?://#', $arr)) {
        $norm = strtolower(rtrim(preg_replace('#[#?].*$#', '', $arr), '/'));
        if ($norm === $urlEnvNorm) {
            return null;
        }
        // meli.la é curto: não passava no critério strlen>mclics e era descartado quando vinha só no JSON aninhado.
        if (function_exists('mlUrlEMeliLa') && mlUrlEMeliLa($arr)) {
            return $arr;
        }
        if (strpos($arr, 'click') !== false || strpos($arr, 'mclics') !== false || strlen($arr) > strlen($urlEnv) + 20) {
            return $arr;
        }

        return null;
    }
    if (is_array($arr)) {
        foreach ($arr as $v) {
            $r = createLinkAfiliadoML_acharLink($v, $urlEnv, $urlEnvNorm);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

/**
 * Campos comuns na resposta createLink (v2) — ordem: curtos / afiliado primeiro.
 *
 * @param array<string, mixed> $row
 */
function createLinkAfiliadoML_extrairLinkDoItemUrl(array $row, string $urlEnviada, string $urlEnvNorm): ?string {
    $keys = [
        'short_url', 'shortUrl', 'short_link', 'shortLink', 'mobile_short_url',
        'affiliate_url', 'affiliate_link', 'destination_url', 'converted_url', 'redirect_url',
        'generated_link', 'tracking_url', 'smart_link', 'final_url', 'long_url',
        'deeplink', 'mobile_deeplink',
        'link', 'url',
    ];
    foreach ($keys as $k) {
        if (empty($row[$k]) || !is_string($row[$k])) {
            continue;
        }
        $c = trim($row[$k]);
        if ($c !== '' && preg_match('#^https?://#i', $c)) {
            return $c;
        }
    }
    return createLinkAfiliadoML_acharLink($row, $urlEnviada, $urlEnvNorm);
}

/**
 * Coleta todas as strings https da resposta JSON do createLink (para não ficar preso a um único campo).
 *
 * @param mixed $data
 * @return list<string>
 */
function mlColetarUrlsHttpsDoJson($data): array {
    $out = [];
    if (is_string($data)) {
        $t = trim($data);
        if ($t !== '' && preg_match('#^https?://#i', $t)) {
            $out[] = $t;
        }
        return $out;
    }
    if (!is_array($data)) {
        return $out;
    }
    foreach ($data as $v) {
        foreach (mlColetarUrlsHttpsDoJson($v) as $u) {
            $out[] = $u;
        }
    }
    return $out;
}

/**
 * Link curto oficial de afiliados ML (ex.: https://meli.la/1GtoEhc).
 */
function mlUrlEMeliLa(string $u): bool {
    $u = trim($u);

    return (bool) preg_match('#^https?://([a-z0-9-]+\.)?meli\.la/[^\s]+#i', $u)
        || (bool) preg_match('#^https?://([a-z0-9-]+\.)?mercadoliv\.re/[^\s]+#i', $u);
}

/**
 * Só aceita link encurtado oficial de afiliados (meli.la / mercadoliv.re).
 * Não devolve URL longa do produto nem tracking mclics — o envio exige link curto.
 *
 * @param mixed $linkExtraido Valor já extraído da resposta (string ou null)
 */
function mlEscolherMelhorLinkCreateLinkResponse(array $j, $linkExtraido, string $urlOriginal = ''): string {
    $cands = array_unique(mlColetarUrlsHttpsDoJson($j));
    if ($linkExtraido !== null && $linkExtraido !== '') {
        $cands[] = trim((string) $linkExtraido);
    }
    $cands = array_values(array_unique(array_filter(array_map('trim', $cands))));
    $originalNorm = strtolower(rtrim((string) preg_replace('~[?#].*$~', '', trim($urlOriginal)), '/'));
    $meli = array_values(array_filter($cands, static function (string $u) use ($originalNorm): bool {
        if (!mlUrlEMeliLa($u)) {
            return false;
        }
        $candidateNorm = strtolower(rtrim((string) preg_replace('~[?#].*$~', '', trim($u)), '/'));
        // A API costuma repetir a URL de entrada no JSON. Ela nunca pode ser
        // tratada como resultado, pois pertence ao afiliado de origem.
        return $originalNorm === '' || $candidateNorm !== $originalNorm;
    }));
    if ($meli === []) {
        return '';
    }
    usort($meli, static function (string $a, string $b): int {
        return strlen($a) <=> strlen($b);
    });

    return $meli[0];
}

function mlRegistrarLogCreateLinkControlado(string $urlSemQuery, $errorCode): void
{
    $entry = [
        'ts' => gmdate('c'),
        'url' => $urlSemQuery,
        'error_code' => $errorCode,
    ];
    error_log('ML createLink controlado: ' . json_encode($entry, JSON_UNESCAPED_UNICODE));
    if (function_exists('setConfig')) {
        try {
            setConfig('ml_createlink_last_controlled', json_encode($entry, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }
    }
}

function createLinkAfiliadoML($url, $tag, $csrf, $cookie, &$err, &$apiFoiChamada = null) {
    if ($apiFoiChamada !== null) {
        $apiFoiChamada = false;
    }
    $err = '';

    $abs = trim((string) $url);
    if ($abs === '') {
        $err = 'createLink: URL vazia.';
        return '';
    }
    if (preg_match('#^//#', $abs)) {
        $abs = 'https:' . $abs;
    } elseif (preg_match('#^/#', $abs)) {
        $abs = 'https://www.mercadolivre.com.br' . $abs;
    }

    $fallback = normalizarUrlMercadoLivre($abs);
    if ($fallback === '') {
        $err = 'createLink: URL inválida após normalização.';
        return '';
    }

    // O Link Builder também recebe links curtos oficiais já compartilhados por
    // outros afiliados. A API resolve o destino e gera um novo meli.la para a
    // tag autenticada; não devemos expandir o curto antes desta tentativa.
    $entradaCurtaOficial = function_exists('mlUrlEMeliLa') && mlUrlEMeliLa($fallback);
    if (!urlMercadoLivrePermitidaParaCreateLink($fallback) && !$entradaCurtaOficial) {
        mlRegistrarLogCreateLinkControlado($fallback, 'invalid_product_path');
        $err = 'createLink: URL do produto não é aceita pelo Link Builder (formato de oferta). Não será enviado link sem meli.la.';
        return '';
    }

    if ($tag === '' || $csrf === '' || $cookie === '') {
        $err = 'createLink: Tag, CSRF ou Cookie ausentes.';
        return '';
    }

    static $lastMlCreateLinkMicro = 0.0;
    if ($lastMlCreateLinkMicro > 0) {
        $elapsed = microtime(true) - $lastMlCreateLinkMicro;
        if ($elapsed < 2.0) {
            usleep((int) round((2.0 - $elapsed) * 1e6));
        }
    }

    if ($apiFoiChamada !== null) {
        $apiFoiChamada = true;
    }

    $api = 'https://www.mercadolivre.com.br/affiliate-program/api/v2/affiliates/createLink';
    $body = json_encode(['urls' => [$fallback], 'tag' => $tag]);
    $h = [
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Origin: https://www.mercadolivre.com.br',
        'Referer: https://www.mercadolivre.com.br/afiliados/linkbuilder',
        'x-csrf-token: ' . $csrf,
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Cookie: ' . $cookie,
    ];
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $lastMlCreateLinkMicro = microtime(true);

    if ($code < 200 || $code >= 300) {
        $err = 'createLink HTTP ' . $code . '. Atualize o x-csrf-token e o Cookie na página Mercado Livre (eles expiram).';
        return '';
    }
    $j = @json_decode($res, true);
    if (!$j) {
        $err = 'createLink: resposta da API não é JSON. Atualize o x-csrf-token e o Cookie na página Mercado Livre.';
        return '';
    }

    $ecRoot = $j['error_code'] ?? null;
    if ((int) $ecRoot === 111) {
        mlRegistrarLogCreateLinkControlado($fallback, 111);
        $err = 'createLink: URL não permitida pelo programa de afiliados (erro 111).';
        return '';
    }

    $urlEnviada = rtrim($fallback, '/');
    $urlEnviadaNorm = strtolower($urlEnviada);

    $first = $j['urls'][0] ?? null;
    if (is_array($first)) {
        $ecItem = $first['error_code'] ?? null;
        if ((int) $ecItem === 111 || $ecItem === '111') {
            mlRegistrarLogCreateLinkControlado($fallback, 111);
            $err = 'createLink: URL não permitida pelo programa de afiliados (erro 111).';
            return '';
        }
    }

    $link = null;
    if (!empty($first)) {
        if (is_string($first) && preg_match('#^https?://#', $first)) {
            if (strtolower(rtrim(preg_replace('#[#?].*$#', '', $first), '/')) !== $urlEnviadaNorm) {
                $link = $first;
            }
        } elseif (is_array($first)) {
            $link = createLinkAfiliadoML_extrairLinkDoItemUrl($first, $urlEnviada, $urlEnviadaNorm);
        }
    }
    if (($link === null || $link === '') && !empty($j['short_url'])) {
        $link = $j['short_url'];
    }
    if (($link === null || $link === '') && !empty($j['link'])) {
        $link = $j['link'];
    }
    if (($link === null || $link === '') && isset($j['data'])) {
        if (is_string($j['data'])) {
            $link = trim($j['data']);
        } elseif (is_array($j['data'])) {
            $link = createLinkAfiliadoML_extrairLinkDoItemUrl($j['data'], $urlEnviada, $urlEnviadaNorm);
        }
    }
    if ($link === null || $link === '') {
        $link = createLinkAfiliadoML_acharLink($j, $urlEnviada, $urlEnviadaNorm);
    }

    $linkFinal = mlEscolherMelhorLinkCreateLinkResponse($j, $link, $fallback);
    if ($linkFinal !== '') {
        return trim($linkFinal);
    }
    if (is_array($first)) {
        $eciLate = $first['error_code'] ?? null;
        if ((int) $eciLate === 111 || $eciLate === '111') {
            mlRegistrarLogCreateLinkControlado($fallback, 111);
            $err = 'createLink: URL não permitida pelo programa de afiliados (erro 111).';
            return '';
        }
        $msgF = (string) ($first['message'] ?? '');
        if ($msgF !== '' && stripos($msgF, 'URL not allowed') !== false) {
            mlRegistrarLogCreateLinkControlado($fallback, 111);
            $err = 'createLink: URL não permitida pelo programa de afiliados.';
            return '';
        }
    }
    if (function_exists('setConfig')) {
        try {
            setConfig('ml_createlink_last_response', mb_substr($res, 0, 2000));
        } catch (Throwable $e) {
            // ignorar falha ao salvar debug
        }
    }
    $msgItem = is_array($first) ? ($first['message'] ?? $first['error'] ?? '') : '';
    $codeItem = is_array($first) ? ($first['error_code'] ?? $first['status'] ?? '') : '';
    $statusItem = is_array($first) ? ($first['status'] ?? '') : '';
    $err = 'createLink: a API não devolveu link encurtado (meli.la / mercadoliv.re) para este produto.';
    if ($msgItem !== '') {
        $err .= ' Resposta da API: ' . (is_string($msgItem) ? $msgItem : json_encode($msgItem));
    }
    if ($codeItem !== '') {
        $err .= ' (código: ' . (is_string($codeItem) ? $codeItem : json_encode($codeItem)) . ')';
    }
    if ($statusItem !== '' && $statusItem !== 'success') {
        $err .= ' Status: ' . (is_string($statusItem) ? $statusItem : json_encode($statusItem));
    }
    $err .= '. Atualize o x-csrf-token e o Cookie na página Mercado Livre (Link Builder) e tente de novo.';
    return '';
}

function gerarCopyOpenAI($nome, $preco, $link, $apiKey, $model, &$err, $systemPrompt = '', $linkGrupoWhatsApp = '') {
    $err = '';
    $defaultSys = "Você é um especialista em copy para promoções no WhatsApp (Mercado Livre/outlet). Crie mensagens curtas (máx. 12 linhas), com gancho, nome em *negrito*, preço (~~antigo~~ → *atual*), % de desconto em *negrito*, 3 benefícios com ✅, CTA em *negrito*. Use formatação WhatsApp: *texto* e ~~riscado~~. Emojis com moderação. Nunca invente parcelamento; omita se não tiver certeza. Foco em conversão.";
    $sys = (trim((string)$systemPrompt) !== '') ? trim($systemPrompt) : $defaultSys;
    $user = "Produto: {$nome}. Preço: {$preco}. Link de afiliado do produto (NÃO inclua na sua resposta, será adicionado depois): {$link}.";
    if (trim((string)$linkGrupoWhatsApp) !== '') {
        $user .= " Link do grupo WhatsApp para as pessoas entrarem (OBRIGATÓRIO incluir na mensagem, na última linha): " . trim($linkGrupoWhatsApp);
    }
    $user .= " Gere apenas o corpo da mensagem.";
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
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI HTTP ' . $code . '. Verifique a chave e o modelo.';
        return '';
    }
    $j = @json_decode($res, true);
    $txt = $j['choices'][0]['message']['content'] ?? '';
    if (trim($txt) === '') {
        $err = 'OpenAI respondeu vazio.';
        return '';
    }
    return $txt;
}

/**
 * @param string $copy Texto da copy
 * @param string $linkAfiliado Link do produto (afiliado)
 * @param bool $incluirLink Se false, não adiciona o bloco "Aproveite..." + link (use quando enviar com botão)
 * @param string $linkGrupoWhatsApp Link do grupo (preservado na mensagem; não é removido ao limpar URLs)
 */
function formatarMensagemWhatsApp($copy, $linkAfiliado, $incluirLink = true, $linkGrupoWhatsApp = '') {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $placeholder = '{{LINK_GRUPO_WHATSAPP}}';
    if (trim((string)$linkGrupoWhatsApp) !== '') {
        $t = str_replace($linkGrupoWhatsApp, $placeholder, $t);
    }
    $t = preg_replace('/https?:\/\/[^\s]+/u', '', $t);
    if (trim((string)$linkGrupoWhatsApp) !== '') {
        $t = str_replace($placeholder, trim($linkGrupoWhatsApp), $t);
    }
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/~~(.*?)~~/s', '~$1~', $t);
    $t = trim($t);
    if ($incluirLink && $linkAfiliado !== '') {
        $t .= "\n\n#️⃣ *Aproveite enquanto está disponível!*\n\n🔗 " . $linkAfiliado;
    }
    return $t;
}

/**
 * @param string $url URL da imagem
 * @param string $refererPaginaProduto ex.: link do item no ML (melhora aceitação no CDN)
 */
function baixarEConverterImagemBase64($url, $refererPaginaProduto = '') {
    $url = function_exists('achadinhosNormalizarUrlImagemProduto') ? achadinhosNormalizarUrlImagemProduto((string) $url) : trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (function_exists('achadinhosBaixarImagemUrlComoJpegBase64')) {
        return achadinhosBaixarImagemUrlComoJpegBase64($url, (string) $refererPaginaProduto);
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 28,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 10) {
        return null;
    }
    $img = @imagecreatefromstring($data);
    if ($img) {
        ob_start();
        @imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        @imagedestroy($img);
        if ($jpeg !== false && strlen($jpeg) > 0) {
            return base64_encode($jpeg);
        }
    }

    return null;
}

/**
 * Busca grupos WhatsApp do banco de dados que estão associados à categoria do produto
 * Retorna array de arrays: [['id'=>int, 'nome'=>string, 'grupo_id'=>string, 'evolution_conta_id'=>int, 'evolution'=>array], ...]
 */
function buscarGruposPorCategoria($categoriaId) {
    if (!$categoriaId) {
        return [];
    }
    $categoriaId = (int) $categoriaId;

    try {
        $schemaPath = __DIR__ . '/../core/db/SchemaHelper.php';
        if (is_file($schemaPath)) {
            require_once $schemaPath;
            if (function_exists('garantirColunaGruposWhatsappAutomacaoLoja')) {
                garantirColunaGruposWhatsappAutomacaoLoja();
            }
        }
        $pdo = getDB();
        static $buscarGruposCatSql = null;
        if ($buscarGruposCatSql === null) {
            try {
                $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
                $buscarGruposCatSql = 'ext_ap';
            } catch (Exception $e) {
                try {
                    $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
                    $buscarGruposCatSql = 'ext';
                } catch (Exception $e2) {
                    $buscarGruposCatSql = 'legacy';
                }
            }
        }
        $sql = $buscarGruposCatSql === 'ext_ap'
            ? "
            SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, g.post_hora_inicio, g.post_hora_fim, e.url_base, e.instancia, e.api_key,
                   COALESCE(e.provedor, 'evolution') AS provedor, e.uazapi_admin_token, COALESCE(e.api_propria, 0) AS api_propria,
                   gc.categoria_id AS grupo_categoria_id
            FROM grupos_whatsapp g
            INNER JOIN grupos_categorias gc ON g.id = gc.grupo_id
            LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
            WHERE g.ativo = 1 AND (e.ativo = 1 OR e.ativo IS NULL) AND COALESCE(g.automacao_loja, 'ml') = 'ml'
            ORDER BY g.nome
        "
            : ($buscarGruposCatSql === 'ext'
            ? "
            SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, g.post_hora_inicio, g.post_hora_fim, e.url_base, e.instancia, e.api_key,
                   COALESCE(e.provedor, 'evolution') AS provedor, e.uazapi_admin_token,
                   gc.categoria_id AS grupo_categoria_id
            FROM grupos_whatsapp g
            INNER JOIN grupos_categorias gc ON g.id = gc.grupo_id
            LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
            WHERE g.ativo = 1 AND (e.ativo = 1 OR e.ativo IS NULL) AND COALESCE(g.automacao_loja, 'ml') = 'ml'
            ORDER BY g.nome
        "
            : "
            SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, g.post_hora_inicio, g.post_hora_fim, e.url_base, e.instancia, e.api_key,
                   gc.categoria_id AS grupo_categoria_id
            FROM grupos_whatsapp g
            INNER JOIN grupos_categorias gc ON g.id = gc.grupo_id
            LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
            WHERE g.ativo = 1 AND (e.ativo = 1 OR e.ativo IS NULL) AND COALESCE(g.automacao_loja, 'ml') = 'ml'
            ORDER BY g.nome
        "
        );
        $stmt = $pdo->query($sql);
        $grupos = [];
        $seen = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $gid = (int) $row['id'];
            $gcCat = (int) ($row['grupo_categoria_id'] ?? 0);
            $match = $gcCat === $categoriaId;
            if (!$match && function_exists('achadinhosCategoriaEDescendenteDe')) {
                $match = achadinhosCategoriaEDescendenteDe($pdo, $categoriaId, $gcCat);
            }
            if (!$match) {
                continue;
            }
            if (isset($seen[$gid])) {
                continue;
            }
            $seen[$gid] = true;
            $grupos[] = [
                'id' => $gid,
                'nome' => $row['nome'],
                'grupo_id' => $row['grupo_id'],
                'evolution_conta_id' => (int) $row['evolution_conta_id'],
                'evolution' => [
                    'url_base' => $row['url_base'],
                    'instancia' => $row['instancia'],
                    'api_key' => $row['api_key'],
                    'provedor' => $row['provedor'] ?? 'evolution',
                    'uazapi_admin_token' => (string) ($row['uazapi_admin_token'] ?? ''),
                    'api_propria' => (int) ($row['api_propria'] ?? 0),
                ],
                'intervalo_minutos' => isset($row['intervalo_minutos']) ? (int) $row['intervalo_minutos'] : null,
                'post_hora_inicio' => $row['post_hora_inicio'] ?? null,
                'post_hora_fim' => $row['post_hora_fim'] ?? null,
            ];
        }

        return $grupos;
    } catch (Exception $e) {
        error_log('Erro ao buscar grupos por categoria: ' . $e->getMessage());

        return [];
    }
}

/**
 * Evolution exige JID completo: grupos @g.us, contatos @s.whatsapp.net.
 */
function achadinhosEvolutionNormalizarJidWhatsApp(string $number): string {
    $n = trim(str_replace(["\0", "\r", "\n"], '', $number));
    if ($n === '') {
        return '';
    }
    if (strpos($n, '@') !== false) {
        return $n;
    }
    $digits = preg_replace('/\D+/', '', $n);
    if ($digits === '') {
        return $n;
    }
    if (strlen($digits) >= 15) {
        return $digits . '@g.us';
    }

    return $digits . '@s.whatsapp.net';
}

function achadinhosEvolutionStripMediaDataUriPrefix(string $b64): string {
    $b64 = trim($b64);
    if ($b64 !== '' && preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $b64)) {
        return (string) preg_replace('#^data:image/[a-z0-9.+-]+;base64,#i', '', $b64);
    }

    return $b64;
}

function enviarWhatsAppEvolution($baseUrl, $inst, $apiKey, $number, $caption, $mediaBase64, &$err) {
    $err = '';
    $base = rtrim((string) $baseUrl, '/');
    $inst = trim((string) $inst);
    $apiKey = trim((string) $apiKey);
    $headers = ['Content-Type: application/json', 'apikey: ' . $apiKey];
    $number = achadinhosEvolutionNormalizarJidWhatsApp((string) $number);
    if ($number === '') {
        $err = 'Evolution: destino (número/JID) vazio';

        return false;
    }
    $caption = trim((string) $caption);
    if ($caption === '') {
        $caption = '.';
    }

    $hasMedia = $mediaBase64 !== null && trim((string) $mediaBase64) !== '';
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    $postEvolution = static function (string $url, array $body, array $headers, int $timeout) use ($jsonFlags): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, $jsonFlags),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [(string) $res, $code];
    };

    $interpretarErro = static function (string $res, int $code): string {
        $j = @json_decode($res, true);
        $suf = '';
        if (is_array($j)) {
            $suf = (string) ($j['message'] ?? $j['msg'] ?? $j['error'] ?? '');
            if ($suf === '' && isset($j['response']['message'])) {
                $suf = (string) $j['response']['message'];
            }
        }
        if ($suf === '' && $res !== '') {
            $suf = mb_substr($res, 0, 200);
        }

        return 'Evolution HTTP ' . $code . ($suf !== '' ? (': ' . $suf) : '');
    };

    $validarRespostaOk = static function (?array $j): bool {
        if (!is_array($j)) {
            return false;
        }
        if (array_key_exists('error', $j) && $j['error']) {
            return false;
        }
        if (isset($j['success']) && $j['success'] === false) {
            return false;
        }
        // Algumas builds devolvem falha com status numérico no corpo (HTTP ainda 200).
        if (isset($j['status']) && is_numeric($j['status']) && (int) $j['status'] >= 400) {
            return false;
        }
        // Evolution API v2: sucesso típico inclui key.id da mensagem.
        if (isset($j['key']) && is_array($j['key']) && !empty($j['key']['id'])) {
            return true;
        }
        // v1 / formatos sem objeto key (mantém compatibilidade).
        return true;
    };

    if ($hasMedia) {
        $mediaClean = achadinhosEvolutionStripMediaDataUriPrefix((string) $mediaBase64);
        $url = $base . '/message/sendMedia/' . rawurlencode($inst);
        $tentativas = [
            [
                'number' => $number,
                'caption' => $caption,
                'delay' => 3000,
                'media' => $mediaClean,
                'mediatype' => 'image',
                'mimetype' => 'image/jpeg',
                'fileName' => 'imagem_produto.jpeg',
            ],
            [
                'number' => $number,
                'caption' => $caption,
                'media' => $mediaClean,
                'mediatype' => 'image',
                'mimetype' => 'image/jpeg',
                'fileName' => 'imagem_produto.jpeg',
            ],
            [
                'number' => $number,
                'caption' => $caption,
                'media' => $mediaClean,
                'mediatype' => 'Image',
                'mimetype' => 'image/jpeg',
                'fileName' => 'imagem_produto.jpeg',
            ],
        ];
        $lastErr = '';
        foreach ($tentativas as $body) {
            [$res, $code] = $postEvolution($url, $body, $headers, 45);
            if ($code >= 200 && $code < 300) {
                $j = @json_decode($res, true);
                if (is_array($j) && $validarRespostaOk($j)) {
                    return true;
                }
                $lastErr = $interpretarErro($res, $code);
                continue;
            }
            $lastErr = $interpretarErro($res, $code);
        }
        $err = $lastErr !== '' ? $lastErr : 'Evolution sendMedia falhou';

        return false;
    }

    $url = $base . '/message/sendText/' . rawurlencode($inst);
    $tentativasTexto = [
        ['number' => $number, 'text' => $caption],
        ['number' => $number, 'text' => $caption, 'delay' => 1000],
    ];
    $lastErr = '';
    foreach ($tentativasTexto as $body) {
        [$res, $code] = $postEvolution($url, $body, $headers, 30);
        if ($code >= 200 && $code < 300) {
            $j = @json_decode($res, true);
            if (is_array($j) && $validarRespostaOk($j)) {
                return true;
            }
            $lastErr = $interpretarErro($res, $code);
            continue;
        }
        $lastErr = $interpretarErro($res, $code);
    }
    $err = $lastErr !== '' ? $lastErr : 'Evolution sendText falhou';

    return false;
}

/**
 * Envia mensagem WhatsApp usando credenciais unificadas (Evolution ou Uazapi).
 * A automação tenta enviar para todo JID cadastrado em Grupos: grupos abertos (todos podem postar)
 * funcionam com a conta conectada como membro; grupos “só administradores” ou avisos de comunidade
 * exigem que o número da instância seja admin — o WhatsApp/API recusa se não puder postar.
 *
 * @param array $evo url_base, instancia, api_key, provedor?, uazapi_admin_token?
 */
function enviarWhatsAppMensagem(array $evo, $number, $caption, $mediaBase64, &$err) {
    $provedor = $evo['provedor'] ?? 'evolution';
    if ($provedor === 'uazapi') {
        require_once __DIR__ . '/uazapi_whatsapp.php';

        return enviarWhatsAppUazapi(
            (string) ($evo['url_base'] ?? ''),
            (string) ($evo['api_key'] ?? ''),
            uazapiResolverAdminToken($evo),
            (string) $number,
            (string) $caption,
            $mediaBase64,
            $err
        );
    }

    return enviarWhatsAppEvolution(
        (string) ($evo['url_base'] ?? ''),
        (string) ($evo['instancia'] ?? ''),
        (string) ($evo['api_key'] ?? ''),
        $number,
        $caption,
        $mediaBase64,
        $err
    );
}

/**
 * Status/Stories: Evolution (v1/v2) ou Uazapi (várias rotas). Falhas na Uazapi devem ser silenciosas no painel.
 *
 * @param array $evo url_base, instancia, api_key, provedor?, uazapi_admin_token?
 */
function enviarWhatsAppStatusPorConta(array $evo, $mensagem, $imagemUrl, &$err = '') {
    $err = '';
    if (($evo['provedor'] ?? 'evolution') === 'uazapi') {
        require_once __DIR__ . '/uazapi_whatsapp.php';
        $tmpErr = '';
        enviarWhatsAppUazapiStatus(
            (string) ($evo['url_base'] ?? ''),
            (string) ($evo['api_key'] ?? ''),
            uazapiResolverAdminToken($evo),
            (string) $mensagem,
            $imagemUrl,
            $tmpErr
        );
        $err = '';

        return false;
    }

    return enviarWhatsAppStatusEvolution(
        (string) ($evo['url_base'] ?? ''),
        (string) ($evo['instancia'] ?? ''),
        (string) ($evo['api_key'] ?? ''),
        $mensagem,
        $imagemUrl,
        $err
    );
}

/**
 * Uma requisição sendStatus à Evolution API (corpo JSON bruto).
 *
 * @return array{code:int, res:string, j:?array}
 */
function achadinhosEvolutionStatusRequest(string $base, string $inst, string $apiKey, $body): array {
    $url = rtrim($base, '/') . '/message/sendStatus/' . rawurlencode($inst);
    $ch = curl_init($url);
    $jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body, $jf),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $apiKey],
        CURLOPT_TIMEOUT => 45,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($res) ? $res : '';
    $j = json_decode($raw, true);

    return ['code' => $code, 'res' => $raw, 'j' => is_array($j) ? $j : null];
}

/**
 * Publica no Status (stories) do WhatsApp via Evolution API (compatível v2 plano + v1 com statusMessage).
 *
 * @param string|null $imagemUrl URL da imagem (opcional)
 */
function enviarWhatsAppStatusEvolution($baseUrl, $inst, $apiKey, $mensagem, $imagemUrl, &$err = '') {
    $err = '';
    $base = rtrim((string) $baseUrl, '/');
    $inst = trim((string) $inst);
    $apiKey = trim((string) $apiKey);
    if ($base === '' || $inst === '' || $apiKey === '') {
        $err = 'Evolution status: URL, instância ou API Key ausente';

        return false;
    }
    // Legenda do Status costuma ter limite menor que mensagem de grupo; evita 400 por corpo grande.
    $mensagem = mb_substr(trim((string) $mensagem), 0, 650);
    if ($mensagem === '') {
        $mensagem = 'Oferta';
    }
    $hasImg = is_string($imagemUrl) && preg_match('#^https?://#i', trim($imagemUrl));
    $contentUrl = $hasImg ? trim((string) $imagemUrl) : $mensagem;
    // Schema v2 exige caption; string vazia costuma gerar HTTP 400 em algumas builds.
    $captionTexto = $mensagem;
    $captionImg = $hasImg ? $mensagem : $captionTexto;
    if ($hasImg && trim($captionImg) === '') {
        $captionImg = ' ';
    }

    // Evolution API v2 (OpenAPI): statusJidList é obrigatório — use [] com allContacts true.
    $montarInner = static function (bool $hasImgInner, string $content, string $capText, string $capImg, bool $allContacts, $jidList): array {
        $list = is_array($jidList) ? $jidList : [];

        return [
            'type' => $hasImgInner ? 'image' : 'text',
            'content' => $content,
            'caption' => $hasImgInner ? $capImg : $capText,
            'backgroundColor' => '#25D366',
            'font' => 1,
            'allContacts' => $allContacts,
            'statusJidList' => $list,
        ];
    };

    $tentativas = [];

    // v2: corpo plano com statusJidList (obrigatório na documentação oficial).
    $innerPadrao = $montarInner($hasImg, $contentUrl, $captionTexto, $captionImg, true, []);
    $tentativas[] = $innerPadrao;
    $tentativas[] = ['statusMessage' => $innerPadrao];

    // Algumas instâncias aceitam só lista explícita (owner)
    if (function_exists('achadinhosEvolutionFetchOwnerJid')) {
        $owner = achadinhosEvolutionFetchOwnerJid($base, $inst, $apiKey);
        if ($owner !== '' && strpos($owner, '@') !== false) {
            $innerOwner = $montarInner($hasImg, $contentUrl, $captionTexto, $captionImg, false, [$owner]);
            $tentativas[] = $innerOwner;
            $tentativas[] = ['statusMessage' => $innerOwner];
        }
    }

    if ($hasImg) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 25, 'header' => "User-Agent: Achadinhos-Status/1.0\r\nAccept: image/*,*/*;q=0.8\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $bin = @file_get_contents($contentUrl, false, $ctx);
        if ($bin !== false && strlen($bin) > 200) {
            $b64 = base64_encode($bin);
            $dataUri = 'data:image/jpeg;base64,' . $b64;
            $innerB64 = $montarInner(true, $dataUri, $captionTexto, $captionImg, true, []);
            $tentativas[] = $innerB64;
            $tentativas[] = ['statusMessage' => $innerB64];
        }
    }

    $lastCode = 0;
    $lastMsg = '';
    foreach ($tentativas as $body) {
        $r = achadinhosEvolutionStatusRequest($base, $inst, $apiKey, $body);
        $lastCode = $r['code'];
        if ($r['code'] >= 200 && $r['code'] < 300) {
            $j = $r['j'];
            if (!is_array($j)) {
                $lastMsg = 'Resposta inválida da API (sem JSON)';
                continue;
            }
            if (isset($j['error']) && $j['error']) {
                $lastMsg = (string) ($j['message'] ?? 'Erro ao publicar Status');
                continue;
            }
            if (isset($j['success']) && $j['success'] === false) {
                $lastMsg = (string) ($j['message'] ?? 'Status recusado pela API');
                continue;
            }
            if (isset($j['status']) && is_numeric($j['status']) && (int) $j['status'] >= 400) {
                $lastMsg = (string) ($j['message'] ?? $j['error'] ?? 'Bad Request');
                continue;
            }
            return true;
        }
        if (is_array($r['j'])) {
            $lastMsg = (string) ($r['j']['message'] ?? $r['j']['error'] ?? $r['j']['msg'] ?? '');
        }
        if ($lastMsg === '' && $r['res'] !== '') {
            $lastMsg = mb_substr($r['res'], 0, 160);
        }
    }
    $err = 'Evolution Status HTTP ' . $lastCode . ($lastMsg !== '' ? (': ' . $lastMsg) : '');

    return false;
}

/**
 * Classifica o produto usando Gemini API e retorna o slug da categoria.
 * Retorna null em caso de erro ou se a API key não estiver configurada.
 */
function classificarCategoriaGemini($nomeProduto, $contextoExtra, $apiKey, &$err) {
    $err = '';
    $apiKey = trim($apiKey);
    if (empty($apiKey)) {
        $err = 'Gemini API key não configurada.';
        return null;
    }
    $nomeProduto = trim($nomeProduto);
    if (empty($nomeProduto)) return null;

    $slugsValidos = [
        'beleza-cuidados-saude', 'tecnologia-eletronicos', 'brinquedos-bebes-criancas',
        'casa-cozinha-decoracao', 'estilo-vida-hobbies', 'moda-masculina', 'moda-feminina',
        'moda-infantil',
        'automotivo-ferramentas', 'tudo-em-um', 'produtos-intimos'
    ];

    $prompt = "Produto: \"{$nomeProduto}\"";
    if (!empty(trim((string)$contextoExtra))) {
        $prompt .= ". Contexto adicional: " . trim((string)$contextoExtra);
    }
    $prompt .= ".\n\nClassifique este produto em UMA das categorias abaixo. Retorne SOMENTE o slug exato (uma palavra com hífens), sem explicação:\n";
    $prompt .= implode(', ', $slugsValidos);
    $prompt .= "\nExemplo de resposta: tecnologia-eletronicos";

    $body = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 30]
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        $err = 'Gemini HTTP ' . $code;
        return null;
    }
    $j = @json_decode($res, true);
    $txt = trim($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if (empty($txt)) {
        $err = 'Gemini respondeu vazio.';
        return null;
    }
    $txt = trim(strtolower($txt));
    $lines = preg_split('/\r\n|\r|\n/', $txt);
    $first = trim((string) ($lines[0] ?? $txt));
    foreach ($slugsValidos as $slug) {
        if ($first === $slug || strpos($first, $slug) !== false) {
            return function_exists('mapearCategoriaParaSlugCanonico') ? mapearCategoriaParaSlugCanonico($slug) : $slug;
        }
    }
    $mapped = function_exists('mapearCategoriaParaSlugCanonico') ? mapearCategoriaParaSlugCanonico($first) : $first;
    if (in_array($mapped, $slugsValidos, true)) {
        return $mapped;
    }

    return 'tudo-em-um';
}

/**
 * Classifica o produto via OpenAI (Chat Completions) e retorna o slug da categoria.
 */
function classificarCategoriaOpenAI($nomeProduto, $contextoExtra, $apiKey, &$err) {
    $err = '';
    $apiKey = trim($apiKey);
    if (empty($apiKey)) {
        $err = 'OpenAI API key não configurada.';
        return null;
    }
    $nomeProduto = trim($nomeProduto);
    if (empty($nomeProduto)) {
        return null;
    }

    $slugsValidos = [
        'beleza-cuidados-saude', 'tecnologia-eletronicos', 'brinquedos-bebes-criancas',
        'casa-cozinha-decoracao', 'estilo-vida-hobbies', 'moda-masculina', 'moda-feminina',
        'moda-infantil',
        'automotivo-ferramentas', 'tudo-em-um', 'produtos-intimos',
    ];

    $prompt = "Produto: \"{$nomeProduto}\"";
    if (!empty(trim((string) $contextoExtra))) {
        $prompt .= '. Contexto adicional: ' . trim((string) $contextoExtra);
    }
    $prompt .= ".\n\nClassifique este produto em UMA das categorias abaixo. Retorne SOMENTE o slug exato (com hífens), sem explicação:\n";
    $prompt .= implode(', ', $slugsValidos);
    $prompt .= "\nExemplo de resposta: tecnologia-eletronicos";

    $model = trim((string) getConfig('ia_categoria_openai_model', ''));
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Você classifica produtos em exatamente um slug da lista. Resposta: só o slug, uma linha, sem aspas ou pontuação.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.2,
        'max_tokens' => 40,
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
        CURLOPT_TIMEOUT => 25,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        $err = 'OpenAI HTTP ' . $code;
        return null;
    }
    $j = @json_decode($res, true);
    $txt = trim((string) ($j['choices'][0]['message']['content'] ?? ''));
    if ($txt === '') {
        $err = 'OpenAI respondeu vazio.';
        return null;
    }
    $txt = trim(strtolower($txt));
    $lines = preg_split('/\r\n|\r|\n/', $txt);
    $first = trim((string) ($lines[0] ?? $txt));
    $first = preg_replace('/^[`"\']+|[`"\']+$/u', '', $first) ?? $first;

    foreach ($slugsValidos as $slug) {
        if ($first === $slug || strpos($first, $slug) !== false) {
            return function_exists('mapearCategoriaParaSlugCanonico') ? mapearCategoriaParaSlugCanonico($slug) : $slug;
        }
    }
    $mapped = function_exists('mapearCategoriaParaSlugCanonico') ? mapearCategoriaParaSlugCanonico($first) : $first;
    if (in_array($mapped, $slugsValidos, true)) {
        return $mapped;
    }

    return 'tudo-em-um';
}

/**
 * Retorna categoria_id para o produto usando APENAS as 10 categorias fixas (nichos).
 * NUNCA cria nova categoria. Mapeia por palavras-chave para um dos nichos; se não houver match, usa "Tudo em um".
 * 1) Se a aba IA estiver com OpenAI ou Gemini para categorias e a chave correspondente estiver preenchida, usa esse provedor.
 * 2) Se ml_site_categoria_id estiver definido e for válido, usa ele.
 * 3) Senão, pontua o nome do produto contra os nichos e retorna o id da categoria correspondente.
 */
function obterOuCriarCategoriaParaProduto($nomeProduto, &$err, $prefix = 'ml', $contextoExtra = '') {
    $err = '';
    $nomeProduto = trim($nomeProduto);
    if (empty($nomeProduto)) return null;

    $pdo = getDB();
    if (function_exists('achadinhosGarantirHierarquiaPadrao')) {
        achadinhosGarantirHierarquiaPadrao($pdo);
    } elseif (function_exists('achadinhosGarantirHierarquiaModa')) {
        achadinhosGarantirHierarquiaModa($pdo);
    }

    // 0) Classificação por IA (OpenAI ou Gemini — Configurações → IA)
    $provedorIa = function_exists('iaCategoriaProvedorAtual') ? iaCategoriaProvedorAtual() : 'none';
    if ($provedorIa === 'gemini') {
        $geminiKey = trim(getConfig('gemini_api_key', ''));
        if ($geminiKey !== '') {
            $slugIa = classificarCategoriaGemini($nomeProduto, $contextoExtra, $geminiKey, $errGemini);
            if ($slugIa) {
                $idIa = function_exists('achadinhosBuscarCategoriaIdPorSlugCanonico')
                    ? achadinhosBuscarCategoriaIdPorSlugCanonico($pdo, $slugIa)
                    : null;
                if (!$idIa) {
                    $idIa = function_exists('achadinhosBuscarCategoriaIdPorSlugCanonico')
                        ? achadinhosBuscarCategoriaIdPorSlugCanonico($pdo, 'tudo-em-um')
                        : null;
                }
                if ($idIa) {
                    return (int) $idIa;
                }
            }
        }
    } elseif ($provedorIa === 'openai') {
        $openaiKey = trim(getConfig('openai_api_key', ''));
        if ($openaiKey === '') {
            $openaiKey = trim(getConfig('ml_openai_api_key', ''));
        }
        if ($openaiKey !== '') {
            $slugIa = classificarCategoriaOpenAI($nomeProduto, $contextoExtra, $openaiKey, $errOpenai);
            if ($slugIa) {
                $idIa = function_exists('achadinhosBuscarCategoriaIdPorSlugCanonico')
                    ? achadinhosBuscarCategoriaIdPorSlugCanonico($pdo, $slugIa)
                    : null;
                if (!$idIa) {
                    $idIa = function_exists('achadinhosBuscarCategoriaIdPorSlugCanonico')
                        ? achadinhosBuscarCategoriaIdPorSlugCanonico($pdo, 'tudo-em-um')
                        : null;
                }
                if ($idIa) {
                    return (int) $idIa;
                }
            }
        }
    }

    // 1) Categoria fixa da loja (0 / -1 = automático; -2 = mais-vendidos)
    $cidResolved = function_exists('achadinhosResolverCategoriaFixaLoja')
        ? achadinhosResolverCategoriaFixaLoja($pdo, getConfig($prefix . '_site_categoria_id', '-1'))
        : null;
    if ($cidResolved !== null) {
        return (int) $cidResolved;
    }

    // Slugs das categorias fixas (nichos); moda-infantil é folha sob categoria pai Moda (parent_id na BD).
    $nichosSlugs = [
        'beleza-cuidados-saude', 'tecnologia-eletronicos', 'brinquedos-bebes-criancas', 'casa-cozinha-decoracao',
        'estilo-vida-hobbies', 'moda-masculina', 'moda-feminina', 'moda-infantil',
        'automotivo-ferramentas', 'tudo-em-um', 'produtos-intimos',
    ];

    // Palavras-chave por nicho (termo => slug). Ordem importa: termos mais específicos primeiro.
    // Termos compostos devem ser verificados primeiro (antes de dividir em palavras)
    $termosCompostos = [
        'ar condicionado' => 'casa-cozinha-decoracao',
        'ar-condicionado' => 'casa-cozinha-decoracao',
        'caixa de som' => 'tecnologia-eletronicos',
        'caixa-de-som' => 'tecnologia-eletronicos',
        'caixa som' => 'tecnologia-eletronicos',
        'boombox' => 'tecnologia-eletronicos',
        'kit cuecas' => 'moda-masculina',
        'kit cueca' => 'moda-masculina',
        'boneca infantil' => 'brinquedos-bebes-criancas',
        'boneco infantil' => 'brinquedos-bebes-criancas',
        'brinquedo infantil' => 'brinquedos-bebes-criancas',
        'conjunto de brinquedos' => 'brinquedos-bebes-criancas',
        'kit brinquedos' => 'brinquedos-bebes-criancas',
        'kit de brinquedos' => 'brinquedos-bebes-criancas',
        'conjunto infantil' => 'moda-infantil',
        'conjunto-infantil' => 'moda-infantil',
        'roupa infantil' => 'moda-infantil',
        'roupa-infantil' => 'moda-infantil',
        'roupas infantil' => 'moda-infantil',
        'moda infantil' => 'moda-infantil',
        'vestido infantil' => 'moda-infantil',
        'camiseta infantil' => 'moda-infantil',
        'short infantil' => 'moda-infantil',
        'calca infantil' => 'moda-infantil',
        'calça infantil' => 'moda-infantil',
        'tenis infantil' => 'moda-infantil',
        'tênis infantil' => 'moda-infantil',
        'agasalho infantil' => 'moda-infantil',
        'moleton infantil' => 'moda-infantil',
        'smart tv' => 'tecnologia-eletronicos',
        'smart-tv' => 'tecnologia-eletronicos',
        'camera ip' => 'tecnologia-eletronicos',
        'câmera ip' => 'tecnologia-eletronicos',
        'camera segurança' => 'tecnologia-eletronicos',
        'câmera segurança' => 'tecnologia-eletronicos',
        'camera seguranca' => 'tecnologia-eletronicos',
        'câmera seguranca' => 'tecnologia-eletronicos',
        'whey protein' => 'beleza-cuidados-saude',
        'whey' => 'beleza-cuidados-saude',
        'micro ondas' => 'casa-cozinha-decoracao',
        'micro-ondas' => 'casa-cozinha-decoracao',
        'cadeira escritorio' => 'casa-cozinha-decoracao',
        'cadeira escritório' => 'casa-cozinha-decoracao',
        'cadeira ergonomica' => 'casa-cozinha-decoracao',
        'cadeira ergonômica' => 'casa-cozinha-decoracao',
        'lavadora pressao' => 'casa-cozinha-decoracao',
        'lavadora pressão' => 'casa-cozinha-decoracao',
        'lava jato' => 'automotivo-ferramentas',
        'lava-jato' => 'automotivo-ferramentas',
        'lava jato portatil' => 'automotivo-ferramentas',
        'lava jato portátil' => 'automotivo-ferramentas',
        'calca jeans' => 'moda-feminina',
        'calça jeans' => 'moda-feminina',
        'wide leg' => 'moda-feminina',
        'pantalona' => 'moda-feminina',
        'guarda roupa' => 'casa-cozinha-decoracao',
        'guarda-roupa' => 'casa-cozinha-decoracao',
        'maquina solda' => 'automotivo-ferramentas',
        'máquina solda' => 'automotivo-ferramentas',
        'máquina de solda' => 'automotivo-ferramentas',
        'compressor ar' => 'automotivo-ferramentas',
        'compressor de ar' => 'automotivo-ferramentas',
        'serra circular' => 'automotivo-ferramentas',
        'chave impacto' => 'automotivo-ferramentas',
        'chave de impacto' => 'automotivo-ferramentas',
        'esmerilhadeira' => 'automotivo-ferramentas',
        'lixadeira' => 'automotivo-ferramentas',
        'barbeador' => 'beleza-cuidados-saude',
        'oneblade' => 'beleza-cuidados-saude',
        'philips' => 'beleza-cuidados-saude',
    ];
    
    $termoParaNicho = [
        // Produtos íntimos (prioridade alta)
        'lingerie' => 'produtos-intimos', 'intima' => 'produtos-intimos', 'intimos' => 'produtos-intimos', 'sensual' => 'produtos-intimos',
        
        // Tecnologia e Eletrônicos (prioridade alta - termos específicos primeiro)
        'impressora' => 'tecnologia-eletronicos', 'epson' => 'tecnologia-eletronicos', 'hp' => 'tecnologia-eletronicos',
        'ecotank' => 'tecnologia-eletronicos', 'multifuncional' => 'tecnologia-eletronicos', 'deskjet' => 'tecnologia-eletronicos',
        'ink' => 'tecnologia-eletronicos', 'advantage' => 'tecnologia-eletronicos', 'smart tank' => 'tecnologia-eletronicos',
        'tablet' => 'tecnologia-eletronicos', 'lenovo' => 'tecnologia-eletronicos', 'tab' => 'tecnologia-eletronicos',
        'enterprise' => 'tecnologia-eletronicos', 'edition' => 'tecnologia-eletronicos', 'x115' => 'tecnologia-eletronicos',
        'android' => 'tecnologia-eletronicos', 'ram' => 'tecnologia-eletronicos', 'gb' => 'tecnologia-eletronicos',
        'nobreak' => 'tecnologia-eletronicos', 'no-break' => 'tecnologia-eletronicos', 'jbr' => 'tecnologia-eletronicos',
        'guard' => 'tecnologia-eletronicos', 'va' => 'tecnologia-eletronicos',
        'camera' => 'tecnologia-eletronicos', 'câmera' => 'tecnologia-eletronicos', 'icsee' => 'tecnologia-eletronicos',
        'wifi' => 'tecnologia-eletronicos', 'ip' => 'tecnologia-eletronicos', 'projetor' => 'tecnologia-eletronicos',
        'davely' => 'tecnologia-eletronicos', 'ansi' => 'tecnologia-eletronicos', 'lumens' => 'tecnologia-eletronicos',
        'hy320' => 'tecnologia-eletronicos', 'wifi6' => 'tecnologia-eletronicos',
        'bateria carregador' => 'tecnologia-eletronicos', 'carregador portatil' => 'tecnologia-eletronicos',
        'carregador portátil' => 'tecnologia-eletronicos', 'samsung' => 'tecnologia-eletronicos',
        'mah' => 'tecnologia-eletronicos', '10000' => 'tecnologia-eletronicos',
        // 'seguranca' e 'segurança' removidos - só conta se for câmera de segurança (termo composto)
        'gamer' => 'tecnologia-eletronicos', 'acer' => 'tecnologia-eletronicos', 'nitro' => 'tecnologia-eletronicos',
        'monitor' => 'tecnologia-eletronicos', 'notebook' => 'tecnologia-eletronicos', 'laptop' => 'tecnologia-eletronicos',
        'celular' => 'tecnologia-eletronicos', 'smartphone' => 'tecnologia-eletronicos', 'iphone' => 'tecnologia-eletronicos',
        'motorola' => 'tecnologia-eletronicos', 'moto' => 'tecnologia-eletronicos',
        'samsung' => 'tecnologia-eletronicos', 'xiaomi' => 'tecnologia-eletronicos', 'lg' => 'tecnologia-eletronicos',
        'philco' => 'tecnologia-eletronicos', 'aoc' => 'tecnologia-eletronicos',
        'informatica' => 'tecnologia-eletronicos', 'eletronico' => 'tecnologia-eletronicos', 'eletronicos' => 'tecnologia-eletronicos',
        'game' => 'tecnologia-eletronicos', 'games' => 'tecnologia-eletronicos', 'playstation' => 'tecnologia-eletronicos',
        'xbox' => 'tecnologia-eletronicos', 'nintendo' => 'tecnologia-eletronicos', 'tv' => 'tecnologia-eletronicos',
        'fone' => 'tecnologia-eletronicos', 'headphone' => 'tecnologia-eletronicos', 'bluetooth' => 'tecnologia-eletronicos',
        'som' => 'tecnologia-eletronicos', 'speaker' => 'tecnologia-eletronicos', 'aiwa' => 'tecnologia-eletronicos',
        
        // Moda Masculina (prioridade alta - termos específicos primeiro)
        'cueca' => 'moda-masculina', 'cuecas' => 'moda-masculina', 'boxer' => 'moda-masculina', 'boxers' => 'moda-masculina',
        'camiseta' => 'moda-masculina', 'camisetas' => 'moda-masculina', 'camisa' => 'moda-masculina',
        'perfume' => 'moda-masculina', 'hugo' => 'moda-masculina', 'boss' => 'moda-masculina', 'eau' => 'moda-masculina',
        'parfum' => 'moda-masculina', 'intense' => 'moda-masculina', 'asad' => 'moda-masculina',
        'bourbon' => 'moda-masculina', 'lattafa' => 'moda-masculina', 'fixacao' => 'moda-masculina',
        'tenis' => 'moda-masculina', 'tênis' => 'moda-masculina', 'kappa' => 'moda-masculina', 'park' => 'moda-masculina',
        'treviso' => 'moda-masculina', 'unissex' => 'moda-masculina', 'confortavel' => 'moda-masculina',
        'chinelo' => 'moda-masculina', 'chinelos' => 'moda-masculina', 'havaianas' => 'moda-masculina',
        'masculina' => 'moda-masculina', 'masculino' => 'moda-masculina', 'masculinos' => 'moda-masculina',
        'polo' => 'moda-masculina', 'wear' => 'moda-masculina', 'esportivos' => 'moda-masculina', 'dry' => 'moda-masculina',
        'fit' => 'moda-masculina', 'slim' => 'moda-masculina',
        
        // Moda Feminina
        'calca' => 'moda-feminina', 'calça' => 'moda-feminina', 'calcas' => 'moda-feminina', 'calças' => 'moda-feminina',
        'jeans' => 'moda-feminina', 'wide' => 'moda-feminina', 'leg' => 'moda-feminina', 'cintura' => 'moda-feminina',
        'alta' => 'moda-feminina', 'pantalona' => 'moda-feminina', 'pantalonas' => 'moda-feminina',
        'short' => 'moda-feminina', 'shorts' => 'moda-feminina', 'bermuda' => 'moda-feminina', 'bermudas' => 'moda-feminina',
        'top' => 'moda-feminina', 'tops' => 'moda-feminina', 'los angeles' => 'moda-feminina', 'academia' => 'moda-feminina',
        'fitness' => 'moda-feminina', 'compreensao' => 'moda-feminina', 'compressão' => 'moda-feminina',
        'meias' => 'moda-feminina', 'meia' => 'moda-feminina', 'puma' => 'moda-feminina', 'reebok' => 'moda-feminina',
        'canos' => 'moda-feminina', 'atoalhada' => 'moda-feminina', 'cano' => 'moda-feminina', 'medio' => 'moda-feminina',
        'feminina' => 'moda-feminina', 'feminino' => 'moda-feminina', 'mulher' => 'moda-feminina', 'femininos' => 'moda-feminina',
        'moda' => 'moda-feminina', 'calcados' => 'moda-feminina', 'acessorios' => 'moda-feminina',
        
        // Beleza e Saúde (prioridade alta - termos específicos primeiro)
        'creatina' => 'beleza-cuidados-saude', 'whey' => 'beleza-cuidados-saude', 'protein' => 'beleza-cuidados-saude',
        'suplemento' => 'beleza-cuidados-saude', 'suplementos' => 'beleza-cuidados-saude',
        'growth' => 'beleza-cuidados-saude', 'dark lab' => 'beleza-cuidados-saude', 'soldiers' => 'beleza-cuidados-saude',
        'max titanium' => 'beleza-cuidados-saude', 'titanium' => 'beleza-cuidados-saude',
        'gel limpeza' => 'beleza-cuidados-saude', 'cerave' => 'beleza-cuidados-saude', 'facial' => 'beleza-cuidados-saude',
        'barbeador' => 'beleza-cuidados-saude', 'oneblade' => 'beleza-cuidados-saude', 'philips' => 'beleza-cuidados-saude',
        'rosto' => 'beleza-cuidados-saude', 'corpo' => 'beleza-cuidados-saude',
        'beleza' => 'beleza-cuidados-saude', 'cuidados' => 'beleza-cuidados-saude', 'saude' => 'beleza-cuidados-saude',
        'bem-estar' => 'beleza-cuidados-saude', 'nutricionais' => 'beleza-cuidados-saude',
        'alimentares' => 'beleza-cuidados-saude', 'cosmetico' => 'beleza-cuidados-saude', 'maquiagem' => 'beleza-cuidados-saude',
        
        // Casa e Cozinha (prioridade média - termos específicos primeiro)
        'microondas' => 'casa-cozinha-decoracao', 'micro-ondas' => 'casa-cozinha-decoracao', 'mto30' => 'casa-cozinha-decoracao',
        'cadeira' => 'casa-cozinha-decoracao', 'escritorio' => 'casa-cozinha-decoracao', 'escritório' => 'casa-cozinha-decoracao',
        'ergonomica' => 'casa-cozinha-decoracao', 'ergonômica' => 'casa-cozinha-decoracao', 'luvinco' => 'casa-cozinha-decoracao',
        'genebra' => 'casa-cozinha-decoracao', 'monaco' => 'casa-cozinha-decoracao', 'boston' => 'casa-cozinha-decoracao',
        'giratória' => 'casa-cozinha-decoracao', 'giratoria' => 'casa-cozinha-decoracao', 'malha' => 'casa-cozinha-decoracao',
        'guarda roupa' => 'casa-cozinha-decoracao', 'guarda-roupa' => 'casa-cozinha-decoracao', 'casal' => 'casa-cozinha-decoracao',
        'portas' => 'casa-cozinha-decoracao', 'gavetas' => 'casa-cozinha-decoracao', 'franca' => 'casa-cozinha-decoracao',
        'lencol' => 'casa-cozinha-decoracao', 'lençol' => 'casa-cozinha-decoracao', 'lencois' => 'casa-cozinha-decoracao',
        'lençois' => 'casa-cozinha-decoracao', 'fios' => 'casa-cozinha-decoracao', 'ponto' => 'casa-cozinha-decoracao',
        'queen' => 'casa-cozinha-decoracao', 'saldão' => 'casa-cozinha-decoracao', 'saldao' => 'casa-cozinha-decoracao',
        'elastico' => 'casa-cozinha-decoracao', 'elástico' => 'casa-cozinha-decoracao', 'hotel' => 'casa-cozinha-decoracao',
        'toalha' => 'casa-cozinha-decoracao', 'toalhas' => 'casa-cozinha-decoracao', 'banho' => 'casa-cozinha-decoracao',
        'algodao' => 'casa-cozinha-decoracao', 'algodão' => 'casa-cozinha-decoracao',
        'gigante' => 'casa-cozinha-decoracao', 'grossa' => 'casa-cozinha-decoracao', 'macia' => 'casa-cozinha-decoracao',
        'travesseiro' => 'casa-cozinha-decoracao', 'travesseiros' => 'casa-cozinha-decoracao', 'nasa' => 'casa-cozinha-decoracao',
        'cervical' => 'casa-cozinha-decoracao', 'coluna' => 'casa-cozinha-decoracao', 'relax' => 'casa-cozinha-decoracao',
        'carvao' => 'casa-cozinha-decoracao', 'carvão' => 'casa-cozinha-decoracao', 'ativado' => 'casa-cozinha-decoracao',
        'varal' => 'casa-cozinha-decoracao', 'varais' => 'casa-cozinha-decoracao', 'chao' => 'casa-cozinha-decoracao',
        'chão' => 'casa-cozinha-decoracao', 'andares' => 'casa-cozinha-decoracao', 'dobravel' => 'casa-cozinha-decoracao',
        'lavadora' => 'casa-cozinha-decoracao', 'pressao' => 'casa-cozinha-decoracao', 'pressão' => 'casa-cozinha-decoracao',
        // 'portatil' e 'portátil' removidos - muito genéricos, podem causar conflitos
        'vonder' => 'casa-cozinha-decoracao', 'libras' => 'casa-cozinha-decoracao', 'lbf' => 'casa-cozinha-decoracao',
        'aspirador' => 'casa-cozinha-decoracao', 'vertical' => 'casa-cozinha-decoracao', 'wap' => 'casa-cozinha-decoracao',
        'speed' => 'casa-cozinha-decoracao', 'garrafa' => 'casa-cozinha-decoracao', 'termica' => 'casa-cozinha-decoracao',
        'térmica' => 'casa-cozinha-decoracao', 'termometro' => 'casa-cozinha-decoracao', 'termômetro' => 'casa-cozinha-decoracao',
        'chas' => 'casa-cozinha-decoracao', 'chás' => 'casa-cozinha-decoracao', 'cafe' => 'casa-cozinha-decoracao',
        'café' => 'casa-cozinha-decoracao',
        'pote' => 'casa-cozinha-decoracao', 'potes' => 'casa-cozinha-decoracao', 'marmita' => 'casa-cozinha-decoracao',
        'hermetico' => 'casa-cozinha-decoracao', 'hermético' => 'casa-cozinha-decoracao', 'vidro' => 'casa-cozinha-decoracao',
        'tampa' => 'casa-cozinha-decoracao', 'plastico' => 'casa-cozinha-decoracao', 'plástico' => 'casa-cozinha-decoracao',
        'tenda' => 'casa-cozinha-decoracao', 'gazebo' => 'casa-cozinha-decoracao', 'sanfonado' => 'casa-cozinha-decoracao',
        'extensao' => 'casa-cozinha-decoracao', 'extensão' => 'casa-cozinha-decoracao', 'tomada' => 'casa-cozinha-decoracao',
        'tomadas' => 'casa-cozinha-decoracao', 'usb' => 'casa-cozinha-decoracao', 'power' => 'casa-cozinha-decoracao',
        'bivolt' => 'casa-cozinha-decoracao', 'pd' => 'casa-cozinha-decoracao', 'led' => 'casa-cozinha-decoracao',
        'trilho' => 'casa-cozinha-decoracao', 'eletrificado' => 'casa-cozinha-decoracao', 'spots' => 'casa-cozinha-decoracao',
        'ry' => 'casa-cozinha-decoracao',
        'condicionado' => 'casa-cozinha-decoracao', 'split' => 'casa-cozinha-decoracao', 'inverter' => 'casa-cozinha-decoracao',
        'eletrodomesticos' => 'casa-cozinha-decoracao', 'geladeira' => 'casa-cozinha-decoracao', 'fogao' => 'casa-cozinha-decoracao',
        'cozinha' => 'casa-cozinha-decoracao', 'decoracao' => 'casa-cozinha-decoracao', 'moveis' => 'casa-cozinha-decoracao',
        'construcao' => 'casa-cozinha-decoracao', 'jardim' => 'casa-cozinha-decoracao', 'iluminacao' => 'casa-cozinha-decoracao',
        'limpeza' => 'casa-cozinha-decoracao', 'lavanderia' => 'casa-cozinha-decoracao', 'panela' => 'casa-cozinha-decoracao',
        'casa' => 'casa-cozinha-decoracao', // Último para evitar matches genéricos
        
        // Automotivo e Ferramentas (prioridade média)
        'roçadeira' => 'automotivo-ferramentas', 'rocadeira' => 'automotivo-ferramentas', 'nakasaki' => 'automotivo-ferramentas',
        'gasolina' => 'automotivo-ferramentas', 'cc' => 'automotivo-ferramentas',
        'bomba' => 'automotivo-ferramentas', 'submersa' => 'automotivo-ferramentas', 'intech' => 'automotivo-ferramentas',
        'caneta' => 'automotivo-ferramentas', 'palito' => 'automotivo-ferramentas', 'machine' => 'automotivo-ferramentas',
        'parafusadeira' => 'automotivo-ferramentas', 'furadeira' => 'automotivo-ferramentas', 'impacto' => 'automotivo-ferramentas',
        'chave' => 'automotivo-ferramentas', 'pol' => 'automotivo-ferramentas', 'rpm' => 'automotivo-ferramentas',
        'black tools' => 'automotivo-ferramentas', 'the black' => 'automotivo-ferramentas', 'tb' => 'automotivo-ferramentas',
        'ferramenta' => 'automotivo-ferramentas', 'ferramentas' => 'automotivo-ferramentas', 'peças' => 'automotivo-ferramentas',
        'maleta' => 'automotivo-ferramentas',
        'esmerilhadeira' => 'automotivo-ferramentas', 'lixadeira' => 'automotivo-ferramentas', 'angular' => 'automotivo-ferramentas',
        'sem fio' => 'automotivo-ferramentas', 'sem-fio' => 'automotivo-ferramentas', 'mah' => 'automotivo-ferramentas',
        'serra' => 'automotivo-ferramentas', 'circular' => 'automotivo-ferramentas', 'several' => 'automotivo-ferramentas',
        'brushless' => 'automotivo-ferramentas', 'importados' => 'automotivo-ferramentas',
        'maquina solda' => 'automotivo-ferramentas', 'máquina solda' => 'automotivo-ferramentas',
        'solda' => 'automotivo-ferramentas', 'inversora' => 'automotivo-ferramentas', 'start' => 'automotivo-ferramentas',
        'arc' => 'automotivo-ferramentas',
        'compressor' => 'automotivo-ferramentas', 'carro' => 'automotivo-ferramentas', 'moto' => 'automotivo-ferramentas',
        'lava jato' => 'automotivo-ferramentas', 'lava-jato' => 'automotivo-ferramentas',
        'escada' => 'automotivo-ferramentas', 'articulada' => 'automotivo-ferramentas', 'reisam' => 'automotivo-ferramentas',
        'aluminio' => 'automotivo-ferramentas', 'alumínio' => 'automotivo-ferramentas', 'degraus' => 'automotivo-ferramentas',
        'bota' => 'automotivo-ferramentas', 'botina' => 'automotivo-ferramentas', 'seguranca' => 'automotivo-ferramentas',
        'segurança' => 'automotivo-ferramentas', 'bracol' => 'automotivo-ferramentas', 'coturno' => 'automotivo-ferramentas',
        'nobuck' => 'automotivo-ferramentas', 'sapato' => 'automotivo-ferramentas',
        'automotivo' => 'automotivo-ferramentas', 'veiculos' => 'automotivo-ferramentas', 'supermercados' => 'automotivo-ferramentas',
        'eletricas' => 'automotivo-ferramentas',
        
        // Brinquedos e Bebês (infantil só via compostos "brinquedo/roupa..." ou palavras abaixo; vestuário infantil = moda-infantil)
        'brinquedos' => 'brinquedos-bebes-criancas', 'bebes' => 'brinquedos-bebes-criancas', 'bebe' => 'brinquedos-bebes-criancas',
        'criancas' => 'brinquedos-bebes-criancas', 'crianca' => 'brinquedos-bebes-criancas',
        'menino' => 'brinquedos-bebes-criancas', 'menina' => 'brinquedos-bebes-criancas',
        'piscinas' => 'brinquedos-bebes-criancas', 'mamadeira' => 'brinquedos-bebes-criancas',
        // Removido 'conjunto', 'roupa', 'roupas' daqui - só vão para brinquedos se tiver "infantil" junto
        
        // Estilo de Vida
        'bicicleta' => 'estilo-vida-hobbies', 'bike' => 'estilo-vida-hobbies', 'ergometrica' => 'estilo-vida-hobbies',
        'ergométrica' => 'estilo-vida-hobbies', 'cardio' => 'estilo-vida-hobbies', 'musculacao' => 'estilo-vida-hobbies',
        'estilo' => 'estilo-vida-hobbies', 'hobbies' => 'estilo-vida-hobbies', 'livros' => 'estilo-vida-hobbies',
        'esportes' => 'estilo-vida-hobbies', 'lazer' => 'estilo-vida-hobbies', 'pet' => 'estilo-vida-hobbies',
        'agro' => 'estilo-vida-hobbies', 'papelaria' => 'estilo-vida-hobbies', 'escolar' => 'estilo-vida-hobbies',
    ];

    $txt = removerAcentos(mb_strtolower($nomeProduto));
    
    // Primeiro verificar termos compostos (mais específicos)
    $scores = [];
    foreach ($nichosSlugs as $slug) {
        $scores[$slug] = 0;
    }
    
    foreach ($termosCompostos as $termoComposto => $slug) {
        if (strpos($txt, $termoComposto) !== false) {
            $scores[$slug] += 5; // Peso muito maior para termos compostos (prioridade absoluta)
        }
    }
    
    // Verificação especial: se tem "câmera segurança" ou "camera segurança", garantir que vai para tecnologia
    // Mas se tem "bota segurança" ou "botina segurança", vai para automotivo
    if (strpos($txt, 'camera seguranca') !== false || strpos($txt, 'câmera seguranca') !== false || 
        strpos($txt, 'camera segurança') !== false || strpos($txt, 'câmera segurança') !== false) {
        $scores['tecnologia-eletronicos'] += 5;
    }
    if ((strpos($txt, 'bota') !== false || strpos($txt, 'botina') !== false) && 
        (strpos($txt, 'seguranca') !== false || strpos($txt, 'segurança') !== false)) {
        $scores['automotivo-ferramentas'] += 5;
    }
    // Verificação especial: "lava jato" sempre vai para automotivo, não casa
    if (strpos($txt, 'lava jato') !== false || strpos($txt, 'lava-jato') !== false) {
        $scores['automotivo-ferramentas'] += 5;
        $scores['casa-cozinha-decoracao'] = 0; // Zerar pontuação de casa se tiver "lava jato"
    }
    // Verificação especial: ferramentas elétricas sempre vão para automotivo/ferramentas
    if (strpos($txt, 'esmerilhadeira') !== false || strpos($txt, 'lixadeira') !== false || 
        strpos($txt, 'serra circular') !== false || strpos($txt, 'chave impacto') !== false ||
        strpos($txt, 'chave de impacto') !== false || strpos($txt, 'maquina solda') !== false ||
        strpos($txt, 'máquina solda') !== false || strpos($txt, 'compressor ar') !== false ||
        strpos($txt, 'compressor de ar') !== false) {
        $scores['automotivo-ferramentas'] += 5;
        $scores['casa-cozinha-decoracao'] = max(0, $scores['casa-cozinha-decoracao'] - 3); // Reduzir pontuação de casa
    }
    // Verificação especial: perfumes sempre vão para moda masculina/feminina
    if (strpos($txt, 'perfume') !== false) {
        if (strpos($txt, 'feminin') !== false || strpos($txt, 'mulher') !== false) {
            $scores['moda-feminina'] += 5;
        } else {
            $scores['moda-masculina'] += 5;
        }
        $scores['casa-cozinha-decoracao'] = max(0, $scores['casa-cozinha-decoracao'] - 3); // Reduzir pontuação de casa
    }
    // Verificação especial: calças sempre vão para moda (infantil = moda-infantil)
    if (strpos($txt, 'calca') !== false || strpos($txt, 'calça') !== false || strpos($txt, 'jeans') !== false) {
        $isInfCalca = (strpos($txt, 'infantil') !== false || strpos($txt, 'menino') !== false || strpos($txt, 'menina') !== false
            || strpos($txt, 'bebe') !== false || strpos($txt, 'bebê') !== false || strpos($txt, 'crianca') !== false
            || strpos($txt, 'criança') !== false);
        if ($isInfCalca) {
            $scores['moda-infantil'] += 5;
        } elseif (strpos($txt, 'masculin') !== false || strpos($txt, 'homem') !== false) {
            $scores['moda-masculina'] += 5;
        } else {
            $scores['moda-feminina'] += 5;
        }
        $scores['casa-cozinha-decoracao'] = max(0, $scores['casa-cozinha-decoracao'] - 3); // Reduzir pontuação de casa
    }
    // Verificação especial: guarda-roupa sempre vai para casa
    if (strpos($txt, 'guarda roupa') !== false || strpos($txt, 'guarda-roupa') !== false) {
        $scores['casa-cozinha-decoracao'] += 5;
        $scores['tecnologia-eletronicos'] = max(0, $scores['tecnologia-eletronicos'] - 3); // Reduzir pontuação de tecnologia
    }
    // Verificação especial: bateria carregador portátil Samsung vai para tecnologia
    if ((strpos($txt, 'bateria carregador') !== false || strpos($txt, 'carregador portatil') !== false || 
         strpos($txt, 'carregador portátil') !== false) && strpos($txt, 'samsung') !== false) {
        $scores['tecnologia-eletronicos'] += 5;
        $scores['casa-cozinha-decoracao'] = max(0, $scores['casa-cozinha-decoracao'] - 3); // Reduzir pontuação de casa
    }
    
    // Depois verificar palavras individuais
    $words = array_filter(preg_split('/[\s\-]+/', $txt), function ($w) { return strlen($w) >= 3; }); // Mínimo 3 caracteres para evitar matches genéricos
    
    // Verificar se é produto infantil (para "conjunto", "roupa" só irem para brinquedos se for infantil)
    $isInfantil = false;
    $palavrasInfantis = ['infantil', 'menino', 'menina', 'bebe', 'bebes', 'crianca', 'criancas', 'bebê', 'bebês', 'criança', 'crianças'];
    foreach ($words as $w) {
        if (in_array($w, $palavrasInfantis)) {
            $isInfantil = true;
            break;
        }
    }

    if ($isInfantil) {
        $roupaInfTokens = [
            'vestido', 'camiseta', 'camisa', 'short', 'bermuda', 'calca', 'calça', 'conjunto', 'roupa', 'blusa', 'saia',
            'agasalho', 'moleton', 'meia', 'meias', 'tenis', 'tênis', 'chinelo', 'sandalia', 'sandália', 'sapato', 'body', 'bodies',
        ];
        foreach ($roupaInfTokens as $tok) {
            if (strpos($txt, $tok) !== false) {
                $scores['moda-infantil'] += 6;
                $scores['brinquedos-bebes-criancas'] = max(0, $scores['brinquedos-bebes-criancas'] - 3);
                break;
            }
        }
    }
    
    // Termos genéricos que só devem fazer match exato (não substring)
    $termosGenericos = ['casa', 'moda', 'estilo', 'pet', 'agro', 'tv', 'lg'];
    
    foreach ($termoParaNicho as $termo => $slug) {
        if (!in_array($slug, $nichosSlugs, true)) continue;
        
        // "conjunto"/"roupa" não pontuam brinquedos (vestuário infantil = moda-infantil via compostos; brinquedo = compostos "brinquedo infantil", etc.)
        if (in_array($termo, ['conjunto', 'roupa', 'roupas'], true) && $slug === 'brinquedos-bebes-criancas') {
            continue;
        }
        
        foreach ($words as $w) {
            $isMatch = false;
            
            // Match exato sempre conta
            if ($w === $termo) {
                $isMatch = true;
            }
            // Para termos não-genéricos com 4+ caracteres, aceita substring
            elseif (!in_array($termo, $termosGenericos) && strlen($termo) >= 4 && strpos($w, $termo) !== false) {
                $isMatch = true;
            }
            // Para termos genéricos, só match exato
            elseif (in_array($termo, $termosGenericos) && $w === $termo) {
                $isMatch = true;
            }
            
            if ($isMatch) {
                // Match exato vale mais pontos
                $scores[$slug] += ($w === $termo ? 2 : 1);
                break; // Uma palavra só conta uma vez por categoria
            }
        }
    }

    // Encontrar melhor categoria (só usar "tudo-em-um" se realmente não houver nenhum match)
    $melhorSlug = 'tudo-em-um';
    $melhorPontos = 0;
    foreach ($scores as $slug => $pts) {
        if ($pts > $melhorPontos) {
            $melhorPontos = $pts;
            $melhorSlug = $slug;
        }
    }
    
    // Se não encontrou nenhum match (pontos = 0), tentar categorias mais genéricas baseadas em palavras comuns
    if ($melhorPontos === 0) {
        // Verificar palavras que indicam categoria mesmo sem match exato
        $palavrasCasa = ['pote', 'marmita', 'lençol', 'lencol', 'toalha', 'travesseiro', 'cadeira', 'varal', 'tenda', 'gazebo'];
        $palavrasTecnologia = ['smart', 'digital', 'wifi', 'bluetooth', 'usb', 'tablet', 'impressora', 'camera', 'câmera'];
        $palavrasModa = ['kit', 'par', 'pares', 'unidade', 'conjunto'];
        $palavrasFerramentas = ['roçadeira', 'rocadeira', 'bomba', 'parafusadeira', 'furadeira', 'escada', 'ferramenta'];
        $palavrasSuplementos = ['creatina', 'whey', 'protein', 'suplemento', 'growth', 'titanium'];
        
        foreach ($words as $w) {
            if (in_array($w, $palavrasCasa)) {
                $melhorSlug = 'casa-cozinha-decoracao';
                break;
            } elseif (in_array($w, $palavrasTecnologia)) {
                $melhorSlug = 'tecnologia-eletronicos';
                break;
            } elseif (in_array($w, $palavrasFerramentas)) {
                $melhorSlug = 'automotivo-ferramentas';
                break;
            } elseif (in_array($w, $palavrasSuplementos)) {
                $melhorSlug = 'beleza-cuidados-saude';
                break;
            } elseif (in_array($w, $palavrasModa)) {
                // Se tem "kit", "par" ou "conjunto" junto com palavras de moda, é moda
                if (strpos($txt, 'cueca') !== false || strpos($txt, 'camiseta') !== false || strpos($txt, 'short') !== false || 
                    strpos($txt, 'top') !== false || strpos($txt, 'bermuda') !== false || strpos($txt, 'meia') !== false ||
                    strpos($txt, 'tenis') !== false || strpos($txt, 'tênis') !== false || strpos($txt, 'chinelo') !== false) {
                    if (strpos($txt, 'infantil') !== false || strpos($txt, 'menino') !== false || strpos($txt, 'menina') !== false
                        || strpos($txt, 'bebe') !== false || strpos($txt, 'bebê') !== false) {
                        $melhorSlug = 'moda-infantil';
                    } elseif (strpos($txt, 'masculin') !== false || strpos($txt, 'homem') !== false || strpos($txt, 'boxer') !== false) {
                        $melhorSlug = 'moda-masculina';
                    } else {
                        $melhorSlug = 'moda-feminina';
                    }
                    break;
                }
            }
        }
    }

    if (function_exists('mapearCategoriaParaSlugCanonico')) {
        $melhorSlug = mapearCategoriaParaSlugCanonico($melhorSlug);
    }
    $idCat = function_exists('achadinhosBuscarCategoriaIdPorSlugCanonico')
        ? achadinhosBuscarCategoriaIdPorSlugCanonico($pdo, $melhorSlug)
        : null;
    if ($idCat) {
        return $idCat;
    }
    $st = $pdo->prepare("SELECT id FROM categorias WHERE slug = ? AND ativo = 1 LIMIT 1");
    $st->execute([$melhorSlug]);
    $row = $st->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    // Fallback: qualquer categoria "tudo-em-um" ou primeira ativa
    $st = $pdo->prepare("SELECT id FROM categorias WHERE ativo = 1 ORDER BY ordem ASC LIMIT 1");
    $st->execute();
    $row = $st->fetch();
    return $row ? (int)$row['id'] : null;
}

function removerAcentos($s) {
    $a = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
    return strtr(mb_strtolower($s), $a);
}

function slugify($s) {
    $s = removerAcentos($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function sugerirCategoriaOpenAI($nomeProduto, $apiKey, $model, &$err) {
    $err = '';
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => "Produto: \"{$nomeProduto}\". Retorne SOMENTE o nome de uma categoria em 1 a 3 palavras (ex: Eletrônicos, Casa e Cozinha, Moda). Uma única linha, sem explicação."],
        ],
        'temperature' => 0.2,
        'max_tokens' => 30,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI (categoria) HTTP ' . $code;
        return '';
    }
    $j = @json_decode($res, true);
    $t = trim($j['choices'][0]['message']['content'] ?? '');
    if (preg_match('/[:\-]\s*(.+)$/u', $t, $m)) $t = trim($m[1]);
    $t = preg_replace('/\s+/', ' ', $t);
    if ($t !== '' && function_exists('achadinhosBuscarCategoriaIdPorNomeOuSlug')) {
        try {
            $pdo = getDB();
            $cid = achadinhosBuscarCategoriaIdPorNomeOuSlug($pdo, $t);
            if ($cid) {
                $st = $pdo->prepare('SELECT nome FROM categorias WHERE id = ? AND ativo = 1 LIMIT 1');
                $st->execute([$cid]);
                $rw = $st->fetch(PDO::FETCH_ASSOC);
                if ($rw && trim((string)$rw['nome']) !== '') {
                    return trim($rw['nome']);
                }
            }
        } catch (Exception $e) {
        }
    }
    return $t;
}

/**
 * Salva o produto no site de ofertas (tabela produtos).
 * $precoStr: texto tipo "R$ 99,90" ou "De R$ 149,90 por R$ 99,90"
 * $linkAfiliado: link de compra (já convertido para afiliado)
 * Retorna o ID do produto ou 0. Em caso de falha, $err é preenchido.
 * $preco, $preco_original, $desconto: opcionais (ex.: Shopee); se null, extrai de $precoStr.
 * $parcelas, $preco_parcela: opcionais; se preenchidos, exibe "em 12x de R$ 46,43" no site.
 * $configPrefix: 'ml' ou 'shopee' para getConfig de categoria/OpenAI.
 * $categoriaIdForcado: opcional; se fornecido, usa em vez de obter categoria automaticamente.
 * $exigirFotoBaixada: se true (padrão), exige URL válida e download OK (ML/Shopee/Amazon/AliExpress).
 */
function salvarProdutoNoSite($nome, $precoStr, $linkAfiliado, $imagemUrl, &$err, $preco = null, $preco_original = null, $desconto = null, $configPrefix = 'ml', $parcelas = null, $preco_parcela = null, $categoriaIdForcado = null, $exigirFotoBaixada = true) {
    $err = '';
    $nome = trim($nome);
    $linkAfiliado = trim($linkAfiliado);
    if (empty($nome) || empty($linkAfiliado)) {
        $err = 'Nome ou link vazio.';
        return 0;
    }
    $imagemUrl = function_exists('achadinhosNormalizarUrlImagemProduto') ? achadinhosNormalizarUrlImagemProduto((string) $imagemUrl) : trim((string) $imagemUrl);
    if ($exigirFotoBaixada && $imagemUrl === '') {
        $err = 'Imagem obrigatória: URL da foto inválida ou vazia.';
        return 0;
    }
    
    // Verificar se produto já existe por nome similar (evitar duplicatas)
    try {
        $pdo = getDB();
        $nomeNorm = mb_substr($nome, 0, 50);
        $st = $pdo->prepare("SELECT id FROM produtos WHERE SUBSTRING(nome, 1, 50) = ? LIMIT 1");
        $st->execute([$nomeNorm]);
        if ($st->fetch()) {
            $err = 'Produto já existe no site (nome similar).';
            return 0;
        }
    } catch (Exception $e) {
        // Continuar mesmo se der erro
    }
    
    if ($preco === null && $preco_original === null) {
        $preco = null;
        $preco_original = null;
        if (preg_match_all('/R\$\s*([\d.,]+)/u', $precoStr, $m)) {
            $vals = [];
            foreach ($m[1] as $v) {
                $f = function_exists('parsePrecoBr') ? parsePrecoBr($v) : null;
                if ($f === null) $f = floatval(str_replace([','], ['.'], trim($v)));
                if ($f > 0) $vals[] = $f;
            }
            if (count($vals) >= 2) {
                $preco_original = max($vals);
                $preco = min($vals);
            } elseif (count($vals) === 1) {
                $preco = $vals[0];
            }
        }
        $desconto = 0;
        if ($preco_original > 0 && $preco > 0 && $preco_original > $preco && function_exists('calcularDesconto')) {
            $desconto = calcularDesconto($preco_original, $preco);
        }
        if (function_exists('sanearPrecoOriginal')) {
            list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, $desconto);
        }
    } else {
        if ($desconto === null && $preco_original > 0 && $preco > 0 && $preco_original > $preco && function_exists('calcularDesconto')) {
            $desconto = calcularDesconto($preco_original, $preco);
        }
        $desconto = $desconto ?? 0;
        if (function_exists('sanearPrecoOriginal')) {
            list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, $desconto);
        }
    }
    
    // Extrair parcelas do texto se não fornecidas
    if ($parcelas === null && $preco_parcela === null && function_exists('extrairParcelas')) {
        list($parcelas, $preco_parcela) = extrairParcelas($precoStr);
    }
    
    // Se temos preco_parcela mas não parcelas, não mostrar (evita confusão)
    if ($preco_parcela !== null && $parcelas === null) {
        $preco_parcela = null;
    }
    // Se temos parcelas mas não preco_parcela, não mostrar
    if ($parcelas !== null && $preco_parcela === null) {
        $parcelas = null;
    }
    
    // Garantir que preco é o total, não o valor da parcela
    if (function_exists('corrigirPrecoTotalParcelas') && $parcelas && $preco_parcela) {
        $preco = corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela);
    }
    
    $imagem = null;
    if ($imagemUrl !== '' && function_exists('downloadImageFromUrl')) {
        $imagem = downloadImageFromUrl($imagemUrl, 'uploads/produtos/');
    }
    if ($exigirFotoBaixada && ($imagem === null || $imagem === '')) {
        $err = 'Imagem obrigatória: não foi possível baixar a foto do produto.';
        return 0;
    }
    $categoria_id = ($categoriaIdForcado !== null && $categoriaIdForcado > 0)
        ? (int)$categoriaIdForcado
        : obterOuCriarCategoriaParaProduto($nome, $errCat, $configPrefix, $precoStr ?? '');
    if (!empty($errCat)) $err = $errCat;
    try {
        $pdo = getDB();

        $st = $pdo->prepare("
            INSERT INTO produtos (nome, categoria_id, imagem, preco, preco_original, desconto, parcelas, preco_parcela, link_compra, destaque, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        $st->execute([
            $nome,
            $categoria_id,
            $imagem,
            $preco,
            $preco_original,
            $desconto,
            $parcelas,
            $preco_parcela,
            $linkAfiliado,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        $err = $e->getMessage();
        return 0;
    }
}
