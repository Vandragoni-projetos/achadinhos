<?php
/**
 * Automação de envio de cupons do Mercado Livre
 * Busca cupons disponíveis e envia nos grupos do WhatsApp
 * 
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_CUPONS_ML_LOADED')) {
    define('AUTOMACAO_CUPONS_ML_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php'; // Para usar funções auxiliares

function runAutomacaoCuponsML($forcarExecucao = false, $apenasGrupoId = null) {
    $details = [];
    $errors = [];
    $apenasGrupoId = ($apenasGrupoId !== null && (int) $apenasGrupoId > 0) ? (int) $apenasGrupoId : null;

    // 1) Config e validações
    $ativa = $forcarExecucao || (getConfig('ml_cupons_automacao_ativa', '0') === '1');
    $evolutionContaId = (int)getConfig('ml_cupons_evolution_conta_id', '0');
    $gruposIdsJson = getConfig('ml_cupons_grupos_ids', '[]');
    $gruposIds = json_decode($gruposIdsJson, true) ?: [];
    $linkAtivacao = getConfig('ml_cupons_link_ativacao', '');
    $imagemCupom = getConfig('ml_cupons_imagem', '');
    $delay = max(1, min(120, (int)getConfig('ml_cupons_delay_entre_envios', '10')));
    $qtdPorExec = max(1, min(10, (int) getConfig('ml_cupons_produtos_por_execucao', '1')));
    $diasEvitarCupom = max(0, (int) getConfig('ml_cupons_dias_evitar_repetir', '1'));

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação de cupons desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }

    if (empty($linkAtivacao)) {
        $errors[] = 'Link de ativação: informe o link para ativação dos cupons.';
    }

    if ($apenasGrupoId === null) {
        if ($evolutionContaId <= 0) {
            $errors[] = 'Evolution API: selecione uma conta Evolution.';
        }
        if (empty($gruposIds)) {
            $errors[] = 'Grupos: selecione ao menos um grupo WhatsApp.';
        }
    }

    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Mercadolivre API.', 'details' => $details, 'errors' => $errors];
    }

    $schemaPath = __DIR__ . '/../core/db/SchemaHelper.php';
    if (is_file($schemaPath)) {
        require_once $schemaPath;
        if (function_exists('garantirColunaGruposWhatsappPostHoras')) {
            garantirColunaGruposWhatsappPostHoras();
        }
    }

    // Buscar grupos e conta do banco (provedor/uazapi alinhado às outras automações — ML Cupons + Uazapi)
    $pdo = getDB();
    $gruposDoBanco = [];
    $contaEvolution = null;

    try {
        static $cuponsEvoMode = null;
        static $cuponsSqlConta = null;
        if ($cuponsEvoMode === null) {
            try {
                $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
                $cuponsEvoMode = 'ext_ap';
            } catch (Exception $e) {
                try {
                    $pdo->query('SELECT provedor, uazapi_admin_token FROM evolution_contas LIMIT 1');
                    $cuponsEvoMode = 'ext';
                } catch (Exception $e2) {
                    $cuponsEvoMode = 'legacy';
                }
            }
            $cuponsSqlConta = 'SELECT url_base, instancia, api_key FROM evolution_contas WHERE id = ? AND ativo = 1';
            if ($cuponsEvoMode === 'ext_ap') {
                $cuponsSqlConta = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token, COALESCE(api_propria, 0) AS api_propria FROM evolution_contas WHERE id = ? AND ativo = 1';
            } elseif ($cuponsEvoMode === 'ext') {
                $cuponsSqlConta = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token FROM evolution_contas WHERE id = ? AND ativo = 1';
            }
        }
        $sqlEJoin = 'e.url_base, e.instancia, e.api_key';
        if ($cuponsEvoMode === 'ext_ap') {
            $sqlEJoin .= ', COALESCE(e.provedor, \'evolution\') AS provedor, e.uazapi_admin_token, COALESCE(e.api_propria, 0) AS api_propria';
        } elseif ($cuponsEvoMode === 'ext') {
            $sqlEJoin .= ', COALESCE(e.provedor, \'evolution\') AS provedor, e.uazapi_admin_token';
        }
        $cuponsMontarEvolution = static function (array $row, ?array $contaDb, string $mode): array {
            $c = $contaDb ?? [];
            $url = ($row['url_base'] ?? '') !== '' ? $row['url_base'] : ($c['url_base'] ?? '');
            $inst = ($row['instancia'] ?? '') !== '' ? $row['instancia'] : ($c['instancia'] ?? '');
            $key = ($row['api_key'] ?? '') !== '' ? $row['api_key'] : ($c['api_key'] ?? '');
            $out = [
                'url_base' => $url !== '' ? rtrim((string) $url, '/') : '',
                'instancia' => (string) $inst,
                'api_key' => (string) $key,
            ];
            if ($mode === 'ext' || $mode === 'ext_ap') {
                $pv = ($row['provedor'] ?? '') !== '' ? $row['provedor'] : ($c['provedor'] ?? 'evolution');
                $out['provedor'] = (string) $pv;
                $out['uazapi_admin_token'] = (string) (($row['uazapi_admin_token'] ?? '') !== '' ? $row['uazapi_admin_token'] : ($c['uazapi_admin_token'] ?? ''));
                $out['api_propria'] = $mode === 'ext_ap' ? (int) ($row['api_propria'] ?? $c['api_propria'] ?? 0) : 0;
            } else {
                $out['provedor'] = 'evolution';
                $out['uazapi_admin_token'] = '';
                $out['api_propria'] = 0;
            }

            return $out;
        };

        if ($apenasGrupoId !== null) {
            $stmt = $pdo->prepare("
                SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, g.post_hora_inicio, g.post_hora_fim, {$sqlEJoin}
                FROM grupos_whatsapp g
                LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
                WHERE g.id = ? AND g.ativo = 1 AND COALESCE(g.automacao_loja, 'ml') = 'ml_cupons'
                LIMIT 1
            ");
            $stmt->execute([$apenasGrupoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = 'Grupo não encontrado, inativo ou não é loja ML Cupons.';
                return ['success' => false, 'message' => 'Grupo inválido para cupons.', 'details' => $details, 'errors' => $errors];
            }
            $evoId = (int) ($row['evolution_conta_id'] ?? 0);
            if ($evoId <= 0) {
                $errors[] = 'Grupo sem conta Evolution.';
                return ['success' => false, 'message' => 'Configure a conta Evolution no grupo.', 'details' => $details, 'errors' => $errors];
            }
            $stmt = $pdo->prepare($cuponsSqlConta);
            $stmt->execute([$evoId]);
            $contaEvolution = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contaEvolution) {
                $errors[] = 'Conta Evolution do grupo não encontrada ou inativa.';
                return ['success' => false, 'message' => 'Erro na conta Evolution do grupo.', 'details' => $details, 'errors' => $errors];
            }
            $gruposDoBanco[] = [
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'grupo_id' => $row['grupo_id'],
                'evolution_conta_id' => $evoId,
                'evolution' => $cuponsMontarEvolution($row, $contaEvolution, (string) $cuponsEvoMode),
                'intervalo_minutos' => isset($row['intervalo_minutos']) ? (int) $row['intervalo_minutos'] : null,
                'post_hora_inicio' => $row['post_hora_inicio'] ?? null,
                'post_hora_fim' => $row['post_hora_fim'] ?? null,
            ];
        } else {
            $stmt = $pdo->prepare($cuponsSqlConta);
            $stmt->execute([$evolutionContaId]);
            $contaEvolution = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$contaEvolution) {
                $errors[] = 'Conta Evolution não encontrada ou inativa.';
                return ['success' => false, 'message' => 'Erro na configuração da conta Evolution.', 'details' => $details, 'errors' => $errors];
            }

            if (!empty($gruposIds)) {
                $placeholders = implode(',', array_fill(0, count($gruposIds), '?'));
                $stmt = $pdo->prepare("
                SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, g.post_hora_inicio, g.post_hora_fim, {$sqlEJoin}
                FROM grupos_whatsapp g
                LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
                WHERE g.id IN ($placeholders) AND g.ativo = 1
            ");
                $stmt->execute($gruposIds);
                while ($row = $stmt->fetch()) {
                    $gruposDoBanco[] = [
                        'id' => (int) $row['id'],
                        'nome' => $row['nome'],
                        'grupo_id' => $row['grupo_id'],
                        'evolution_conta_id' => (int) $row['evolution_conta_id'],
                        'evolution' => $cuponsMontarEvolution($row, $contaEvolution, (string) $cuponsEvoMode),
                        'intervalo_minutos' => isset($row['intervalo_minutos']) ? (int) $row['intervalo_minutos'] : null,
                        'post_hora_inicio' => $row['post_hora_inicio'] ?? null,
                        'post_hora_fim' => $row['post_hora_fim'] ?? null,
                    ];
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Erro ao buscar grupos: ' . $e->getMessage();
        return ['success' => false, 'message' => 'Erro ao carregar grupos do banco.', 'details' => $details, 'errors' => $errors];
    }
    
    if (empty($gruposDoBanco)) {
        $errors[] = 'Nenhum grupo válido encontrado.';
        return ['success' => false, 'message' => 'Configure os grupos na página Mercadolivre API.', 'details' => $details, 'errors' => $errors];
    }
    
    // 2) Buscar cupons disponíveis
    $cupons = buscarCuponsDisponiveisML($errors);
    $details['cupons_encontrados'] = count($cupons);
    
    if (empty($cupons)) {
        return ['success' => false, 'message' => 'Nenhum cupom disponível encontrado.', 'details' => $details, 'errors' => $errors];
    }
    
    // Filtrar cupons já enviados (evitar repetição)
    $pdo = getDB();
    $cuponsParaEnviar = [];
    foreach ($cupons as $cupom) {
        $codigo = $cupom['codigo'] ?? '';
        if (empty($codigo)) continue;
        
        // Verificar se já foi enviado (janela em dias; 0 = nunca repetir o mesmo código)
        try {
            if ($diasEvitarCupom > 0) {
                $stmt = $pdo->prepare('SELECT id FROM cupons_enviados WHERE codigo = ? AND data_envio >= DATE_SUB(NOW(), INTERVAL ? DAY)');
                $stmt->execute([$codigo, $diasEvitarCupom]);
            } else {
                $stmt = $pdo->prepare('SELECT id FROM cupons_enviados WHERE codigo = ?');
                $stmt->execute([$codigo]);
            }
            if ($stmt->fetch()) {
                continue;
            }
        } catch (Exception $e) {
            // Tabela pode não existir ainda, criar depois
        }
        
        $cuponsParaEnviar[] = $cupom;
    }
    
    if (empty($cuponsParaEnviar)) {
        return ['success' => false, 'message' => 'Todos os cupons encontrados já foram enviados na janela configurada.', 'details' => $details, 'errors' => $errors];
    }
    
    // Pegar apenas alguns cupons para não sobrecarregar
    $cuponsParaEnviar = array_slice($cuponsParaEnviar, 0, min($qtdPorExec, count($cuponsParaEnviar)));
    $details['cupons_para_enviar'] = count($cuponsParaEnviar);
    
    // 3) Preparar imagem
    $imgB64 = null;
    if (!empty($imagemCupom)) {
        $imagemPath = __DIR__ . '/../' . $imagemCupom;
        if (file_exists($imagemPath)) {
            // Converter imagem local para base64
            $imageData = @file_get_contents($imagemPath);
            if ($imageData !== false && strlen($imageData) > 10) {
                $img = @imagecreatefromstring($imageData);
                if ($img) {
                    ob_start();
                    @imagejpeg($img, null, 90);
                    $jpeg = ob_get_clean();
                    @imagedestroy($img);
                    if ($jpeg !== false && strlen($jpeg) > 0) {
                        $imgB64 = base64_encode($jpeg);
                    }
                }
            }
        }
    }
    
    // 4) Enviar para cada grupo (ou dispatch opcional)
    $enviados = 0;
    $errosEnvio = [];

    $mensagemCupons = formatarMensagemCuponsML($cuponsParaEnviar, $linkAtivacao);
    $dispatchesTree = dispatch_habilitado() ? get_active_dispatches(dispatch_envio_admin_id()) : [
        'whatsapp' => [],
        'telegram' => [],
    ];
    $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatchesTree['whatsapp']);
    $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatchesTree['telegram']);
    $evoFallbackStatus = !empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null;

    $inserirCuponsPorGrupoJid = function (string $grupoJid) use ($cuponsParaEnviar, $pdo) {
        foreach ($cuponsParaEnviar as $cupom) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cupons_enviados (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        codigo VARCHAR(100) NOT NULL,
                        desconto VARCHAR(50),
                        condicoes TEXT,
                        data_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
                        grupo_id VARCHAR(255),
                        INDEX idx_codigo (codigo),
                        INDEX idx_data (data_envio)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare('INSERT INTO cupons_enviados (codigo, desconto, condicoes, grupo_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $cupom['codigo'] ?? '',
                    $cupom['desconto'] ?? '',
                    $cupom['condicoes'] ?? '',
                    $grupoJid,
                ]);
            } catch (Exception $e) {
                // Ignorar erro de inserção
            }
        }
    };

    if ($useWaDispatch) {
        dispatch_executar_whatsapp_destinos(
            $dispatchesTree['whatsapp'],
            'ml_cupons',
            (int) $delay,
            $imgB64,
            function ($idx, $total) use ($mensagemCupons) {
                return $mensagemCupons;
            },
            $errosEnvio,
            $enviados,
            $evoFallbackStatus,
            $inserirCuponsPorGrupoJid,
            $forcarExecucao
        );
    } else {
        foreach ($gruposDoBanco as $grupoIdx => $grupoInfo) {
            $grupoId = $grupoInfo['grupo_id'];
            $grupoIdDb = (int)($grupoInfo['id'] ?? 0);
            if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && !grupoEstaNaJanelaPostagem($grupoInfo['post_hora_inicio'] ?? null, $grupoInfo['post_hora_fim'] ?? null)) {
                continue;
            }
            if (!$forcarExecucao && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, 'ml_cupons', $grupoInfo['intervalo_minutos'] ?? null, $delay)) {
                continue;
            }
            $evo = $grupoInfo['evolution'];
            $ok = enviarWhatsAppMensagem($evo, $grupoId, $mensagemCupons, $imgB64, $err);
            if ($ok) {
                $enviados++;
                if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                    registrarEnvioGrupo($grupoIdDb, 'ml_cupons');
                }
                $inserirCuponsPorGrupoJid($grupoId);
            } else {
                $errosEnvio[] = 'Grupo ' . ($grupoInfo['nome'] ?? $grupoId) . ': ' . $err;
            }
            if ($grupoIdx < count($gruposDoBanco) - 1) {
                sleep((int) $delay);
            }
        }
    }

    $cupomTgB64 = ($imgB64 !== null && $imgB64 !== '') ? (string) $imgB64 : null;
    if ($useTgDispatch) {
        dispatch_executar_telegram_destinos($dispatchesTree['telegram'], $mensagemCupons, null, $errosEnvio, $cupomTgB64);
    } elseif ($enviados > 0 && function_exists('enviarTelegram')) {
        enviarTelegramFluxoLoja('ml_cupons', $mensagemCupons, null, $errosEnvio, $cupomTgB64);
    }

    if ($enviados > 0 && function_exists('getEvolutionParaStatus') && function_exists('enviarWhatsAppStatusPorConta')) {
        $fallback = $evoFallbackStatus ?? (!empty($gruposDoBanco) ? ($gruposDoBanco[0]['evolution'] ?? null) : null);
        $evoStatus = getEvolutionParaStatus($fallback, 'ml_cupons');
        if ($evoStatus) {
            $errSt = '';
            enviarWhatsAppStatusPorConta($evoStatus, $mensagemCupons, null, $errSt);
            if (!empty($errSt) && (($evoStatus['provedor'] ?? 'evolution') !== 'uazapi')) {
                $errosEnvio[] = 'Status: ' . $errSt;
            }
        }
    }
    
    $errors = array_merge($errors, $errosEnvio);
    $details['mensagens_enviadas'] = $enviados;
    
    if ($enviados > 0) {
        return ['success' => true, 'message' => "Automação concluída. {$enviados} mensagem(ns) enviada(s) com " . count($cuponsParaEnviar) . " cupom(ns).", 'details' => $details, 'errors' => $errors];
    }
    
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

/**
 * Busca cupons disponíveis do Mercado Livre (afiliados)
 * Tenta buscar via scraping da página de cupons de afiliados
 */
function buscarCuponsDisponiveisML(&$errors = []) {
    $cupons = [];
    
    // Obter credenciais de autenticação específicas para cupons de afiliados
    $cookie = getConfig('ml_cupons_cookie', '');
    $csrf = getConfig('ml_cupons_csrf_token', '');
    
    if (empty($cookie) || empty($csrf)) {
        $errors[] = 'É necessário configurar Cookie e CSRF Token específicos para cupons de afiliados na página Mercadolivre API.';
        return $cupons;
    }
    
    // 1) Buscar cupons disponíveis via scraping da página de afiliados
    $urlCupons = 'https://www.mercadolivre.com.br/afiliados/coupons';
    
    $ch = curl_init($urlCupons);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Cookie: ' . $cookie,
            'x-csrf-token: ' . $csrf,
        ],
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($html)) {
        $errors[] = 'Não foi possível acessar a página de cupons de afiliados (HTTP ' . $httpCode . ').';
        return $cupons;
    }
    
    // 2) Tentar extrair dados JSON da página (geralmente há um script com dados)
    if (preg_match('/window\.__PRELOADED_STATE__\s*=\s*({.+?});/s', $html, $matches)) {
        $preloadedState = json_decode($matches[1], true);
        if ($preloadedState && isset($preloadedState['coupons'])) {
            // Processar cupons do estado pré-carregado
            foreach ($preloadedState['coupons'] as $cupomData) {
                $cupons[] = processarCupomAfiliado($cupomData);
            }
        }
    }
    
    // 3) Se não encontrou no JSON, tentar extrair via DOM
    if (empty($cupons)) {
        $cupons = extrairCuponsDoHTML($html, $errors);
    }
    
    // 4) Para cada cupom encontrado, tentar gerar código via API interna
    foreach ($cupons as &$cupom) {
        if (empty($cupom['codigo']) && !empty($cupom['id'])) {
            $codigoGerado = gerarCodigoCupomAfiliado($cupom['id'], $cookie, $csrf, $errors);
            if (!empty($codigoGerado)) {
                $cupom['codigo'] = $codigoGerado;
            }
        }
    }
    
    return array_filter($cupons, function($c) {
        return !empty($c['codigo']);
    });
}

/**
 * Processa dados de cupom do estado pré-carregado
 */
function processarCupomAfiliado($data) {
    $cupom = [
        'id' => $data['id'] ?? '',
        'codigo' => $data['code'] ?? $data['coupon_code'] ?? '',
        'desconto' => '',
        'condicoes' => '',
        'validade' => $data['expires_at'] ?? $data['valid_until'] ?? '',
        'produtos' => $data['products'] ?? $data['store_name'] ?? '',
        'orçamento' => $data['budget'] ?? $data['remaining_budget'] ?? ''
    ];
    
    // Formatar desconto
    if (isset($data['discount_amount'])) {
        $cupom['desconto'] = 'R$ ' . number_format($data['discount_amount'], 2, ',', '.') . ' OFF';
    } elseif (isset($data['discount_percentage'])) {
        $cupom['desconto'] = $data['discount_percentage'] . '% OFF';
    }
    
    // Formatar condições
    $condicoes = [];
    if (isset($data['min_purchase'])) {
        $condicoes[] = 'a partir de R$' . number_format($data['min_purchase'], 2, ',', '.');
    }
    if (!empty($cupom['produtos'])) {
        $condicoes[] = 'em produtos de ' . $cupom['produtos'];
    }
    $cupom['condicoes'] = implode(', ', $condicoes);
    
    return $cupom;
}

/**
 * Extrai cupons do HTML usando DOM
 */
function extrairCuponsDoHTML($html, &$errors = []) {
    $cupons = [];
    
    try {
        $dom = @new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        
        // Tentar encontrar cards de cupons (pode variar conforme estrutura da página)
        $cards = $xpath->query("//*[contains(@class, 'coupon') or contains(@class, 'cupom')]");
        
        foreach ($cards as $card) {
            $cupom = [
                'id' => '',
                'codigo' => '',
                'desconto' => '',
                'condicoes' => '',
                'validade' => '',
                'produtos' => '',
                'orçamento' => ''
            ];
            
            // Extrair informações do card
            $texto = $card->textContent;
            
            // Tentar encontrar código
            if (preg_match('/#([A-Z0-9]+)/', $texto, $matches)) {
                $cupom['codigo'] = '#' . $matches[1];
            }
            
            // Tentar encontrar desconto
            if (preg_match('/R\$\s*([\d.,]+)/', $texto, $matches)) {
                $cupom['desconto'] = 'R$ ' . $matches[1] . ' OFF';
            } elseif (preg_match('/(\d+)%\s*OFF/', $texto, $matches)) {
                $cupom['desconto'] = $matches[1] . '% OFF';
            }
            
            if (!empty($cupom['codigo']) || !empty($cupom['desconto'])) {
                $cupons[] = $cupom;
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Erro ao extrair cupons do HTML: ' . $e->getMessage();
    }
    
    return $cupons;
}

/**
 * Gera código de cupom via API interna do Mercado Livre
 */
function gerarCodigoCupomAfiliado($cupomId, $cookie, $csrf, &$errors = []) {
    // Tentar diferentes endpoints possíveis da API interna
    $endpoints = [
        'https://www.mercadolivre.com.br/afiliados/coupons/generate',
        'https://www.mercadolivre.com.br/afiliados/api/coupons/generate',
        'https://www.mercadolivre.com.br/affiliate-program/api/v2/coupons/generate',
    ];
    
    foreach ($endpoints as $apiUrl) {
        $postData = json_encode([
            'coupon_id' => $cupomId,
            'id' => $cupomId
        ]);
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Cookie: ' . $cookie,
                'x-csrf-token: ' . $csrf,
                'Origin: https://www.mercadolivre.com.br',
                'Referer: https://www.mercadolivre.com.br/afiliados/coupons',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            if (isset($data['code']) || isset($data['coupon_code']) || isset($data['couponCode'])) {
                return $data['code'] ?? $data['coupon_code'] ?? $data['couponCode'] ?? '';
            }
            // Se retornou sucesso mas sem código, pode ser que já tenha código
            if (isset($data['id']) || isset($data['success'])) {
                // Tentar buscar o código gerado
                return '';
            }
        } elseif ($httpCode === 404) {
            // Endpoint não existe, tentar próximo
            continue;
        }
    }
    
    return '';
}

/**
 * Formata mensagem de cupons no estilo da imagem
 */
function formatarMensagemCuponsML($cupons, $linkAtivacao) {
    $mensagem = "🎉 *CUPONS LIBERADOS NO MERCADO LIVRE!*\n\n";
    
    foreach ($cupons as $cupom) {
        $codigo = $cupom['codigo'] ?? '';
        $desconto = $cupom['desconto'] ?? '';
        $condicoes = $cupom['condicoes'] ?? '';
        $validade = $cupom['validade'] ?? '';
        $produtos = $cupom['produtos'] ?? '';
        
        $mensagem .= "🎫 *{$codigo}*\n";
        
        if (!empty($desconto)) {
            $mensagem .= "{$desconto}";
            if (!empty($condicoes)) {
                $mensagem .= " {$condicoes}";
            }
            $mensagem .= ".\n";
        } elseif (!empty($condicoes)) {
            $mensagem .= "{$condicoes}.\n";
        }
        
        // Adicionar informações de validade se disponível
        if (!empty($validade)) {
            try {
                $dataValidade = new DateTime($validade);
                $mensagem .= "⏰ Vence em " . $dataValidade->format('d/m/Y') . ".\n";
            } catch (Exception $e) {
                // Ignorar erro de data
            }
        }
        
        // Adicionar produtos/lojas se disponível
        if (!empty($produtos)) {
            $mensagem .= "🏪 {$produtos}.\n";
        }
        
        $mensagem .= "\n";
    }
    
    if (!empty($linkAtivacao)) {
        $mensagem .= "👉 *ATIVE AQUI:*\n";
        $mensagem .= "{$linkAtivacao}\n";
    }
    
    return trim($mensagem);
}
