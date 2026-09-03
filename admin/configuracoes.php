<?php
// Processar formulário ANTES de incluir o header (para poder redirecionar)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Verificar login
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();

/**
 * Salva configuração do tipo de provedor WhatsApp.
 *
 * @param array<string,mixed> $input
 * @return array{tipo:string}
 */
function salvarConfigTipoWhatsApp(array $input): array
{
    $tipoRaw = trim((string) ($input['evolution_tipo'] ?? 'terceiros'));
    $evolution_tipo = in_array($tipoRaw, ['terceiros', 'propria', 'uazapi', 'uazapi_propria'], true) ? $tipoRaw : 'terceiros';
    setConfig('evolution_tipo', $evolution_tipo);
    // Atualiza só os campos enviados no POST; não apaga credenciais dos outros modos ao trocar
    // de provedor (permite alternar entre os modos sem perder URL/chaves já gravadas).
    if ($evolution_tipo === 'propria') {
        if (array_key_exists('evolution_api_url', $input)) {
            setConfig('evolution_api_url', trim((string) $input['evolution_api_url']));
        }
        if (array_key_exists('evolution_api_key_global', $input)) {
            setConfig('evolution_api_key_global', trim((string) $input['evolution_api_key_global']));
        }
    } elseif ($evolution_tipo === 'uazapi' || $evolution_tipo === 'uazapi_propria') {
        if (array_key_exists('uazapi_api_url', $input)) {
            setConfig('uazapi_api_url', rtrim(trim((string) $input['uazapi_api_url']), '/'));
        }
        if ($evolution_tipo === 'uazapi_propria' && array_key_exists('uazapi_admin_token_global', $input)) {
            setConfig('uazapi_admin_token_global', trim((string) $input['uazapi_admin_token_global']));
        }
    }

    return ['tipo' => $evolution_tipo];
}

/**
 * Gera o HTML de uma linha da tabela de contas WhatsApp (inserção via AJAX após conectar QR).
 */
function achadinhosEvolutionRenderContaTableRowHtml(array $conta): string
{
    ob_start();
    $id = (int) ($conta['id'] ?? 0);
    $nomeH = htmlspecialchars((string) ($conta['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
    $nomeAttr = htmlspecialchars((string) ($conta['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
    $urlH = htmlspecialchars((string) ($conta['url_base'] ?? ''), ENT_QUOTES, 'UTF-8');
    $instH = htmlspecialchars((string) ($conta['instancia'] ?? ''), ENT_QUOTES, 'UTF-8');
    $provRow = $conta['provedor'] ?? 'evolution';
    $contaApiPropria = !empty($conta['api_propria'] ?? 0);
    $contaUazapi = $provRow === 'uazapi';
    $waLine = 'WhatsApp: sincronizando…';
    $waClass = 'evo-wa-state text-xs text-slate-600 max-w-[220px] leading-snug';
    if ($contaApiPropria) {
        try {
            $est = whatsAppObterEstadoConta($conta);
            if (!empty($est['connected'])) {
                $waLine = 'WhatsApp: Conectado';
                $waClass = 'evo-wa-state text-xs text-emerald-700 font-medium max-w-[220px] leading-snug';
            } elseif (!empty($est['ok'])) {
                $st = trim((string) ($est['state'] ?? ''));
                if ($st === '' || strtolower($st) === 'array') {
                    $waLine = 'WhatsApp: Aguardando conexão';
                } else {
                    $waLine = 'WhatsApp: ' . htmlspecialchars(achadinhosEvolutionHumanStateLabel($st), ENT_QUOTES, 'UTF-8');
                }
            } else {
                $waLine = 'WhatsApp: Sem resposta da API (offline ou credenciais)';
            }
        } catch (Exception $e) {
            $waLine = 'WhatsApp: —';
        }
    }
    ?>
                                <tr class="hover:bg-gray-50/50" data-evolution-conta-id="<?php echo $id; ?>">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <?php echo $nomeH; ?>
                                        <?php echo achadinhosEvolutionContaBadgesHtml($conta); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 truncate max-w-[180px] hidden md:table-cell" title="<?php echo $urlH; ?>"><?php echo $urlH; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono"><?php echo $instH; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($contaApiPropria): ?>
                                        <div class="flex flex-col gap-1 items-start">
                                            <?php if (!empty($conta['ativo'])): ?>
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Conta ativa</span>
                                            <?php else: ?>
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-gray-100 text-gray-600">Conta inativa</span>
                                            <?php endif; ?>
                                            <span class="<?php echo htmlspecialchars($waClass, ENT_QUOTES, 'UTF-8'); ?>" data-evolution-wa-id="<?php echo $id; ?>"><?php echo $waLine; ?></span>
                                        </div>
                                        <?php elseif (!empty($conta['ativo'])): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Ativa</span>
                                        <?php else: ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-gray-100 text-gray-600">Inativa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right align-top">
                                        <?php if ($contaApiPropria): ?>
                                        <div class="flex flex-wrap justify-end gap-x-2 gap-y-1.5 text-xs font-medium">
                                            <a href="?tab=evolution&edit_evolution=<?php echo $id; ?>" class="text-orange-600 hover:text-orange-800">Editar</a>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-status-refresh-btn" data-evolution-id="<?php echo $id; ?>">Atualizar</button>
                                            <button type="button" class="text-orange-600 hover:text-orange-800 evolution-escanear-qr-btn" data-evolution-id="<?php echo $id; ?>" data-evolution-nome="<?php echo $nomeAttr; ?>">QR</button>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-restart-btn" data-evolution-id="<?php echo $id; ?>">Reiniciar</button>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-logout-btn" data-evolution-id="<?php echo $id; ?>">Desconectar</button>
                                            <button type="button" class="text-orange-600 hover:text-orange-800 evolution-testar-btn" data-evolution-id="<?php echo $id; ?>" data-evolution-nome="<?php echo $nomeAttr; ?>">Testar</button>
                                            <form method="POST" action="?tab=evolution" class="inline" onsubmit="return confirm('Remover esta conta do painel e apagar a instância no servidor (Evolution ou Uazapi)?');">
                                                <input type="hidden" name="config_tab" value="evolution">
                                                <input type="hidden" name="evolution_action" value="delete">
                                                <input type="hidden" name="evolution_id" value="<?php echo $id; ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-600">Excluir</button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <?php if ($contaUazapi): ?>
                                        <button type="button" class="text-orange-600 hover:text-orange-700 text-xs font-medium mr-3 evolution-escanear-qr-btn" data-evolution-id="<?php echo $id; ?>" data-evolution-nome="<?php echo $nomeAttr; ?>">QR</button>
                                        <?php endif; ?>
                                        <button type="button" class="text-orange-600 hover:text-orange-700 text-xs font-medium mr-3 evolution-testar-btn" data-evolution-id="<?php echo $id; ?>" data-evolution-nome="<?php echo $nomeAttr; ?>">Testar</button>
                                        <a href="?tab=evolution&edit_evolution=<?php echo $id; ?>" class="text-orange-500 hover:text-orange-600 text-xs font-medium mr-3">Editar</a>
                                        <form method="POST" action="?tab=evolution" style="display: inline;" onsubmit="return confirm('Remover esta conta? Evolution e Uazapi (API própria) tentam apagar a instância na API; Uazapi provedor externo remove só o cadastro no painel.');">
                                            <input type="hidden" name="config_tab" value="evolution">
                                            <input type="hidden" name="evolution_action" value="delete">
                                            <input type="hidden" name="evolution_id" value="<?php echo $id; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-600 text-xs font-medium">Excluir</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
    <?php
    return trim((string) ob_get_clean());
}

// Evolution: criar instância e obter QR code (AJAX) – apenas para API própria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'create_instance') {
    header('Content-Type: application/json; charset=utf-8');
    $evolution_nome = trim($_POST['evolution_nome'] ?? '');
    $evolution_instancia = trim($_POST['evolution_instancia'] ?? '');
    $evolution_api_url = rtrim(trim($_POST['evolution_api_url'] ?? ''), '/');
    $evolution_api_key = trim($_POST['evolution_api_key'] ?? '');
    if (empty($evolution_nome) || empty($evolution_instancia) || empty($evolution_api_url) || empty($evolution_api_key)) {
        echo json_encode(['success' => false, 'message' => 'Preencha nome, instância, URL da API e API Key.']);
        exit;
    }
    $instancia = preg_replace('/[^a-zA-Z0-9_-]/', '', $evolution_instancia);
    if (empty($instancia)) {
        echo json_encode(['success' => false, 'message' => 'Nome da instância inválido (use letras, números, _ ou -).']);
        exit;
    }
    $url = $evolution_api_url . '/instance/create';
    $body = json_encode([
        'instanceName' => $instancia,
        'integration' => 'WHATSAPP-BAILEYS',
        'qrcode' => true,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $evolution_api_key],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 201 || $code === 200) {
        $j = json_decode($res, true);
        if (!is_array($j)) {
            echo json_encode(['success' => false, 'message' => 'Resposta inválida da Evolution API (JSON). HTTP ' . $code]);
            exit;
        }
        $instApikey = achadinhosEvolutionExtractInstanceApikeyFromCreateResponse($j, $evolution_api_key);
        $fromCreate = achadinhosEvolutionExtractQrFromJson($j);
        $qrBase64 = $fromCreate['qr'];
        $pairing = $fromCreate['pairing'];
        $conn = null;
        $conn2 = null;
        if ($qrBase64 === '' && ($pairing === null || $pairing === '')) {
            $conn = achadinhosEvolutionHttpGetConnect($evolution_api_url, $instancia, $instApikey);
            if ($conn['code'] === 200 && $conn['json'] !== null) {
                $ext = achadinhosEvolutionExtractQrFromJson($conn['json']);
                if ($ext['qr'] !== '') {
                    $qrBase64 = $ext['qr'];
                }
                if (($pairing === null || $pairing === '') && $ext['pairing'] !== null && $ext['pairing'] !== '') {
                    $pairing = $ext['pairing'];
                }
            }
        }
        if ($qrBase64 === '' && ($pairing === null || $pairing === '')) {
            $conn2 = achadinhosEvolutionHttpGetConnect($evolution_api_url, $instancia, $evolution_api_key);
            if ($conn2['code'] === 200 && $conn2['json'] !== null) {
                $ext2 = achadinhosEvolutionExtractQrFromJson($conn2['json']);
                if ($ext2['qr'] !== '') {
                    $qrBase64 = $ext2['qr'];
                }
                if (($pairing === null || $pairing === '') && $ext2['pairing'] !== null && $ext2['pairing'] !== '') {
                    $pairing = $ext2['pairing'];
                }
            }
        }
        $hasPairing = $pairing !== null && $pairing !== '';
        $qrMissing = ($qrBase64 === '' && !$hasPairing);

        $hasApiPropriaCol = false;
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApiPropriaCol = true;
        } catch (Exception $x) {
        }
        $hasProvCol = false;
        try {
            $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
            $hasProvCol = true;
        } catch (Exception $x) {
        }

        try {
            if ($hasProvCol && $hasApiPropriaCol) {
                $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, 1, 1, ?, ?)');
                $stmt->execute([$evolution_nome, $evolution_api_url, $instancia, $instApikey, 'evolution', null]);
            } elseif ($hasProvCol) {
                $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([$evolution_nome, $evolution_api_url, $instancia, $instApikey, 'evolution', null]);
            } elseif ($hasApiPropriaCol) {
                $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria) VALUES (?, ?, ?, ?, 1, 1)');
                $stmt->execute([$evolution_nome, $evolution_api_url, $instancia, $instApikey]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$evolution_nome, $evolution_api_url, $instancia, $instApikey]);
            }
            $contaId = (int) $pdo->lastInsertId();
            $payload = [
                'success' => true,
                'conta_id' => $contaId,
                'qr_code' => $qrBase64,
                'pairing_code' => $hasPairing ? $pairing : null,
            ];
            if ($qrMissing) {
                $payload['needs_qr'] = true;
                $payload['message'] = 'Instância criada na Evolution e salva no painel. Não foi possível obter QR agora (API lenta ou instável). Use «QR» ou «Atualizar» na lista para gerar quando a Evolution responder.';
            } else {
                $payload['message'] = 'Instância criada. Escaneie o QR code com o WhatsApp.';
            }
            echo json_encode($payload);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Instância criada na Evolution mas erro ao salvar no banco: ' . $e->getMessage()]);
        }
    } else {
        $j = @json_decode($res, true);
        $msg = $j['response']['message'][0] ?? $j['message'] ?? 'HTTP ' . $code;
        echo json_encode(['success' => false, 'message' => 'Evolution API: ' . $msg]);
    }
    exit;
}

// Uazapi: criar instância + QR (AJAX) — modo Configurações → WhatsApp → Uazapi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'uazapi_create_instance') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../config/uazapi_whatsapp.php';
    $nome = trim($_POST['uazapi_nome'] ?? '');
    $instancia = trim($_POST['uazapi_instancia'] ?? '');
    $tipoCfg = getConfig('evolution_tipo', 'terceiros');
    $apiUrl = rtrim(trim($_POST['uazapi_api_url'] ?? ''), '/');
    $adminTok = trim($_POST['uazapi_admin_token'] ?? '');
    if ($tipoCfg === 'uazapi_propria') {
        if ($apiUrl === '') {
            $apiUrl = rtrim(trim((string) getConfig('uazapi_api_url', '')), '/');
        }
        if ($adminTok === '') {
            $adminTok = trim((string) getConfig('uazapi_admin_token_global', ''));
        }
    }
    if ($nome === '' || $instancia === '' || $apiUrl === '' || $adminTok === '') {
        echo json_encode(['success' => false, 'message' => 'Preencha nome, instância, URL da Uazapi e token (no modo API própria Uazapi use também URL e token globais salvos).']);
        exit;
    }
    $init = uazapiInstanceInit($apiUrl, $adminTok, $instancia, $errInit);
    if (!$init['ok'] || empty($init['token'])) {
        echo json_encode(['success' => false, 'message' => $errInit ?: 'Falha ao criar instância na Uazapi.']);
        exit;
    }
    $instanceToken = $init['token'];
    $conn = uazapiInstanceConnect($apiUrl, $instanceToken, $adminTok, null);
    $qr = $conn['ok'] ? ($conn['qr'] ?? '') : '';
    try {
        $hasApiPropria = false;
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApiPropria = true;
        } catch (Exception $x) {
        }
        $hasProv = false;
        try {
            $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
            $hasProv = true;
        } catch (Exception $x) {
        }
        $apiPropriaUaz = ($tipoCfg === 'uazapi_propria') ? 1 : 0;
        if ($hasProv && $hasApiPropria) {
            $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
            $stmt->execute([$nome, $apiUrl, $instancia, $instanceToken, $apiPropriaUaz, 'uazapi', $adminTok]);
        } elseif ($hasProv) {
            $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([$nome, $apiUrl, $instancia, $instanceToken, 'uazapi', $adminTok]);
        } elseif ($hasApiPropria) {
            $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria) VALUES (?, ?, ?, ?, 1, ?)');
            $stmt->execute([$nome, $apiUrl, $instancia, $instanceToken, $apiPropriaUaz]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$nome, $apiUrl, $instancia, $instanceToken]);
        }
        $contaId = (int) $pdo->lastInsertId();
        echo json_encode([
            'success' => true,
            'message' => 'Instância Uazapi criada. Escaneie o QR code no WhatsApp.',
            'conta_id' => $contaId,
            'qr_code' => $qr,
            'pairing_code' => $conn['pairingCode'] ?? null,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao gravar conta: ' . $e->getMessage()]);
    }
    exit;
}

// WhatsApp: salvar provedor imediatamente (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'save_provider_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $saved = salvarConfigTipoWhatsApp($_POST);
        echo json_encode([
            'success' => true,
            'message' => 'Provedor salvo com sucesso.',
            'tipo' => $saved['tipo'],
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao salvar provedor: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// Evolution: atualizar QR code (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'refresh_qr') {
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int)($_POST['evolution_id'] ?? 0);
    if ($contaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Conta inválida.']);
        exit;
    }
    $hasApiPropria = false;
    try {
        $pdo->query("SELECT api_propria FROM evolution_contas LIMIT 1");
        $hasApiPropria = true;
    } catch (Exception $x) {}
    $hasProv = false;
    try {
        $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
        $hasProv = true;
    } catch (Exception $x) {
    }
    $cols = 'id, url_base, instancia, api_key' . ($hasApiPropria ? ', api_propria' : '') . ($hasProv ? ', provedor, uazapi_admin_token' : '');
    $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM evolution_contas WHERE id = ?');
    $stmt->execute([$contaId]);
    $conta = $stmt->fetch();
    if (!$conta) {
        echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
        exit;
    }
    $provedor = $hasProv ? ($conta['provedor'] ?? 'evolution') : 'evolution';
    if ($provedor === 'uazapi') {
        require_once __DIR__ . '/../config/uazapi_whatsapp.php';
        $conn = uazapiInstanceConnect(
            (string) ($conta['url_base'] ?? ''),
            (string) ($conta['api_key'] ?? ''),
            uazapiResolverAdminToken($conta),
            null
        );
        if ($conn['ok']) {
            echo json_encode([
                'success' => true,
                'qr_code' => $conn['qr'] ?? '',
                'pairing_code' => $conn['pairingCode'] ?? null,
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn['message'] ?? 'Falha ao obter QR code na Uazapi. Verifique URL, token da instância e token do painel.']);
        }
        exit;
    }
    if ($hasApiPropria && empty($conta['api_propria'])) {
        echo json_encode(['success' => false, 'message' => 'Esta conta não é da API própria. Use apenas para contas criadas pela Opção 2.']);
        exit;
    }
    $conn = achadinhosEvolutionHttpGetConnect(
        rtrim((string) $conta['url_base'], '/'),
        (string) $conta['instancia'],
        (string) $conta['api_key']
    );
    if ($conn['code'] === 200 && $conn['json'] !== null) {
        $ext = achadinhosEvolutionExtractQrFromJson($conn['json']);
        $qrBase64 = $ext['qr'];
        $pairing = $ext['pairing'];
        $hasPairing = $pairing !== null && $pairing !== '';
        if ($qrBase64 === '' && !$hasPairing) {
            echo json_encode([
                'success' => false,
                'message' => 'A API não devolveu QR nem código de pareamento neste momento. Aguarde alguns segundos e use «Atualizar QR code».',
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'qr_code' => $qrBase64,
                'pairing_code' => $hasPairing ? $pairing : null,
            ]);
        }
    } else {
        $snippet = mb_substr(preg_replace('/\s+/', ' ', $conn['body']), 0, 200);
        echo json_encode([
            'success' => false,
            'message' => 'Falha ao obter QR code. HTTP ' . $conn['code'] . ($snippet !== '' ? (' — ' . $snippet) : ''),
        ]);
    }
    exit;
}

// WhatsApp: status da conexão (AJAX) para polling do QR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'connection_status') {
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int) ($_POST['evolution_id'] ?? 0);
    if ($contaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Conta inválida.']);
        exit;
    }
    try {
        $hasProv = false;
        try {
            $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
            $hasProv = true;
        } catch (Exception $x) {
        }
        $hasApCs = false;
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApCs = true;
        } catch (Exception $x) {
        }
        $sel = 'id, nome, url_base, instancia, api_key' . ($hasProv ? ', provedor, uazapi_admin_token' : '')
            . ($hasApCs ? ', COALESCE(api_propria, 0) AS api_propria' : '');
        $stmt = $pdo->prepare('SELECT ' . $sel . ' FROM evolution_contas WHERE id = ?');
        $stmt->execute([$contaId]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conta) {
            echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
            exit;
        }
        $estado = whatsAppObterEstadoConta($conta);
        $connected = !empty($estado['connected']);
        $stateStr = trim((string) ($estado['state'] ?? ''));
        if ($stateStr === 'array') {
            $stateStr = '';
        }
        $stateNorm = strtolower($stateStr);
        if ($connected) {
            $stateLabel = 'Conectado';
        } else {
            $stateLabel = !empty($estado['ok'])
                ? achadinhosEvolutionHumanStateLabel($stateStr !== '' ? $stateStr : '')
                : 'Sem resposta da API (offline ou credenciais)';
        }
        echo json_encode([
            'success' => true,
            'connected' => $connected,
            'state' => $stateStr,
            'state_norm' => $stateNorm,
            'state_label' => $stateLabel,
            'ok' => !empty($estado['ok']),
            'conta_id' => (int) $conta['id'],
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao consultar status: ' . $e->getMessage()]);
    }
    exit;
}

// Linha da tabela de contas (AJAX) — após conectar QR sem recarregar a página
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'conta_row_html') {
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int) ($_POST['evolution_id'] ?? 0);
    if ($contaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Conta inválida.']);
        exit;
    }
    try {
        $stRow = $pdo->prepare('SELECT * FROM evolution_contas WHERE id = ?');
        $stRow->execute([$contaId]);
        $row = $stRow->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'html' => achadinhosEvolutionRenderContaTableRowHtml($row),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Evolution API própria: reiniciar instância (PUT /instance/restart/{instance})
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'evolution_restart') {
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int) ($_POST['evolution_id'] ?? 0);
    if ($contaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Conta inválida.']);
        exit;
    }
    try {
        $hasApiPropria = false;
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApiPropria = true;
        } catch (Exception $x) {
        }
        $hasProv = false;
        try {
            $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
            $hasProv = true;
        } catch (Exception $x) {
        }
        $cols = 'id, url_base, instancia, api_key' . ($hasApiPropria ? ', api_propria' : '') . ($hasProv ? ', provedor, uazapi_admin_token' : '');
        $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM evolution_contas WHERE id = ?');
        $stmt->execute([$contaId]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conta) {
            echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
            exit;
        }
        if (($conta['provedor'] ?? 'evolution') === 'uazapi') {
            if (!$hasApiPropria || empty($conta['api_propria'])) {
                echo json_encode(['success' => false, 'message' => 'Reinício na Uazapi está disponível apenas para contas criadas no modo «API própria Uazapi».']);
                exit;
            }
            require_once __DIR__ . '/../config/uazapi_whatsapp.php';
            $r = uazapiInstanceRestartRuntime(
                (string) ($conta['url_base'] ?? ''),
                (string) ($conta['api_key'] ?? ''),
                uazapiResolverAdminToken($conta)
            );
            if ($r['code'] >= 200 && $r['code'] < 300) {
                echo json_encode(['success' => true, 'message' => 'Runtime da instância reiniciado na Uazapi.']);
            } else {
                $snippet = mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200);
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha ao reiniciar na Uazapi. HTTP ' . $r['code'] . ($snippet !== '' ? (' — ' . $snippet) : ''),
                ]);
            }
            exit;
        }
        if ($hasApiPropria && empty($conta['api_propria'])) {
            echo json_encode(['success' => false, 'message' => 'Reinício disponível apenas para instâncias da API própria.']);
            exit;
        }
        $baseR = rtrim((string) $conta['url_base'], '/');
        $instR = (string) $conta['instancia'];
        $keyR = (string) $conta['api_key'];
        $r = achadinhosEvolutionHttpRestart($baseR, $instR, $keyR);
        if (($r['code'] < 200 || $r['code'] >= 300) && $hasApiPropria && !empty($conta['api_propria'])) {
            $gKey = trim((string) getConfig('evolution_api_key_global', ''));
            if ($gKey !== '' && $gKey !== $keyR) {
                $r = achadinhosEvolutionHttpRestart($baseR, $instR, $gKey);
            }
        }
        if ($r['code'] >= 200 && $r['code'] < 300) {
            echo json_encode(['success' => true, 'message' => 'Instância reiniciada na Evolution.']);
        } else {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200);
            echo json_encode([
                'success' => false,
                'message' => 'Falha ao reiniciar. HTTP ' . $r['code'] . ($snippet !== '' ? (' — ' . $snippet) : ''),
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Evolution API própria: desconectar instância (DELETE /instance/logout/{instance})
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'evolution_logout') {
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int) ($_POST['evolution_id'] ?? 0);
    if ($contaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Conta inválida.']);
        exit;
    }
    try {
        $hasApiPropria = false;
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApiPropria = true;
        } catch (Exception $x) {
        }
        $hasProv = false;
        try {
            $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
            $hasProv = true;
        } catch (Exception $x) {
        }
        $cols = 'id, url_base, instancia, api_key' . ($hasApiPropria ? ', api_propria' : '') . ($hasProv ? ', provedor, uazapi_admin_token' : '');
        $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM evolution_contas WHERE id = ?');
        $stmt->execute([$contaId]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conta) {
            echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
            exit;
        }
        if (($conta['provedor'] ?? 'evolution') === 'uazapi') {
            if (!$hasApiPropria || empty($conta['api_propria'])) {
                echo json_encode(['success' => false, 'message' => 'Desconectar na Uazapi está disponível apenas para contas do modo «API própria Uazapi».']);
                exit;
            }
            require_once __DIR__ . '/../config/uazapi_whatsapp.php';
            $r = uazapiInstanceDisconnect(
                (string) ($conta['url_base'] ?? ''),
                (string) ($conta['api_key'] ?? ''),
                uazapiResolverAdminToken($conta)
            );
            if ($r['code'] >= 200 && $r['code'] < 300) {
                echo json_encode(['success' => true, 'message' => 'WhatsApp desconectado na Uazapi (será necessário novo QR para conectar).']);
            } else {
                $snippet = mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200);
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha ao desconectar na Uazapi. HTTP ' . $r['code'] . ($snippet !== '' ? (' — ' . $snippet) : ''),
                ]);
            }
            exit;
        }
        if ($hasApiPropria && empty($conta['api_propria'])) {
            echo json_encode(['success' => false, 'message' => 'Desconectar disponível apenas para instâncias da API própria.']);
            exit;
        }
        $baseL = rtrim((string) $conta['url_base'], '/');
        $instL = (string) $conta['instancia'];
        $keyL = (string) $conta['api_key'];
        $r = achadinhosEvolutionHttpLogout($baseL, $instL, $keyL);
        if (($r['code'] < 200 || $r['code'] >= 300) && $hasApiPropria && !empty($conta['api_propria'])) {
            $gKey = trim((string) getConfig('evolution_api_key_global', ''));
            if ($gKey !== '' && $gKey !== $keyL) {
                $r = achadinhosEvolutionHttpLogout($baseL, $instL, $gKey);
            }
        }
        if ($r['code'] >= 200 && $r['code'] < 300) {
            echo json_encode(['success' => true, 'message' => 'Instância desconectada na Evolution.']);
        } else {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200);
            echo json_encode([
                'success' => false,
                'message' => 'Falha ao desconectar. HTTP ' . $r['code'] . ($snippet !== '' ? (' — ' . $snippet) : ''),
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Teste de conexão Evolution (AJAX) – retorna JSON e encerra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    $evolution_id = isset($_POST['evolution_id']) ? (int)$_POST['evolution_id'] : 0;
    $numero = trim(preg_replace('/\D/', '', $_POST['evolution_test_number'] ?? ''));
    if ($evolution_id <= 0 || strlen($numero) < 10) {
        echo json_encode(['success' => false, 'message' => 'Informe uma conta e um número válido (com DDD).']);
        exit;
    }
    // Brasil: se tiver 10 ou 11 dígitos sem código do país, adiciona 55
    if (strlen($numero) >= 10 && strlen($numero) <= 11 && substr($numero, 0, 2) !== '55') {
        $numero = '55' . $numero;
    }
    $hasProvCol = false;
    try {
        $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
        $hasProvCol = true;
    } catch (Exception $x) {
    }
    $sel = 'id, nome, url_base, instancia, api_key' . ($hasProvCol ? ', provedor, uazapi_admin_token' : '');
    $stmt = $pdo->prepare('SELECT ' . $sel . ' FROM evolution_contas WHERE id = ?');
    $stmt->execute([$evolution_id]);
    $conta = $stmt->fetch();
    if (!$conta) {
        echo json_encode(['success' => false, 'message' => 'Conta não encontrada.']);
        exit;
    }
    require_once __DIR__ . '/../config/automacao-ml.php';
    $mensagemTeste = "✅ Teste de conexão – AfiliadosPro\n\nSe você recebeu esta mensagem, o WhatsApp está conectado corretamente.";
    $err = '';
    $evo = [
        'url_base' => $conta['url_base'],
        'instancia' => $conta['instancia'],
        'api_key' => $conta['api_key'],
        'provedor' => $hasProvCol ? ($conta['provedor'] ?? 'evolution') : 'evolution',
        'uazapi_admin_token' => $hasProvCol ? ($conta['uazapi_admin_token'] ?? '') : '',
    ];
    $ok = enviarWhatsAppMensagem($evo, $numero, $mensagemTeste, null, $err);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Mensagem de teste enviada. Verifique o WhatsApp no número informado.']);
    } else {
        echo json_encode(['success' => false, 'message' => $err ?: 'Falha ao enviar. Verifique URL, instância e credenciais (API Key ou token Uazapi).']);
    }
    exit;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $configTabEarly = (string) ($_POST['config_tab'] ?? '');
    if (isset($_POST['cron_monitor_menu_submit']) && $configTabEarly === 'crons') {
        $acao = (string) ($_POST['cron_monitor_menu_acao'] ?? '0');
        setConfig('cron_monitor_menu_ativo', $acao === '1' ? '1' : '0');
        header('Location: configuracoes.php?tab=crons');
        exit;
    }

    // Debug: verificar se POST está chegando
    error_log("=== POST RECEBIDO EM CONFIGURACOES ===");
    error_log("POST data: " . print_r($_POST, true));
    
    $erros = [];
    $debug = [];
    $debug[] = 'POST recebido';
    $configTab = $_POST['config_tab'] ?? '';
    $bannerDeletado = false;
    $result1 = $result2 = $result3 = true;
    
    // Só processa configurações da aba Geral quando o formulário da aba Geral foi enviado
    if ($configTab === 'geral') {
    $debug[] = 'categorias_topbar: ' . ($_POST['categorias_topbar'] ?? 'não definido');
    $debug[] = 'footer_email: ' . ($_POST['footer_email'] ?? 'não definido');
    $debug[] = 'footer_instagram: ' . ($_POST['footer_instagram'] ?? 'não definido');
    $debug[] = 'footer_facebook: ' . ($_POST['footer_facebook'] ?? 'não definido');
    $debug[] = 'banner_type: ' . ($_POST['banner_type'] ?? 'não definido');
    
    if (!empty($_POST['remove_logo'])) {
        $logoAntigo = getConfig('logo');
        if (!empty($logoAntigo)) {
            deleteImagem($logoAntigo);
        }
        setConfig('logo', '');
    }
    if (!empty($_POST['remove_favicon'])) {
        $favAntigo = getConfig('favicon');
        if (!empty($favAntigo)) {
            deleteImagem($favAntigo);
        }
        setConfig('favicon', '');
    }
    
    // Logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logo = uploadImagem($_FILES['logo'], 'uploads/');
        if ($logo) {
            $logoAntigo = getConfig('logo');
            if (!empty($logoAntigo)) {
                deleteImagem($logoAntigo);
            }
            setConfig('logo', $logo);
        }
    }
    
    // Favicon
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $favicon = uploadImagem($_FILES['favicon'], 'uploads/');
        if ($favicon) {
            $faviconAntigo = getConfig('favicon');
            if (!empty($faviconAntigo)) {
                deleteImagem($faviconAntigo);
            }
            setConfig('favicon', $favicon);
        }
    }
    
    if (isset($_POST['site_nome_marca'])) {
        $sn = trim((string) $_POST['site_nome_marca']);
        if ($sn === '') {
            setConfig('site_nome_marca', 'OfertasJá');
        } else {
            $sn = function_exists('mb_substr') ? mb_substr($sn, 0, 80) : substr($sn, 0, 80);
            setConfig('site_nome_marca', $sn);
        }
    }
    $modoMarca = isset($_POST['site_marca_modo']) ? (string) $_POST['site_marca_modo'] : '';
    if (in_array($modoMarca, ['logo', 'texto', 'ambos'], true)) {
        setConfig('site_marca_modo', $modoMarca);
    }
    
    // Cor do tema (hex)
    if (isset($_POST['tema_cor'])) {
        $temaCor = trim($_POST['tema_cor']);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $temaCor)) {
            setConfig('tema_cor', $temaCor);
        }
    }
    
    // Categorias topbar (permite vazio; deduplica e alinha nomes ao BD quando possível)
    $categoriasTopbar = isset($_POST['categorias_topbar']) ? $_POST['categorias_topbar'] : '';
    $categoriasTopbar = function_exists('achadinhosNormalizarListaTopbar')
        ? achadinhosNormalizarListaTopbar($categoriasTopbar)
        : $categoriasTopbar;
    if ($categoriasTopbar !== '' && function_exists('achadinhosSincronizarCategoriasTopbarComBd')) {
        $categoriasTopbar = achadinhosSincronizarCategoriasTopbarComBd($pdo, $categoriasTopbar);
    }
    $debug[] = 'Salvando categorias_topbar: "' . substr($categoriasTopbar, 0, 50) . '"';
    setConfig('categorias_topbar', $categoriasTopbar);
    
    // Carregar banners atuais primeiro
    $bannersAtuais = json_decode(getConfig('banners', '[]'), true) ?: [];
    $bannerDeletado = false;
    
    // Deletar banner ANTES de processar upload (prioridade)
    if (isset($_POST['delete_banner']) && $_POST['delete_banner'] !== '') {
        $index = (int)$_POST['delete_banner'];
        
        if ($index >= 0 && isset($bannersAtuais[$index])) {
            // Deletar arquivo físico
            deleteImagem($bannersAtuais[$index]);
            // Remover do array
            unset($bannersAtuais[$index]);
            // Reindexar array para evitar índices quebrados
            $bannersAtuais = array_values(array_filter($bannersAtuais));
            
            setConfig('banners', json_encode($bannersAtuais));
            $bannerDeletado = true;
        }
    }
    
    // Tipo de banner (imagens ou JSON padrão)
    $bannerType = isset($_POST['banner_type']) ? $_POST['banner_type'] : 'images';
    if (empty($bannerType)) {
        $bannerType = 'images';
    }
    $debug[] = 'Salvando banner_type: ' . $bannerType;
    setConfig('banner_type', $bannerType);
    
    // Banners JSON padrão
    if (isset($_POST['banners_json']) && $bannerType === 'default') {
        $bannersJsonValue = $_POST['banners_json'];
        $debug[] = 'Salvando banners_json (tamanho: ' . strlen($bannersJsonValue) . ' chars)';
        setConfig('banners_json', $bannersJsonValue);
    }
    
    // Banners - Upload de novas imagens (apenas se tipo for imagens)
    if ($bannerType === 'images' && isset($_FILES['banner_images']) && !empty($_FILES['banner_images']['name'][0])) {
        $bannersAtuais = json_decode(getConfig('banners', '[]'), true) ?: [];
        
        foreach ($_FILES['banner_images']['name'] as $key => $name) {
            if ($_FILES['banner_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['banner_images']['name'][$key],
                    'type' => $_FILES['banner_images']['type'][$key],
                    'tmp_name' => $_FILES['banner_images']['tmp_name'][$key],
                    'error' => $_FILES['banner_images']['error'][$key],
                    'size' => $_FILES['banner_images']['size'][$key]
                ];
                
                $bannerImage = uploadImagem($file, 'uploads/banners/');
                if ($bannerImage) {
                    $bannersAtuais[] = $bannerImage;
                }
            }
        }
        
        // Remover valores vazios e garantir que são strings
        $bannersAtuais = array_values(array_filter(array_map('trim', $bannersAtuais)));
        
        setConfig('banners', json_encode($bannersAtuais));
    }
    
    // Footer (permite vazios)
    $footerEmail = isset($_POST['footer_email']) ? $_POST['footer_email'] : '';
    $footerInstagram = isset($_POST['footer_instagram']) ? $_POST['footer_instagram'] : '';
    $footerFacebook = isset($_POST['footer_facebook']) ? $_POST['footer_facebook'] : '';
    
    $debug[] = 'Salvando footer_email: "' . $footerEmail . '"';
    $debug[] = 'Salvando footer_instagram: "' . $footerInstagram . '"';
    $debug[] = 'Salvando footer_facebook: "' . $footerFacebook . '"';
    
    $result1 = setConfig('footer_email', $footerEmail);
    $result2 = setConfig('footer_instagram', $footerInstagram);
    $result3 = setConfig('footer_facebook', $footerFacebook);
    
    // Link do Grupo do WhatsApp (topbar)
    $whatsappGrupoBotaoTexto = isset($_POST['whatsapp_grupo_botao_texto']) ? trim((string) $_POST['whatsapp_grupo_botao_texto']) : '';
    if (mb_strlen($whatsappGrupoBotaoTexto, 'UTF-8') > 120) {
        $whatsappGrupoBotaoTexto = mb_substr($whatsappGrupoBotaoTexto, 0, 120, 'UTF-8');
    }
    setConfig('whatsapp_grupo_botao_texto', $whatsappGrupoBotaoTexto);
    $whatsappGrupoUrl = isset($_POST['whatsapp_grupo_url']) ? trim($_POST['whatsapp_grupo_url']) : '';
    setConfig('whatsapp_grupo_url', $whatsappGrupoUrl);
    
    // Popup (imagem + link para o grupo)
    if (isset($_POST['popup_ativo'])) {
        setConfig('popup_ativo', '1');
    } else {
        setConfig('popup_ativo', '0');
    }
    if (isset($_FILES['popup_imagem']) && $_FILES['popup_imagem']['error'] === UPLOAD_ERR_OK) {
        $popupImg = uploadImagem($_FILES['popup_imagem'], 'uploads/');
        if ($popupImg) {
            $popupAntigo = getConfig('popup_imagem');
            if (!empty($popupAntigo)) deleteImagem($popupAntigo);
            setConfig('popup_imagem', $popupImg);
        }
    }
    } // fim if config_tab === 'geral'
    
    // IA Global (OpenAI + Gemini) - só quando formulário da aba IA foi enviado
    if ($configTab === 'openai') {
    if (isset($_POST['openai_api_key'])) {
        setConfig('openai_api_key', trim($_POST['openai_api_key']));
    }
    if (isset($_POST['gemini_api_key'])) {
        setConfig('gemini_api_key', trim($_POST['gemini_api_key']));
    }
    $iaCatProv = trim((string) ($_POST['ia_categoria_provedor'] ?? 'none'));
    if (!in_array($iaCatProv, ['none', 'openai', 'gemini'], true)) {
        $iaCatProv = 'none';
    }
    setConfig('ia_categoria_provedor', $iaCatProv);
    setConfig('ia_categoria_usar_gemini', $iaCatProv === 'gemini' ? '1' : '0');
    } // fim if config_tab === 'openai'
    
    // Telegram
    if ($configTab === 'telegram') {
        if (isset($_POST['telegram_bot_token'])) {
            setConfig('telegram_bot_token', trim($_POST['telegram_bot_token']));
        }
        if (isset($_POST['telegram_chat_id'])) {
            setConfig('telegram_chat_id', trim($_POST['telegram_chat_id']));
        }
        if (isset($_POST['telegram_business_connection_id'])) {
            setConfig('telegram_business_connection_id', trim((string) $_POST['telegram_business_connection_id']));
        }
        if (isset($_POST['telegram_ativo'])) {
            setConfig('telegram_ativo', '1');
        } else {
            setConfig('telegram_ativo', '0');
        }
        if (isset($_POST['dispatch_admin_id'])) {
            setConfig('dispatch_admin_id', (string) max(1, (int) ($_POST['dispatch_admin_id'] ?? 1)));
        }
        if (isset($_POST['dispatch_ativo_producao'])) {
            setConfig('dispatch_ativo_producao', '1');
        } else {
            setConfig('dispatch_ativo_producao', '0');
        }
    }
    
    // Evolution / Uazapi - Salvar configuração (tipo: terceiros, própria ou uazapi)
    if (isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'save_config') {
        salvarConfigTipoWhatsApp($_POST);
    }
    
    // Evolution / Uazapi - Adicionar/Editar conta (provedor externo ou Uazapi manual)
    if (isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'save') {
        $evolution_id = isset($_POST['evolution_id']) ? (int)$_POST['evolution_id'] : null;
        $evolution_nome = trim($_POST['evolution_nome'] ?? '');
        $evolution_url_base = rtrim(trim($_POST['evolution_url_base'] ?? ''), '/');
        $evolution_instancia = trim($_POST['evolution_instancia'] ?? '');
        $evolution_api_key = trim($_POST['evolution_api_key'] ?? '');
        $uazapi_admin_token_save = trim($_POST['uazapi_admin_token'] ?? '');
        $evolution_ativo = isset($_POST['evolution_ativo']) ? 1 : 0;
        $evolutionTipoCfg = getConfig('evolution_tipo', 'terceiros');
        $existingRow = null;
        if ($evolution_id) {
            try {
                $stEx = $pdo->prepare('SELECT * FROM evolution_contas WHERE id = ?');
                $stEx->execute([$evolution_id]);
                $existingRow = $stEx->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Exception $x) {
            }
        }
        // Edição: mantém o provedor da conta; nova conta: segue o tipo selecionado no painel.
        $contaEhUazapi = ($evolution_id && $existingRow)
            ? (($existingRow['provedor'] ?? '') === 'uazapi')
            : in_array($evolutionTipoCfg, ['uazapi', 'uazapi_propria'], true);
        $provedorSave = $contaEhUazapi ? 'uazapi' : 'evolution';
        $uazapiTokDb = null;
        if ($contaEhUazapi) {
            if ($evolution_id && $uazapi_admin_token_save === '' && $existingRow) {
                $uazapiTokDb = $existingRow['uazapi_admin_token'] ?? null;
            } else {
                $uazapiTokDb = $uazapi_admin_token_save !== '' ? $uazapi_admin_token_save : null;
            }
        }

        if (empty($evolution_nome) || empty($evolution_url_base) || empty($evolution_instancia) || empty($evolution_api_key)) {
            $erros[] = 'Todos os campos da conta WhatsApp são obrigatórios!';
        } else {
            try {
                $hasApiPropria = false;
                try {
                    $pdo->query("SELECT api_propria FROM evolution_contas LIMIT 1");
                    $hasApiPropria = true;
                } catch (Exception $x) {}
                $hasProv = false;
                try {
                    $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
                    $hasProv = true;
                } catch (Exception $x) {
                }
                $apiPropriaVal = 0;
                if ($evolution_id) {
                    if ($hasProv) {
                        $stmt = $pdo->prepare('UPDATE evolution_contas SET nome = ?, url_base = ?, instancia = ?, api_key = ?, ativo = ?, provedor = ?, uazapi_admin_token = ? WHERE id = ?');
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo, $provedorSave, $uazapiTokDb, $evolution_id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE evolution_contas SET nome = ?, url_base = ?, instancia = ?, api_key = ?, ativo = ? WHERE id = ?');
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo, $evolution_id]);
                    }
                } else {
                    if ($hasProv && $hasApiPropria) {
                        $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo, $apiPropriaVal, $provedorSave, $uazapiTokDb]);
                    } elseif ($hasProv) {
                        $stmt = $pdo->prepare('INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, provedor, uazapi_admin_token) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo, $provedorSave, $uazapiTokDb]);
                    } elseif ($hasApiPropria) {
                        $stmt = $pdo->prepare("INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo, api_propria) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo, $apiPropriaVal]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO evolution_contas (nome, url_base, instancia, api_key, ativo) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$evolution_nome, $evolution_url_base, $evolution_instancia, $evolution_api_key, $evolution_ativo]);
                    }
                }
            } catch (Exception $e) {
                $erros[] = 'Erro ao salvar conta: ' . $e->getMessage();
            }
        }
    }
    
    // Evolution - Deletar conta (tenta DELETE /instance/delete/{instance} na API; remove sempre do painel)
    if (isset($_POST['evolution_action']) && $_POST['evolution_action'] === 'delete' && isset($_POST['evolution_id'])) {
        $delId = (int) $_POST['evolution_id'];
        try {
            $hasProv = false;
            try {
                $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
                $hasProv = true;
            } catch (Exception $x) {
            }
            $hasApiPropriaCol = false;
            try {
                $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
                $hasApiPropriaCol = true;
            } catch (Exception $x) {
            }
            $cols = 'id, url_base, instancia, api_key' . ($hasApiPropriaCol ? ', api_propria' : '') . ($hasProv ? ', provedor, uazapi_admin_token' : '');
            $stRow = $pdo->prepare('SELECT ' . $cols . ' FROM evolution_contas WHERE id = ?');
            $stRow->execute([$delId]);
            $rowDel = $stRow->fetch(PDO::FETCH_ASSOC);
            if ($rowDel) {
                $provDel = $hasProv ? ($rowDel['provedor'] ?? 'evolution') : 'evolution';
                if ($provDel === 'uazapi' && $hasApiPropriaCol && !empty($rowDel['api_propria'])) {
                    require_once __DIR__ . '/../config/uazapi_whatsapp.php';
                    $baseU = rtrim(trim((string) ($rowDel['url_base'] ?? '')), '/');
                    $tokU = trim((string) ($rowDel['api_key'] ?? ''));
                    if ($baseU !== '' && $tokU !== '') {
                        $apiDel = uazapiInstanceDeleteNaApi($baseU, $tokU, uazapiResolverAdminToken($rowDel));
                        $okRemote = ($apiDel['code'] >= 200 && $apiDel['code'] < 300) || $apiDel['code'] === 404;
                        if (!$okRemote) {
                            $snippet = mb_substr(preg_replace('/\s+/', ' ', $apiDel['body']), 0, 180);
                            $_SESSION['evolution_delete_warn'] = 'A conta foi removida deste painel, mas a Uazapi não confirmou exclusão da instância (HTTP '
                                . $apiDel['code'] . ($snippet !== '' ? ': ' . $snippet : '')
                                . '). Se ainda existir no servidor, apague-a no painel da Uazapi.';
                        }
                    }
                } elseif ($provDel !== 'uazapi') {
                    $base = rtrim(trim((string) ($rowDel['url_base'] ?? '')), '/');
                    $inst = trim((string) ($rowDel['instancia'] ?? ''));
                    $key = trim((string) ($rowDel['api_key'] ?? ''));
                    if ($base !== '' && $inst !== '') {
                        $apiDel = ['code' => 0, 'body' => ''];
                        $okRemote = false;
                        if ($key !== '') {
                            $apiDel = achadinhosEvolutionHttpDeleteInstance($base, $inst, $key);
                            $okRemote = ($apiDel['code'] >= 200 && $apiDel['code'] < 300) || $apiDel['code'] === 404;
                        }
                        if (!$okRemote && $hasApiPropriaCol && !empty($rowDel['api_propria'])) {
                            $gKey = trim((string) getConfig('evolution_api_key_global', ''));
                            if ($gKey !== '' && $gKey !== $key) {
                                $apiDel = achadinhosEvolutionHttpDeleteInstance($base, $inst, $gKey);
                                $okRemote = ($apiDel['code'] >= 200 && $apiDel['code'] < 300) || $apiDel['code'] === 404;
                            }
                        }
                        if (!$okRemote && $key !== '') {
                            $snippet = mb_substr(preg_replace('/\s+/', ' ', $apiDel['body']), 0, 180);
                            $_SESSION['evolution_delete_warn'] = 'A conta foi removida deste painel, mas a Evolution API não confirmou exclusão da instância (HTTP '
                                . $apiDel['code'] . ($snippet !== '' ? ': ' . $snippet : '')
                                . '). Se a instância ainda existir no servidor, apague-a no painel da Evolution.';
                        }
                    }
                }
                $pdo->prepare('DELETE FROM evolution_contas WHERE id = ?')->execute([$delId]);
            }
        } catch (Exception $e) {
            $erros[] = 'Erro ao excluir conta: ' . $e->getMessage();
        }
    }
    
    // Crons - API cron-job.org + URL pública (rodar-tudo). Horários de postagem ficam por grupo (Grupos).
    if (isset($_POST['crons_action']) && $_POST['crons_action'] === 'save') {
        require_once __DIR__ . '/../core/cron/CronJobService.php';
        if (isset($_POST['produtos_dias_expiracao'])) {
            setConfig('produtos_dias_expiracao', (string) max(1, min(365, (int)$_POST['produtos_dias_expiracao'])));
        }
        if (isset($_POST['cron_job_org_api_key'])) {
            setConfig('cron_job_org_api_key', trim((string) $_POST['cron_job_org_api_key']));
        }
        if (isset($_POST['cron_public_base_url'])) {
            setConfig('cron_public_base_url', rtrim(trim((string) $_POST['cron_public_base_url']), '/'));
        }

        $apiKeyApos = trim((string) getConfig('cron_job_org_api_key', ''));
        if ($apiKeyApos === '') {
            setConfig('cron_global_job_id', '');
            setConfig('cron_global_last_synced_job_host', '');
        } else {
            setConfig('cron_global_sync_last_error', '');
            $_SESSION['cron_sync_ok_msg'] = 'Crons: chave API guardada. Cada grupo passa a poder sincronizar o seu job na cron-job.org (página Grupos).';
        }
    }
    
    // Conta admin: foto do perfil / usuário e senha
    if ($configTab === 'conta') {
        $contaTabHandled = false;
        $adminIdSess = (int) ($_SESSION['admin_id'] ?? 0);

        if (!empty($_POST['admin_save_avatar_only'])) {
            $contaTabHandled = true;
            if ($adminIdSess <= 0) {
                $erros[] = 'Sessão inválida.';
            } elseif (isset($_FILES['admin_avatar']) && $_FILES['admin_avatar']['error'] === UPLOAD_ERR_OK) {
                ensureAdminAvatarColumn();
                $nova = uploadImagem($_FILES['admin_avatar'], 'uploads/admin_avatars/');
                if ($nova) {
                    $antigo = getAdminAvatarPathById($adminIdSess);
                    if ($antigo !== '' && $antigo !== $nova) {
                        deleteImagem($antigo);
                    }
                    $pdo->prepare('UPDATE admins SET avatar = ? WHERE id = ?')->execute([$nova, $adminIdSess]);
                    header('Location: configuracoes.php?avatar_ok=1&tab=conta');
                    exit;
                }
                $erros[] = 'Não foi possível enviar a foto. Use JPG, PNG, GIF ou WebP.';
            } else {
                $erros[] = 'Selecione uma imagem para enviar.';
            }
        } elseif (!empty($_POST['admin_remove_avatar'])) {
            $contaTabHandled = true;
            if ($adminIdSess <= 0) {
                $erros[] = 'Sessão inválida.';
            } else {
                ensureAdminAvatarColumn();
                $antigo = getAdminAvatarPathById($adminIdSess);
                if ($antigo !== '') {
                    deleteImagem($antigo);
                }
                $pdo->prepare('UPDATE admins SET avatar = NULL WHERE id = ?')->execute([$adminIdSess]);
                header('Location: configuracoes.php?avatar_removed=1&tab=conta');
                exit;
            }
        }

        if (!$contaTabHandled) {
            $senhaAtual = $_POST['admin_senha_atual'] ?? '';
            $novoUsuario = trim($_POST['admin_username'] ?? '');
            $novaSenha = $_POST['admin_senha_nova'] ?? '';
            $novaSenhaConfirma = $_POST['admin_senha_nova_confirm'] ?? '';

            if (empty($senhaAtual)) {
                $erros[] = 'Informe a senha atual para alterar usuário ou senha.';
            } else {
                $adminId = (int) ($_SESSION['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id, username, password FROM admins WHERE id = ?");
                $stmt->execute([$adminId]);
                $admin = $stmt->fetch();
                if (!$admin || !password_verify($senhaAtual, $admin['password'])) {
                    $erros[] = 'Senha atual incorreta.';
                } else {
                    $atualizarUsuario = ($novoUsuario !== '' && $novoUsuario !== $admin['username']);
                    $atualizarSenha = ($novaSenha !== '');
                    if (!$atualizarUsuario && !$atualizarSenha) {
                        $erros[] = 'Informe um novo nome de usuário e/ou uma nova senha para alterar.';
                    }
                    if ($atualizarUsuario) {
                        if (strlen($novoUsuario) < 3) {
                            $erros[] = 'O novo usuário deve ter no mínimo 3 caracteres.';
                        } else {
                            $stmtCheck = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
                            $stmtCheck->execute([$novoUsuario, $adminId]);
                            if ($stmtCheck->fetch()) {
                                $erros[] = 'Este nome de usuário já está em uso.';
                            }
                        }
                    }
                    if ($atualizarSenha) {
                        if (strlen($novaSenha) < 6) {
                            $erros[] = 'A nova senha deve ter no mínimo 6 caracteres.';
                        } elseif ($novaSenha !== $novaSenhaConfirma) {
                            $erros[] = 'A confirmação da nova senha não confere.';
                        }
                    }
                    if (empty($erros)) {
                        try {
                            if ($atualizarUsuario && $atualizarSenha) {
                                $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
                                $stmt->execute([$novoUsuario, $hash, $adminId]);
                                $_SESSION['admin_username'] = $novoUsuario;
                            } elseif ($atualizarUsuario) {
                                $stmt = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
                                $stmt->execute([$novoUsuario, $adminId]);
                                $_SESSION['admin_username'] = $novoUsuario;
                            } elseif ($atualizarSenha) {
                                $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
                                $stmt->execute([$hash, $adminId]);
                            }
                            if ($atualizarUsuario || $atualizarSenha) {
                                header('Location: configuracoes.php?account_updated=1&tab=conta');
                                exit;
                            }
                        } catch (Exception $e) {
                            $erros[] = 'Erro ao atualizar: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
    
    if ($configTab === 'geral') {
        $debug[] = 'Resultado footer_email: ' . ($result1 ? 'OK' : 'ERRO');
        $debug[] = 'Resultado footer_instagram: ' . ($result2 ? 'OK' : 'ERRO');
        $debug[] = 'Resultado footer_facebook: ' . ($result3 ? 'OK' : 'ERRO');
        if (!$result1 || !$result2 || !$result3) {
            $erros[] = 'Erro ao salvar algumas configurações. Verifique os erros acima.';
        }
    }
    
    // Debug: verificar resultados
    $debug[] = 'Total de erros: ' . count($erros);
    $debug[] = 'bannerDeletado: ' . ($bannerDeletado ? 'sim' : 'não');
    
    // Redirecionar para evitar reenvio do formulário e mostrar mensagem
    $redirectMsg = $bannerDeletado ? 'delete=1' : 'success=1';
    if (!empty($erros)) {
        $redirectMsg = 'error=' . urlencode(implode(', ', $erros));
        unset($_SESSION['cron_sync_ok_msg'], $_SESSION['cron_sync_warn_msg']);
    }
    if ($configTab) {
        $redirectMsg .= '&tab=' . urlencode($configTab);
    }
    header('Location: configuracoes.php?' . $redirectMsg);
    exit;
}

// Verificar mensagens via GET
$message = '';
$messageType = '';

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Configurações salvas com sucesso!';
    $messageType = 'success';
    if (!empty($_SESSION['cron_sync_ok_msg'])) {
        $message .= ' ' . (string) $_SESSION['cron_sync_ok_msg'];
        unset($_SESSION['cron_sync_ok_msg']);
    }
    if (!empty($_SESSION['cron_sync_warn_msg'])) {
        $message .= ' Aviso: ' . (string) $_SESSION['cron_sync_warn_msg'];
        if ($messageType === 'success') {
            $messageType = 'warning';
        }
        unset($_SESSION['cron_sync_warn_msg']);
    }
}
if (isset($_GET['account_updated']) && $_GET['account_updated'] == '1') {
    $message = 'Usuário e/ou senha atualizados com sucesso!';
    $messageType = 'success';
}
if (isset($_GET['avatar_ok']) && $_GET['avatar_ok'] == '1') {
    $message = 'Foto do perfil atualizada.';
    $messageType = 'success';
}
if (isset($_GET['avatar_removed']) && $_GET['avatar_removed'] == '1') {
    $message = 'Foto do perfil removida.';
    $messageType = 'success';
}
if (isset($_GET['delete']) && $_GET['delete'] == '1') {
    $message = 'Banner deletado com sucesso!';
    $messageType = 'success';
}
if (isset($_GET['error'])) {
    $message = 'Erro ao salvar: ' . htmlspecialchars($_GET['error']);
    $messageType = 'error';
}
if (!empty($_SESSION['evolution_delete_warn'])) {
    $w = (string) $_SESSION['evolution_delete_warn'];
    unset($_SESSION['evolution_delete_warn']);
    if ($message !== '') {
        $message .= ' ' . $w;
    } else {
        $message = $w;
    }
    $messageType = $messageType === 'error' ? 'error' : 'warning';
}

// Agora incluir o header (após processamento do POST para evitar output antes do redirect)
$pageTitle = 'Configurações';
ensureAdminAvatarColumn();
$adminAvatarPathUi = getAdminAvatarPathById((int) ($_SESSION['admin_id'] ?? 0));
require_once __DIR__ . '/includes/header.php';

// Carregar configurações
$logo = getConfig('logo');
$favicon = getConfig('favicon', '');
$siteNomeMarca = getConfig('site_nome_marca', 'OfertasJá');
$siteMarcaModo = getConfig('site_marca_modo', 'ambos');
if (!in_array($siteMarcaModo, ['logo', 'texto', 'ambos'], true)) {
    $siteMarcaModo = 'ambos';
}
$temaCor = getConfig('tema_cor', '#f97316');
$categoriasTopbar = getConfig('categorias_topbar', 'Eletrônicos|Celulares|Games|Computadores|Cozinha');
$bannerType = getConfig('banner_type', 'images');
$bannersJson = getConfig('banners', '[]');
$banners = json_decode($bannersJson, true) ?: [];
$bannersDefaultJson = getConfig('banners_json', '[]');
if (empty($bannersDefaultJson) || $bannersDefaultJson === '[]') {
    // Banners padrão se não houver configuração
    $bannersDefaultJson = json_encode([
        ['id' => 1, 'title' => 'Até 60% OFF', 'subtitle' => 'em Eletrônicos', 'description' => 'Ofertas imperdíveis para você', 'bgGradient' => 'from-[hsl(var(--primary))] via-orange-500 to-orange-600'],
        ['id' => 2, 'title' => 'Super Ofertas', 'subtitle' => 'em Celulares', 'description' => 'Os melhores smartphones com desconto', 'bgGradient' => 'from-orange-600 via-[hsl(var(--primary))] to-red-500'],
        ['id' => 3, 'title' => 'Black Friday', 'subtitle' => 'Todo Dia', 'description' => 'Preços baixos o ano inteiro', 'bgGradient' => 'from-red-500 via-orange-500 to-[hsl(var(--primary))]'],
    ]);
}
$footerEmail = getConfig('footer_email', '');
$footerInstagram = getConfig('footer_instagram', '');
$footerFacebook = getConfig('footer_facebook', '');
$whatsappGrupoUrl = getConfig('whatsapp_grupo_url', '');
$whatsappGrupoBotaoTexto = getConfig('whatsapp_grupo_botao_texto', '');
$popupAtivo = getConfig('popup_ativo', '0') === '1';
$popupImagem = getConfig('popup_imagem', '');
$openaiApiKey = getConfig('openai_api_key', '');
$geminiApiKey = getConfig('gemini_api_key', '');
$iaCategoriaProvedor = function_exists('iaCategoriaProvedorAtual') ? iaCategoriaProvedorAtual() : 'none';

// Carregar contas Evolution
$evolutionContas = [];
try {
    $evolutionContas = $pdo->query("SELECT * FROM evolution_contas ORDER BY nome")->fetchAll();
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

// Config Evolution (tipo + API própria)
$evolutionTipo = getConfig('evolution_tipo', 'terceiros');
if (!in_array($evolutionTipo, ['terceiros', 'propria', 'uazapi', 'uazapi_propria'], true)) {
    $evolutionTipo = 'terceiros';
}
$evolutionApiUrl = getConfig('evolution_api_url', '');
$evolutionApiKeyGlobal = getConfig('evolution_api_key_global', '');
$uazapiApiUrl = rtrim(getConfig('uazapi_api_url', ''), '/');
$uazapiAdminTokenGlobal = trim((string) getConfig('uazapi_admin_token_global', ''));
// Telegram
$telegramBotToken = getConfig('telegram_bot_token', '');
$telegramChatId = getConfig('telegram_chat_id', '');
$telegramBusinessConnectionId = getConfig('telegram_business_connection_id', '');
$telegramAtivo = getConfig('telegram_ativo', '0') === '1';
$dispatchAdminId = (int) getConfig('dispatch_admin_id', '1');
if ($dispatchAdminId < 1) {
    $dispatchAdminId = 1;
}
$dispatchAtivoProducao = getConfig('dispatch_ativo_producao', '0') === '1';

// Aba ativa: equivalente a $_GET['tab'] ?? 'geral', com normalização segura
$tabGet = $_GET['tab'] ?? ($_GET['Tab'] ?? 'geral');
$activeTab = is_string($tabGet) ? strtolower(trim($tabGet)) : 'geral';
if ($activeTab === '') {
    $activeTab = 'geral';
}
if ($activeTab === 'horarios' || $activeTab === 'horário' || $activeTab === 'cron') {
    $activeTab = 'crons';
}
$abasConfigValidas = ['geral', 'openai', 'evolution', 'telegram', 'crons', 'conta'];
if (!in_array($activeTab, $abasConfigValidas, true)) {
    $activeTab = 'geral';
}
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Configurações</h1>
                <p class="text-sm text-gray-500 mt-0.5">Ajuste as opções do site e das automações</p>
            </div>
            
            <!-- Abas -->
            <div class="mb-6 border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                    <a href="?tab=geral" class="<?php echo $activeTab === 'geral' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Geral
                    </a>
                    <a href="?tab=openai" class="<?php echo $activeTab === 'openai' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        IA
                    </a>
                    <a href="?tab=evolution" class="<?php echo $activeTab === 'evolution' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        WhatsApp
                    </a>
                    <a href="?tab=telegram" class="<?php echo $activeTab === 'telegram' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Telegram
                    </a>
                    <a href="?tab=crons" class="<?php echo $activeTab === 'crons' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Crons
                    </a>
                    <a href="?tab=conta" class="<?php echo $activeTab === 'conta' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Conta admin
                    </a>
                </nav>
            </div>
            
            <?php if ($message): ?>
            <?php
            $msgBoxClass = 'bg-red-50 text-red-800 border-red-200';
            if ($messageType === 'success') {
                $msgBoxClass = 'bg-emerald-50 text-emerald-800 border-emerald-200';
            } elseif ($messageType === 'warning') {
                $msgBoxClass = 'bg-amber-50 text-amber-900 border-amber-200';
            }
            ?>
            <div class="mb-6 p-4 rounded-lg border <?php echo $msgBoxClass; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($activeTab === 'geral'): ?>
            <form method="POST" action="?tab=geral" enctype="multipart/form-data" class="space-y-8" id="configForm">
                <input type="hidden" name="config_tab" value="geral">
                <!-- Logo -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Logo do Site</h2>
                    
                    <?php if (!empty($logo)): ?>
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-2">Logo atual:</p>
                        <img src="../<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="max-h-32 object-contain">
                        <label class="mt-3 flex items-center gap-2 text-sm text-red-700 cursor-pointer">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Remover logo atual (ao salvar)
                        </label>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label for="logo" class="block text-sm font-medium text-gray-700 mb-2">Upload de Nova Logo</label>
                        <input type="file" id="logo" name="logo" accept="image/*"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Formatos aceitos: JPG, PNG, GIF, WebP</p>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Marca no site (cabeçalho e rodapé)</h2>
                    <p class="text-sm text-gray-600 mb-4">Defina o nome exibido e se quer logo, só nome ou os dois. Tamanhos acompanham o ecrã (telefone, tablet, desktop).</p>
                    <div class="space-y-4">
                        <div>
                            <label for="site_nome_marca" class="block text-sm font-medium text-gray-700 mb-2">Nome da marca</label>
                            <input type="text" id="site_nome_marca" name="site_nome_marca" maxlength="80"
                                   value="<?php echo htmlspecialchars($siteNomeMarca); ?>"
                                   class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"
                                   placeholder="Ex.: OfertasJá">
                        </div>
                        <div>
                            <label for="site_marca_modo" class="block text-sm font-medium text-gray-700 mb-2">Mostrar no topo e rodapé</label>
                            <select id="site_marca_modo" name="site_marca_modo"
                                    class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="ambos" <?php echo $siteMarcaModo === 'ambos' ? 'selected' : ''; ?>>Logo e nome (recomendado se tiver logo)</option>
                                <option value="logo" <?php echo $siteMarcaModo === 'logo' ? 'selected' : ''; ?>>Só logo (sem nome ao lado)</option>
                                <option value="texto" <?php echo $siteMarcaModo === 'texto' ? 'selected' : ''; ?>>Só nome (sem imagem)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Favicon -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Favicon do Site</h2>
                    <p class="text-sm text-gray-600 mb-4">Ícone exibido na aba do navegador. Tamanho recomendado: 32x32 ou 64x64 pixels.</p>
                    <?php if (!empty($favicon)): ?>
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-2">Favicon atual:</p>
                        <img src="../<?php echo htmlspecialchars($favicon); ?>" alt="Favicon" class="h-8 w-8 object-contain">
                        <label class="mt-3 flex items-center gap-2 text-sm text-red-700 cursor-pointer">
                            <input type="checkbox" name="remove_favicon" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Remover favicon atual (ao salvar)
                        </label>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label for="favicon" class="block text-sm font-medium text-gray-700 mb-2">Upload de Novo Favicon</label>
                        <input type="file" id="favicon" name="favicon" accept="image/png,image/x-icon,image/ico,image/jpeg,image/gif"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Formatos: PNG, ICO, JPG, GIF. Tamanho ideal: 32x32px</p>
                    </div>
                </div>
                
                <!-- Cor do Tema -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Cor do Tema</h2>
                    <p class="text-sm text-gray-600 mb-4">Altere a cor principal do site (botões, links, destaques). A mudança se aplica automaticamente em todo o site e no painel admin.</p>
                    <input type="hidden" name="tema_cor" id="tema_cor_hidden" value="<?php echo htmlspecialchars($temaCor); ?>">
                    <div class="flex flex-wrap gap-4 items-center">
                        <div class="flex gap-2 flex-wrap">
                            <?php
                            $coresPreset = [
                                '#f97316' => 'Laranja (padrão)',
                                '#dc2626' => 'Vermelho',
                                '#ea580c' => 'Laranja escuro',
                                '#ca8a04' => 'Âmbar',
                                '#16a34a' => 'Verde',
                                '#2563eb' => 'Azul',
                                '#7c3aed' => 'Roxo',
                                '#db2777' => 'Rosa',
                                '#0891b2' => 'Ciano',
                            ];
                            foreach ($coresPreset as $hex => $label):
                            ?>
                            <button type="button" class="tema-cor-preset w-10 h-10 rounded-lg border-2 transition-all hover:scale-105 <?php echo $temaCor === $hex ? 'border-gray-800 ring-2 ring-offset-1 ring-gray-400' : 'border-gray-200 hover:border-gray-400'; ?>" data-hex="<?php echo $hex; ?>" style="background-color: <?php echo $hex; ?>" title="<?php echo htmlspecialchars($label); ?>"></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm font-medium text-gray-700">Ou escolha:</label>
                            <input type="color" id="tema_cor_picker" value="<?php echo htmlspecialchars($temaCor); ?>"
                                   class="w-12 h-10 cursor-pointer border border-gray-300 rounded">
                            <input type="text" id="tema_cor_custom" value="<?php echo htmlspecialchars($temaCor); ?>"
                                   placeholder="#f97316" maxlength="7"
                                   class="w-24 px-2 py-1 border border-gray-300 rounded text-sm font-mono focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Cor selecionada: <strong id="tema_cor_display"><?php echo htmlspecialchars($temaCor); ?></strong></p>
                    <script>
                    (function(){
                        var h = document.getElementById('tema_cor_hidden');
                        var picker = document.getElementById('tema_cor_picker');
                        var custom = document.getElementById('tema_cor_custom');
                        var display = document.getElementById('tema_cor_display');
                        function setCor(hex){ h.value=hex; picker.value=hex; custom.value=hex; display.textContent=hex; document.querySelectorAll('.tema-cor-preset').forEach(function(b){ b.classList.toggle('border-gray-800', b.dataset.hex===hex); b.classList.toggle('ring-2', b.dataset.hex===hex); b.classList.toggle('ring-offset-1', b.dataset.hex===hex); b.classList.toggle('ring-gray-400', b.dataset.hex===hex); b.classList.toggle('border-gray-200', b.dataset.hex!==hex); }); }
                        document.querySelectorAll('.tema-cor-preset').forEach(function(b){ b.onclick=function(){ setCor(b.dataset.hex); }; });
                        picker.oninput=picker.onchange=function(){ setCor(this.value); };
                        custom.onchange=function(){ var v=this.value.trim(); if(v&&v.charAt(0)!=='#')v='#'+v; if(/^#[0-9A-Fa-f]{6}$/.test(v)) setCor(v); };
                    })();
                    </script>
                </div>
                
                <!-- Grupo do WhatsApp -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Grupo do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Texto e link do botão verde na topbar do site (desktop e mobile).</p>
                    <div class="mb-4">
                        <label for="whatsapp_grupo_botao_texto" class="block text-sm font-medium text-gray-700 mb-2">Texto do botão</label>
                        <input type="text" id="whatsapp_grupo_botao_texto" name="whatsapp_grupo_botao_texto" maxlength="120"
                               value="<?php echo htmlspecialchars($whatsappGrupoBotaoTexto); ?>"
                               placeholder="Entrar no grupo do WhatsApp"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Se ficar vazio, o site usa o texto padrão “Entrar No Grupo do Whatsapp”.</p>
                    </div>
                    <div>
                        <label for="whatsapp_grupo_url" class="block text-sm font-medium text-gray-700 mb-2">URL do Grupo do WhatsApp</label>
                        <input type="url" id="whatsapp_grupo_url" name="whatsapp_grupo_url"
                               value="<?php echo htmlspecialchars($whatsappGrupoUrl); ?>"
                               placeholder="https://chat.whatsapp.com/..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Exemplo: https://chat.whatsapp.com/ABC123xyz</p>
                    </div>
                </div>
                
                <!-- Popup -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Popup no Site</h2>
                    <p class="text-sm text-gray-600 mb-4">Exibe um popup com imagem ao visitar o site. O visitante pode fechar ou clicar na imagem para ser direcionado ao grupo do WhatsApp (URL acima).</p>
                    
                    <div class="space-y-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="popup_ativo" value="1"
                                   <?php echo $popupAtivo ? 'checked' : ''; ?>
                                   class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span class="text-sm font-medium text-gray-700">Ativar popup</span>
                        </label>
                        
                        <?php if (!empty($popupImagem)): ?>
                        <div>
                            <p class="text-sm text-gray-600 mb-2">Imagem atual do popup:</p>
                            <img src="../<?php echo htmlspecialchars($popupImagem); ?>" alt="Popup" class="max-h-40 object-contain rounded border border-gray-300">
                        </div>
                        <?php endif; ?>
                        
                        <div>
                            <label for="popup_imagem" class="block text-sm font-medium text-gray-700 mb-2">Imagem do Popup</label>
                            <input type="file" id="popup_imagem" name="popup_imagem" accept="image/*"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Formatos aceitos: JPG, PNG, GIF, WebP. Se a URL do grupo estiver vazia, o clique na imagem não fará nada.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Categorias Topbar -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Categorias da Topbar</h2>
                    <p class="text-sm text-gray-600 mb-4">Informe as categorias separadas por | (pipe)</p>
                    
                    <div>
                        <label for="categorias_topbar" class="block text-sm font-medium text-gray-700 mb-2">Categorias</label>
                        <input type="text" id="categorias_topbar" name="categorias_topbar"
                               value="<?php echo htmlspecialchars($categoriasTopbar); ?>"
                               placeholder="Eletrônicos|Celulares|Games|Computadores|Cozinha"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Exemplo: Eletrônicos|Celulares|Games</p>
                    </div>
                </div>
                
                <!-- Banners -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Banners do Carrossel</h2>
                    
                    <!-- Seleção do tipo de banner -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-3">Tipo de Banner</label>
                        <div class="flex gap-4">
                            <label class="flex items-center">
                                <input type="radio" name="banner_type" value="images" <?php echo $bannerType === 'images' ? 'checked' : ''; ?>
                                       class="mr-2" onchange="toggleBannerType()">
                                <span>Banners de Imagens (Upload)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="banner_type" value="default" <?php echo $bannerType === 'default' ? 'checked' : ''; ?>
                                       class="mr-2" onchange="toggleBannerType()">
                                <span>Banners Padrão (JSON)</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Banners de Imagens -->
                    <div id="banners-images" style="display: <?php echo $bannerType === 'images' ? 'block' : 'none'; ?>;">
                        <p class="text-sm text-gray-600 mb-4">Faça upload de imagens que aparecerão no carrossel principal</p>
                        
                        <!-- Banners atuais -->
                        <?php 
                        // Recarregar banners após processamento
                        $bannersJson = getConfig('banners', '[]');
                        $banners = json_decode($bannersJson, true) ?: [];
                        $banners = array_values(array_filter(array_map('trim', $banners)));
                        ?>
                        <?php if (!empty($banners)): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-3">Banners Atuais (<?php echo count($banners); ?>)</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <?php foreach ($banners as $index => $banner): ?>
                                <div class="relative border border-gray-200 rounded p-2">
                                    <img src="../<?php echo htmlspecialchars($banner); ?>" alt="Banner <?php echo $index + 1; ?>" 
                                         class="w-full h-32 object-cover rounded border border-gray-300">
                                    <button type="button" onclick="deleteBanner(<?php echo $index; ?>)" class="mt-2 bg-red-500 hover:bg-red-600 text-white text-sm px-3 py-1 rounded transition-colors w-full">
                                        Deletar Banner #<?php echo $index + 1; ?>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mb-6 p-4 bg-gray-100 rounded text-gray-600 text-sm">
                            Nenhum banner cadastrado. Faça upload de imagens abaixo.
                        </div>
                        <?php endif; ?>
                        
                        <!-- Upload de novas imagens -->
                        <div>
                            <label for="banner_images" class="block text-sm font-medium text-gray-700 mb-2">Adicionar Novas Imagens</label>
                            <input type="file" id="banner_images" name="banner_images[]" multiple accept="image/*"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Você pode selecionar múltiplas imagens. Formatos aceitos: JPG, PNG, GIF, WebP</p>
                        </div>
                    </div>
                    
                    <!-- Banners Padrão JSON -->
                    <div id="banners-default" style="display: <?php echo $bannerType === 'default' ? 'block' : 'none'; ?>;">
                        <p class="text-sm text-gray-600 mb-4">Configure os banners padrão usando JSON com títulos, subtítulos e gradientes</p>
                        
                        <div>
                            <label for="banners_json" class="block text-sm font-medium text-gray-700 mb-2">JSON dos Banners Padrão</label>
                            <textarea id="banners_json" name="banners_json" rows="12"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($bannersDefaultJson); ?></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Formato JSON: <code>[{"id": 1, "title": "Título", "subtitle": "Subtítulo", "description": "Descrição", "bgGradient": "from-orange-500 via-orange-600 to-orange-700"}]</code>
                            </p>
                            <button type="button" onclick="restoreDefaultBanners()" class="mt-2 text-sm text-orange-600 hover:text-orange-700">
                                Restaurar Banners Padrão
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Configurações do Footer</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="footer_email" class="block text-sm font-medium text-gray-700 mb-2">Email de Contato (opcional)</label>
                            <input type="email" id="footer_email" name="footer_email"
                                   value="<?php echo htmlspecialchars($footerEmail); ?>"
                                   placeholder="contato@exemplo.com"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="footer_instagram" class="block text-sm font-medium text-gray-700 mb-2">Link Instagram (opcional)</label>
                            <input type="text" id="footer_instagram" name="footer_instagram"
                                   value="<?php echo htmlspecialchars($footerInstagram); ?>"
                                   placeholder="https://instagram.com/..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="footer_facebook" class="block text-sm font-medium text-gray-700 mb-2">Link Facebook (opcional)</label>
                            <input type="text" id="footer_facebook" name="footer_facebook"
                                   value="<?php echo htmlspecialchars($footerFacebook); ?>"
                                   placeholder="https://facebook.com/..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Salvar Configurações
                    </button>
                </div>
            </form>
            
            <?php elseif ($activeTab === 'openai'): ?>
            <!-- Aba IA -->
            <form method="POST" action="?tab=openai" class="space-y-8">
                <input type="hidden" name="config_tab" value="openai">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Chaves de API (IA)</h2>
                    <p class="text-sm text-gray-600 mb-4">Configure as chaves de API para uso em copywriting e classificação de categorias.</p>
                    
                    <div class="space-y-6">
                        <div>
                            <label for="openai_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave API OpenAI</label>
                            <input type="text" id="openai_api_key" name="openai_api_key"
                                   value="<?php echo htmlspecialchars($openaiApiKey); ?>"
                                   placeholder="sk-..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Usada para gerar copy das ofertas e, se escolher OpenAI abaixo, para classificar categorias. Obtenha em: <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-500 hover:underline">platform.openai.com/api-keys</a></p>
                        </div>
                        
                        <div>
                            <label for="gemini_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave API Gemini (Google)</label>
                            <input type="text" id="gemini_api_key" name="gemini_api_key"
                                   value="<?php echo htmlspecialchars($geminiApiKey); ?>"
                                   placeholder="AIza..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Usada quando <strong>Gemini</strong> está selecionado para classificação. Obtenha em: <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-blue-500 hover:underline">aistudio.google.com</a></p>
                        </div>
                        
                        <div class="border-t pt-4">
                            <label for="ia_categoria_provedor" class="block text-sm font-medium text-gray-700 mb-2">Classificação de categorias e subcategorias (produtos)</label>
                            <select name="ia_categoria_provedor" id="ia_categoria_provedor"
                                    class="w-full max-w-lg px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 bg-white text-sm">
                                <option value="none" <?php echo $iaCategoriaProvedor === 'none' ? 'selected' : ''; ?>>Nenhum — só palavras-chave e regras locais (sem IA)</option>
                                <option value="openai" <?php echo $iaCategoriaProvedor === 'openai' ? 'selected' : ''; ?>>OpenAI — definir categoria pelo nome/contexto do produto</option>
                                <option value="gemini" <?php echo $iaCategoriaProvedor === 'gemini' ? 'selected' : ''; ?>>Google Gemini — definir categoria pelo nome/contexto do produto</option>
                            </select>
                            <p class="mt-2 text-xs text-gray-500">A IA escolhe o <strong>slug canônico</strong> da categoria (incluindo folhas como moda-infantil, alinhadas à hierarquia do site). Encaixa o produto na categoria correta para envio aos grupos WhatsApp vinculados. Exige a chave do provedor selecionado preenchida acima.</p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Salvar
                    </button>
                </div>
            </form>
            
            <?php elseif ($activeTab === 'evolution'): ?>
            <!-- Aba WhatsApp (Evolution) -->
            <?php
            $editEvolution = null;
            $editEvolutionIsPropria = false;
            if (isset($_GET['edit_evolution'])) {
                $id = (int)$_GET['edit_evolution'];
                $stmt = $pdo->prepare("SELECT * FROM evolution_contas WHERE id = ?");
                $stmt->execute([$id]);
                $editEvolution = $stmt->fetch();
                $editEvolutionIsPropria = !empty($editEvolution['api_propria'] ?? 0);
            }
            $formUazapi = in_array($evolutionTipo, ['uazapi', 'uazapi_propria'], true)
                || ($editEvolution && (($editEvolution['provedor'] ?? 'evolution') === 'uazapi'));
            $evolutionTituloModalEditar = 'Editar conta WhatsApp';
            if ($editEvolution && (($editEvolution['provedor'] ?? '') === 'uazapi')) {
                $evolutionTituloModalEditar = 'Editar conta Uazapi';
            }
            ?>
            <style>
                /* Evolution devolve PNG do QR muitas vezes em tons de azul; WhatsApp lê melhor módulos pretos */
                img.evolution-qr-bw {
                    filter: grayscale(100%) contrast(22) brightness(1.05);
                    image-rendering: -webkit-optimize-contrast;
                    image-rendering: crisp-edges;
                }
            </style>
            <div class="space-y-6">
                <!-- WhatsApp: Provedor externo ou API própria -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-3">WhatsApp</h2>
                    <form method="POST" action="?tab=evolution" id="evolution-tipo-form">
                        <input type="hidden" name="config_tab" value="evolution">
                        <input type="hidden" name="evolution_action" value="save_config">
                        <div class="grid grid-cols-2 xl:grid-cols-4 gap-2">
                            <label class="flex items-start gap-2 p-3 border-2 rounded-lg cursor-pointer transition-all hover:border-orange-200 <?php echo $evolutionTipo === 'terceiros' ? 'border-orange-500 bg-orange-50/50' : 'border-gray-200'; ?> evolution-tipo-opt" data-tipo="terceiros" title="Evolution em serviço de terceiros — URL, instância e API Key por conta">
                                <input type="radio" name="evolution_tipo" value="terceiros" <?php echo $evolutionTipo === 'terceiros' ? 'checked' : ''; ?>
                                       class="mt-0.5 shrink-0 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs sm:text-sm font-semibold text-gray-900 leading-tight">Provedor externo (Evolution)</span>
                            </label>
                            <label class="flex items-start gap-2 p-3 border-2 rounded-lg cursor-pointer transition-all hover:border-orange-200 <?php echo $evolutionTipo === 'propria' ? 'border-orange-500 bg-orange-50/50' : 'border-gray-200'; ?> evolution-tipo-opt" data-tipo="propria" title="Sua Evolution API (Servidor próprio) — criar instância e QR neste painel">
                                <input type="radio" name="evolution_tipo" value="propria" <?php echo $evolutionTipo === 'propria' ? 'checked' : ''; ?>
                                       class="mt-0.5 shrink-0 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs sm:text-sm font-semibold text-gray-900 leading-tight">Evolution (API própria)</span>
                            </label>
                            <label class="flex items-start gap-2 p-3 border-2 rounded-lg cursor-pointer transition-all hover:border-orange-200 <?php echo $evolutionTipo === 'uazapi' ? 'border-orange-500 bg-orange-50/50' : 'border-gray-200'; ?> evolution-tipo-opt" data-tipo="uazapi" title="Uazapi hospedado — nova instância com QR ou + Nova conta">
                                <input type="radio" name="evolution_tipo" value="uazapi" <?php echo $evolutionTipo === 'uazapi' ? 'checked' : ''; ?>
                                       class="mt-0.5 shrink-0 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs sm:text-sm font-semibold text-gray-900 leading-tight">Provedor externo (Uazapi)</span>
                            </label>
                            <label class="flex items-start gap-2 p-3 border-2 rounded-lg cursor-pointer transition-all hover:border-orange-200 <?php echo $evolutionTipo === 'uazapi_propria' ? 'border-orange-500 bg-orange-50/50' : 'border-gray-200'; ?> evolution-tipo-opt" data-tipo="uazapi_propria" title="Seu servidor Uazapi — URL e token globais">
                                <input type="radio" name="evolution_tipo" value="uazapi_propria" <?php echo $evolutionTipo === 'uazapi_propria' ? 'checked' : ''; ?>
                                       class="mt-0.5 shrink-0 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs sm:text-sm font-semibold text-gray-900 leading-tight">Uazapi (API própria)</span>
                            </label>
                        </div>
                        <p id="evolution-provider-save-feedback" class="mt-3 text-sm text-gray-500" aria-live="polite"></p>
                    </form>
                </div>
                
                <?php if ($evolutionTipo === 'terceiros'): ?>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" id="evolution-terceiros-nova-conta">
                    <h2 class="text-lg font-bold text-gray-800 mb-3">Provedor externo (Evolution) — nova conta</h2>
                    <form method="POST" action="?tab=evolution">
                        <input type="hidden" name="config_tab" value="evolution">
                        <input type="hidden" name="evolution_action" value="save">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="evolution_terceiros_nome" class="block text-sm font-medium text-gray-700 mb-2">Nome da conta *</label>
                                <input type="text" id="evolution_terceiros_nome" name="evolution_nome" required
                                       placeholder="Ex: Conta principal"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div>
                                <label for="evolution_terceiros_instancia" class="block text-sm font-medium text-gray-700 mb-2">Instância *</label>
                                <input type="text" id="evolution_terceiros_instancia" name="evolution_instancia" required
                                       placeholder="Nome da instância na Evolution"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div class="md:col-span-2">
                                <label for="evolution_terceiros_url" class="block text-sm font-medium text-gray-700 mb-2">URL base da API *</label>
                                <input type="url" id="evolution_terceiros_url" name="evolution_url_base" required
                                       placeholder="https://sua-api.evolution.exemplo.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div class="md:col-span-2">
                                <label for="evolution_terceiros_apikey" class="block text-sm font-medium text-gray-700 mb-2">API Key *</label>
                                <input type="text" id="evolution_terceiros_apikey" name="evolution_api_key" required
                                       placeholder="API Key da instância"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer mb-4">
                            <input type="checkbox" name="evolution_ativo" value="1" checked
                                   class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span class="text-sm text-gray-700">Conta ativa</span>
                        </label>
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                            Salvar conta
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if ($evolutionTipo === 'propria'): ?>
                <!-- API própria: Config + Adicionar conta com QR -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" id="evolution-propria-config">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">Sua Evolution API (Servidor próprio)</h2>
                    <form method="POST" action="?tab=evolution" class="mb-6">
                        <input type="hidden" name="config_tab" value="evolution">
                        <input type="hidden" name="evolution_action" value="save_config">
                        <input type="hidden" name="evolution_tipo" value="propria">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="evolution_api_url" class="block text-sm font-medium text-gray-700 mb-2">URL da API *</label>
                                <input type="url" id="evolution_api_url" name="evolution_api_url" required
                                       value="<?php echo htmlspecialchars($evolutionApiUrl); ?>"
                                       placeholder="https://sua-evolution.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div>
                                <label for="evolution_api_key_global" class="block text-sm font-medium text-gray-700 mb-2">API Key global *</label>
                                <input type="text" id="evolution_api_key_global" name="evolution_api_key_global" required
                                       value="<?php echo htmlspecialchars($evolutionApiKeyGlobal); ?>"
                                       placeholder="Sua API Key"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                        </div>
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">Salvar configuração</button>
                    </form>
                    
                    <h3 class="text-base font-bold text-gray-800 mb-2 mt-6 pt-6 border-t border-gray-200">Nova conta</h3>
                    <p class="text-sm text-gray-500 mb-4">Nome e instância. O QR code aparecerá para vincular o WhatsApp.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="evolution_nova_nome" class="block text-sm font-medium text-gray-700 mb-2">Nome da conta *</label>
                            <input type="text" id="evolution_nova_nome" placeholder="Ex: Conta Vendas"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="evolution_nova_instancia" class="block text-sm font-medium text-gray-700 mb-2">Nome da instância *</label>
                            <input type="text" id="evolution_nova_instancia" placeholder="Ex: contavendas (letras, números, _ ou -)"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <button type="button" id="evolution-criar-instancia-btn" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Criar instância e escanear QR
                    </button>
                    <div id="evolution-qr-container" class="mt-6 hidden p-4 bg-gray-50 rounded-lg border">
                        <p class="text-sm font-medium text-gray-700 mb-2">Escaneie o QR code com o WhatsApp no seu celular:</p>
                        <p class="text-xs text-gray-500 mb-3">O QR expira em breve; se desaparecer ou falhar, use «Atualizar QR code». Depois de escanear, pode recarregar a página para ver a conta na lista.</p>
                        <div class="flex flex-col items-start gap-4">
                            <img id="evolution-qr-img" src="" alt="QR Code" class="evolution-qr-bw max-w-[280px] h-auto border border-gray-300 rounded bg-white p-2">
                            <p id="evolution-qr-pairing" class="text-sm text-gray-600 hidden"></p>
                            <p id="evolution-qr-status" class="text-sm text-gray-600">Aguardando criação da sessão...</p>
                            <div class="flex flex-wrap gap-4 items-center">
                                <button type="button" id="evolution-refresh-qr-btn" class="text-orange-600 hover:text-orange-800 text-sm font-medium">Atualizar QR code</button>
                                <button type="button" id="evolution-reload-page-btn" class="text-gray-600 hover:text-gray-800 text-sm font-medium border border-gray-300 rounded px-3 py-1">Recarregar página</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($evolutionTipo === 'uazapi_propria'): ?>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" id="uazapi-propria-config">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">Sua Uazapi API (servidor próprio)</h2>
                    <p class="text-sm text-gray-600 mb-4">Defina a URL base do seu servidor Uazapi e o <strong>Admin Token</strong> global (como a API Key global na Evolution). Eles serão usados ao criar novas instâncias e nas ações administrativas nas contas deste modo.</p>
                    <form method="POST" action="?tab=evolution" class="mb-6">
                        <input type="hidden" name="config_tab" value="evolution">
                        <input type="hidden" name="evolution_action" value="save_config">
                        <input type="hidden" name="evolution_tipo" value="uazapi_propria">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="uazapi_propria_api_url" class="block text-sm font-medium text-gray-700 mb-2">URL base da API Uazapi *</label>
                                <input type="url" id="uazapi_propria_api_url" name="uazapi_api_url" required
                                       value="<?php echo htmlspecialchars($uazapiApiUrl); ?>"
                                       placeholder="https://sua-uazapi.exemplo.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div>
                                <label for="uazapi_admin_token_global" class="block text-sm font-medium text-gray-700 mb-2">Admin Token global *</label>
                                <input type="text" id="uazapi_admin_token_global" name="uazapi_admin_token_global" required
                                       value="<?php echo htmlspecialchars($uazapiAdminTokenGlobal); ?>"
                                       placeholder="Token de administrador do servidor Uazapi"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                            </div>
                        </div>
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">Salvar configuração</button>
                    </form>
                    <h3 class="text-base font-bold text-gray-800 mb-2 mt-6 pt-6 border-t border-gray-200">Nova conta</h3>
                    <p class="text-sm text-gray-500 mb-4">Nome e nome da instância. O QR usa a URL e o Admin Token globais salvos acima.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="uazapi_propria_nova_nome" class="block text-sm font-medium text-gray-700 mb-2">Nome da conta *</label>
                            <input type="text" id="uazapi_propria_nova_nome" placeholder="Ex: Loja principal"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="uazapi_propria_nova_instancia" class="block text-sm font-medium text-gray-700 mb-2">Nome da instância *</label>
                            <input type="text" id="uazapi_propria_nova_instancia" placeholder="Ex: loja1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <button type="button" id="uazapi-propria-criar-instancia-btn" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Criar instância e escanear QR
                    </button>
                    <div id="uazapi-propria-qr-container" class="mt-6 hidden p-4 bg-gray-50 rounded-lg border">
                        <p id="uazapi-propria-qr-status" class="text-sm text-gray-600 mb-2"></p>
                        <div class="flex flex-col items-start gap-4">
                            <img id="uazapi-propria-qr-img" src="" alt="QR Code" class="evolution-qr-bw max-w-[280px] h-auto border border-gray-300 rounded bg-white p-2">
                            <p id="uazapi-propria-qr-pairing" class="text-sm text-gray-600 hidden"></p>
                            <div class="flex flex-wrap gap-4 items-center">
                                <button type="button" id="uazapi-propria-refresh-qr-btn" class="text-orange-600 hover:text-orange-800 text-sm font-medium">Atualizar QR code</button>
                                <button type="button" id="uazapi-propria-reload-page-btn" class="text-gray-600 hover:text-gray-800 text-sm font-medium border border-gray-300 rounded px-3 py-1">Recarregar página</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($evolutionTipo === 'uazapi'): ?>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" id="uazapi-criar-com-qr">
                    <h2 class="text-lg font-bold text-gray-800 mb-3">Provedor externo (Uazapi) — nova instância</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="uazapi_nova_nome" class="block text-sm font-medium text-gray-700 mb-2">Nome da conta *</label>
                            <input type="text" id="uazapi_nova_nome" placeholder="Ex: Loja principal"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="uazapi_nova_instancia" class="block text-sm font-medium text-gray-700 mb-2">Nome da instância *</label>
                            <input type="text" id="uazapi_nova_instancia" placeholder="Ex: loja1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="uazapi_nova_api_url" class="block text-sm font-medium text-gray-700 mb-2">URL base da API *</label>
                            <input type="url" id="uazapi_nova_api_url" value="<?php echo htmlspecialchars($uazapiApiUrl); ?>"
                                   placeholder="https://..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="uazapi_nova_admin_token" class="block text-sm font-medium text-gray-700 mb-2">Token *</label>
                            <input type="password" id="uazapi_nova_admin_token" autocomplete="new-password"
                                   placeholder="Token de administrador (painel Uazapi)"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <button type="button" id="uazapi-criar-instancia-btn" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Criar instância e escanear QR
                    </button>
                    <div id="uazapi-qr-container" class="mt-6 hidden p-4 bg-gray-50 rounded-lg border">
                        <p id="uazapi-qr-status" class="text-sm text-gray-600 mb-2"></p>
                        <div class="flex flex-col items-start gap-4">
                            <img id="uazapi-qr-img" src="" alt="QR Code" class="evolution-qr-bw max-w-[280px] h-auto border border-gray-300 rounded bg-white p-2">
                            <p id="uazapi-qr-pairing" class="text-sm text-gray-600 hidden"></p>
                            <div class="flex flex-wrap gap-4 items-center">
                                <button type="button" id="uazapi-refresh-qr-btn" class="text-orange-600 hover:text-orange-800 text-sm font-medium">Atualizar QR code</button>
                                <button type="button" id="uazapi-reload-page-btn" class="text-gray-600 hover:text-gray-800 text-sm font-medium border border-gray-300 rounded px-3 py-1">Recarregar página</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Modal cadastrar/editar conta (provedor externo ou Uazapi manual) -->
                <div id="evolution-conta-modal" class="fixed inset-0 z-[60] hidden overflow-y-auto" aria-labelledby="evolution-conta-modal-title" role="dialog" aria-modal="true">
                    <div class="flex min-h-full items-end sm:items-center justify-center p-4 sm:p-6">
                        <div class="fixed inset-0 bg-gray-900/50 transition-opacity" id="evolution-conta-modal-backdrop" aria-hidden="true"></div>
                        <div class="relative z-10 bg-white rounded-xl shadow-xl border border-gray-100 w-full max-w-2xl max-h-[min(90vh,720px)] flex flex-col">
                            <div class="flex-shrink-0 flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-gray-100">
                                <div class="min-w-0">
                                    <h2 id="evolution-conta-modal-title" class="text-lg font-bold text-gray-900 flex flex-wrap items-center gap-2">
                                        <span id="evolution-conta-modal-title-text"><?php echo $editEvolution ? htmlspecialchars($evolutionTituloModalEditar) : ($formUazapi ? 'Nova conta Uazapi (manual)' : 'Nova conta WhatsApp'); ?></span>
                                    </h2>
                                    <p id="evolution-conta-modal-desc-uazapi" class="text-sm text-gray-600 mt-2 <?php echo $formUazapi ? '' : 'hidden'; ?>">URL, instância, token da instância; token do painel opcional (renovar QR).</p>
                                </div>
                                <button type="button" id="evolution-conta-modal-fechar" class="flex-shrink-0 rounded-lg p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-orange-500" aria-label="Fechar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="overflow-y-auto flex-1 px-5 py-4">
                                <form method="POST" action="?tab=evolution" id="evolution-conta-form">
                                    <input type="hidden" name="config_tab" value="evolution">
                                    <input type="hidden" name="evolution_action" value="save">
                                    <input type="hidden" name="evolution_id" id="evolution_conta_hidden_id" value="<?php echo $editEvolution ? (int) $editEvolution['id'] : ''; ?>">

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <label for="evolution_nome" class="block text-sm font-medium text-gray-700 mb-2">Nome da Conta *</label>
                                            <input type="text" id="evolution_nome" name="evolution_nome" required
                                                   value="<?php echo htmlspecialchars($editEvolution['nome'] ?? ''); ?>"
                                                   placeholder="Ex: Conta Principal"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        </div>
                                        <div>
                                            <label for="evolution_url_base" id="evolution_url_base_label" class="block text-sm font-medium text-gray-700 mb-2"><?php echo $formUazapi ? 'URL base da API Uazapi *' : 'URL Base *'; ?></label>
                                            <input type="url" id="evolution_url_base" name="evolution_url_base" <?php echo $editEvolutionIsPropria ? '' : 'required'; ?>
                                                   value="<?php echo htmlspecialchars($editEvolution['url_base'] ?? ''); ?>"
                                                   placeholder="<?php echo $formUazapi ? 'https://sua-api.uazapi.com' : 'https://evolution.exemplo.com'; ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        </div>
                                        <div>
                                            <label for="evolution_instancia" class="block text-sm font-medium text-gray-700 mb-2">Instância *</label>
                                            <input type="text" id="evolution_instancia" name="evolution_instancia" required
                                                   value="<?php echo htmlspecialchars($editEvolution['instancia'] ?? ''); ?>"
                                                   placeholder="Nome da instância"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        </div>
                                        <div>
                                            <label for="evolution_api_key" id="evolution_api_key_label" class="block text-sm font-medium text-gray-700 mb-2"><?php echo $formUazapi ? 'Token da instância *' : 'API Key *'; ?></label>
                                            <input type="text" id="evolution_api_key" name="evolution_api_key" <?php echo $editEvolutionIsPropria ? '' : 'required'; ?>
                                                   value="<?php echo htmlspecialchars($editEvolution['api_key'] ?? ''); ?>"
                                                   placeholder="<?php echo $formUazapi ? 'Token retornado pela Uazapi' : 'Sua API Key'; ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        </div>
                                        <div id="evolution-conta-uazapi-admin-wrap" class="md:col-span-2 <?php echo $formUazapi ? '' : 'hidden'; ?>">
                                            <label for="uazapi_admin_token" class="block text-sm font-medium text-gray-700 mb-2">Token</label>
                                            <input type="password" name="uazapi_admin_token" id="uazapi_admin_token" autocomplete="new-password"
                                                   value=""
                                                   placeholder="<?php echo $editEvolution ? 'Deixe em branco para manter o token já salvo' : 'Opcional; necessário para renovar o QR'; ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" name="evolution_ativo" id="evolution_ativo_chk" value="1"
                                                   <?php echo ($editEvolution && $editEvolution['ativo']) ? 'checked' : ''; ?>
                                                   class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                            <span class="text-sm text-gray-700">Conta ativa</span>
                                        </label>
                                    </div>

                                    <div class="flex flex-wrap gap-3 pt-2">
                                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                                            Salvar
                                        </button>
                                        <button type="button" id="evolution-conta-modal-cancelar" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-6 rounded transition-colors">
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de contas -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-gray-800">Contas WhatsApp</h2>
                        <button type="button" id="evolution-abrir-modal-nova-conta" class="text-sm font-medium text-orange-600 hover:text-orange-700 focus:outline-none focus:underline">
                            + Nova conta
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Nome / API</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 hidden md:table-cell">URL</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Instância</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="evolution-contas-tbody" class="divide-y divide-gray-100">
                                <?php if (empty($evolutionContas)): ?>
                                <tr class="evolution-contas-empty-placeholder">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500 text-sm">Nenhuma conta cadastrada.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($evolutionContas as $conta): ?>
                                <tr class="hover:bg-gray-50/50" data-evolution-conta-id="<?php echo (int) $conta['id']; ?>">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($conta['nome']); ?>
                                        <?php echo achadinhosEvolutionContaBadgesHtml($conta); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 truncate max-w-[180px] hidden md:table-cell" title="<?php echo htmlspecialchars($conta['url_base']); ?>"><?php echo htmlspecialchars($conta['url_base']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono"><?php echo htmlspecialchars($conta['instancia']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php $contaApiPropria = !empty($conta['api_propria'] ?? 0); ?>
                                        <?php $contaUazapi = ($conta['provedor'] ?? 'evolution') === 'uazapi'; ?>
                                        <?php if ($contaApiPropria): ?>
                                        <div class="flex flex-col gap-1 items-start">
                                            <?php if ($conta['ativo']): ?>
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Conta ativa</span>
                                            <?php else: ?>
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-gray-100 text-gray-600">Conta inativa</span>
                                            <?php endif; ?>
                                            <span class="evo-wa-state text-xs text-slate-600 max-w-[220px] leading-snug" data-evolution-wa-id="<?php echo (int) $conta['id']; ?>">WhatsApp: sincronizando…</span>
                                        </div>
                                        <?php elseif ($conta['ativo']): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Ativa</span>
                                        <?php else: ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-gray-100 text-gray-600">Inativa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right align-top">
                                        <?php if ($contaApiPropria): ?>
                                        <div class="flex flex-wrap justify-end gap-x-2 gap-y-1.5 text-xs font-medium">
                                            <a href="?tab=evolution&edit_evolution=<?php echo (int) $conta['id']; ?>" class="text-orange-600 hover:text-orange-800">Editar</a>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-status-refresh-btn" data-evolution-id="<?php echo (int) $conta['id']; ?>">Atualizar</button>
                                            <button type="button" class="text-orange-600 hover:text-orange-800 evolution-escanear-qr-btn" data-evolution-id="<?php echo (int) $conta['id']; ?>" data-evolution-nome="<?php echo htmlspecialchars($conta['nome']); ?>">QR</button>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-restart-btn" data-evolution-id="<?php echo (int) $conta['id']; ?>">Reiniciar</button>
                                            <button type="button" class="text-slate-600 hover:text-slate-900 evolution-logout-btn" data-evolution-id="<?php echo (int) $conta['id']; ?>">Desconectar</button>
                                            <button type="button" class="text-orange-600 hover:text-orange-800 evolution-testar-btn" data-evolution-id="<?php echo (int) $conta['id']; ?>" data-evolution-nome="<?php echo htmlspecialchars($conta['nome']); ?>">Testar</button>
                                            <form method="POST" action="?tab=evolution" class="inline" onsubmit="return confirm('Remover esta conta do painel e apagar a instância no servidor (Evolution ou Uazapi)?');">
                                                <input type="hidden" name="config_tab" value="evolution">
                                                <input type="hidden" name="evolution_action" value="delete">
                                                <input type="hidden" name="evolution_id" value="<?php echo $conta['id']; ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-600">Excluir</button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <?php if ($contaApiPropria || $contaUazapi): ?>
                                        <button type="button" class="text-orange-600 hover:text-orange-700 text-xs font-medium mr-3 evolution-escanear-qr-btn" data-evolution-id="<?php echo (int)$conta['id']; ?>" data-evolution-nome="<?php echo htmlspecialchars($conta['nome']); ?>">QR</button>
                                        <?php endif; ?>
                                        <button type="button" class="text-orange-600 hover:text-orange-700 text-xs font-medium mr-3 evolution-testar-btn" data-evolution-id="<?php echo (int)$conta['id']; ?>" data-evolution-nome="<?php echo htmlspecialchars($conta['nome']); ?>">Testar</button>
                                        <a href="?tab=evolution&edit_evolution=<?php echo $conta['id']; ?>" class="text-orange-500 hover:text-orange-600 text-xs font-medium mr-3">Editar</a>
                                        <form method="POST" action="?tab=evolution" style="display: inline;" onsubmit="return confirm('Remover esta conta? Evolution e Uazapi (API própria) tentam apagar a instância na API; Uazapi provedor externo remove só o cadastro no painel.');">
                                            <input type="hidden" name="config_tab" value="evolution">
                                            <input type="hidden" name="evolution_action" value="delete">
                                            <input type="hidden" name="evolution_id" value="<?php echo $conta['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-600 text-xs font-medium">Excluir</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Modal Testar conexão -->
                <div id="evolution-testar-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" id="evolution-testar-backdrop"></div>
                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                            <form id="evolution-testar-form">
                                <input type="hidden" name="evolution_action" value="test">
                                <input type="hidden" name="evolution_id" id="evolution-testar-id" value="">
                                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                    <h3 class="text-lg font-bold text-gray-900 mb-2" id="evolution-testar-modal-title">Testar WhatsApp</h3>
                                    <p class="text-sm text-gray-500 mb-4">Envie uma mensagem de teste para um número. Se o número receber a mensagem no WhatsApp, a conexão está ativa.</p>
                                    <div class="mb-4">
                                        <label for="evolution_test_number" class="block text-sm font-medium text-gray-700 mb-1">Número para receber o teste (com DDD)</label>
                                        <input type="text" id="evolution_test_number" name="evolution_test_number" required
                                               placeholder="Ex: 11999998888 ou 5511999998888"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                        <p class="mt-1 text-xs text-gray-500">Apenas números. Ex: 11999998888 (11 = DDD)</p>
                                    </div>
                                    <div id="evolution-testar-result" class="hidden mb-4 p-3 rounded text-sm"></div>
                                </div>
                                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                                    <button type="submit" id="evolution-testar-submit" class="w-full sm:w-auto inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-orange-500 text-base font-medium text-white hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 sm:text-sm">
                                        Enviar mensagem de teste
                                    </button>
                                    <button type="button" id="evolution-testar-fechar" class="mt-3 sm:mt-0 w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 sm:text-sm">
                                        Fechar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Modal Escanear QR (API própria) -->
                <div id="evolution-qr-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-modal="true">
                    <div class="flex items-center justify-center min-h-screen px-4">
                        <div class="fixed inset-0 bg-gray-500 bg-opacity-75" id="evolution-qr-modal-backdrop"></div>
                        <div class="relative bg-white rounded-lg shadow-xl p-6 max-w-sm w-full">
                            <h3 class="text-lg font-bold text-gray-900 mb-2" id="evolution-qr-modal-title">Escanear QR Code</h3>
                            <p class="text-sm text-gray-500 mb-4">Abra o WhatsApp no celular e escaneie o código abaixo.</p>
                            <div class="flex justify-center mb-4">
                                <img id="evolution-qr-modal-img" src="" alt="QR Code" class="evolution-qr-bw max-w-[250px] border border-gray-300 rounded bg-white p-2">
                            </div>
                            <p id="evolution-qr-modal-pairing" class="text-sm text-gray-600 mb-4 hidden"></p>
                            <p id="evolution-qr-modal-status" class="text-sm text-gray-600 mb-4">Aguardando leitura do QR code...</p>
                            <div class="flex gap-2">
                                <button type="button" id="evolution-qr-modal-refresh" class="flex-1 bg-orange-500 hover:bg-orange-600 text-white py-2 px-4 rounded font-medium text-sm">Atualizar QR</button>
                                <button type="button" id="evolution-qr-modal-fechar" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 px-4 rounded font-medium text-sm">Fechar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                (function() {
                    window.achadinhosEnsureContaTableRow = function(contaId, done) {
                        var id = String(contaId);
                        var tbody = document.getElementById('evolution-contas-tbody');
                        if (!tbody) {
                            if (typeof done === 'function') done();
                            return;
                        }
                        if (tbody.querySelector('tr[data-evolution-conta-id="' + id + '"]')) {
                            if (typeof done === 'function') done();
                            return;
                        }
                        var fd = new FormData();
                        fd.append('evolution_action', 'conta_row_html');
                        fd.append('evolution_id', id);
                        fd.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data || !data.success || !data.html) {
                                    if (typeof done === 'function') done();
                                    return;
                                }
                                var ph = tbody.querySelector('tr.evolution-contas-empty-placeholder');
                                if (ph) ph.remove();
                                tbody.insertAdjacentHTML('beforeend', String(data.html).trim());
                                if (typeof done === 'function') done();
                            })
                            .catch(function() {
                                if (typeof done === 'function') done();
                            });
                    };
                    window.achadinhosReplaceEvolutionContaRow = function(contaId, done) {
                        var id = String(contaId);
                        var tbody = document.getElementById('evolution-contas-tbody');
                        if (!tbody) {
                            if (typeof done === 'function') done();
                            return;
                        }
                        var oldRow = tbody.querySelector('tr[data-evolution-conta-id="' + id + '"]');
                        var fd = new FormData();
                        fd.append('evolution_action', 'conta_row_html');
                        fd.append('evolution_id', id);
                        fd.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data || !data.success || !data.html) {
                                    if (typeof done === 'function') done();
                                    return;
                                }
                                var wrap = document.createElement('tbody');
                                wrap.innerHTML = String(data.html).trim();
                                var newRow = wrap.firstElementChild;
                                if (!newRow) {
                                    if (typeof done === 'function') done();
                                    return;
                                }
                                if (oldRow) {
                                    oldRow.replaceWith(newRow);
                                } else {
                                    var ph = tbody.querySelector('tr.evolution-contas-empty-placeholder');
                                    if (ph) ph.remove();
                                    tbody.appendChild(newRow);
                                }
                                if (typeof done === 'function') done();
                            })
                            .catch(function() {
                                if (typeof done === 'function') done();
                            });
                    };
                })();
                (function() {
                    var form = document.getElementById('evolution-tipo-form');
                    if (!form) return;
                    var radios = form.querySelectorAll('input[name="evolution_tipo"]');
                    var feedback = document.getElementById('evolution-provider-save-feedback');
                    function setFeedback(kind, text) {
                        if (!feedback) return;
                        feedback.textContent = text;
                        feedback.className = 'mt-3 text-sm ';
                        if (kind === 'success') feedback.className += 'text-emerald-700';
                        else if (kind === 'error') feedback.className += 'text-red-700';
                        else feedback.className += 'text-gray-500';
                    }
                    function applySelectionUI(selectedValue) {
                        form.querySelectorAll('.evolution-tipo-opt').forEach(function(opt) {
                            var active = opt.getAttribute('data-tipo') === selectedValue;
                            opt.classList.toggle('border-orange-500', active);
                            opt.classList.toggle('bg-orange-50/50', active);
                            opt.classList.toggle('border-gray-200', !active);
                        });
                    }
                    function toggleRadios(disabled) {
                        radios.forEach(function(radio) { radio.disabled = disabled; });
                    }
                    var checked = form.querySelector('input[name="evolution_tipo"]:checked');
                    if (checked) applySelectionUI(checked.value);
                    radios.forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            applySelectionUI(radio.value);
                            setFeedback('saving', 'Salvando...');
                            // FormData antes de desabilitar: radios disabled não são enviados e o PHP
                            // interpretaria falta de evolution_tipo como "terceiros".
                            var fd = new FormData(form);
                            fd.set('evolution_action', 'save_provider_ajax');
                            fd.set('config_tab', 'evolution');
                            fd.set('evolution_tipo', radio.value);
                            toggleRadios(true);
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    toggleRadios(false);
                                    if (data && data.success) {
                                        setFeedback('success', 'Salvo com sucesso.');
                                        window.setTimeout(function() {
                                            window.location.href = 'configuracoes.php?tab=evolution';
                                        }, 350);
                                    } else {
                                        setFeedback('error', (data && data.message) ? data.message : 'Erro ao salvar.');
                                    }
                                })
                                .catch(function() {
                                    toggleRadios(false);
                                    setFeedback('error', 'Erro de conexão ao salvar.');
                                });
                        });
                    });
                })();
                (function() {
                    var modal = document.getElementById('evolution-testar-modal');
                    var backdrop = document.getElementById('evolution-testar-backdrop');
                    var form = document.getElementById('evolution-testar-form');
                    var resultDiv = document.getElementById('evolution-testar-result');
                    var submitBtn = document.getElementById('evolution-testar-submit');
                    var idInput = document.getElementById('evolution-testar-id');
                    var titleEl = document.getElementById('evolution-testar-modal-title');

                    function openModal(evolutionId, evolutionNome) {
                        idInput.value = evolutionId;
                        titleEl.textContent = 'Testar conexão: ' + evolutionNome;
                        resultDiv.classList.add('hidden');
                        resultDiv.textContent = '';
                        document.getElementById('evolution_test_number').value = '';
                        modal.classList.remove('hidden');
                    }
                    function closeModal() {
                        modal.classList.add('hidden');
                    }
                    window.achadinhosEvolutionTestarOpen = openModal;
                    document.getElementById('evolution-testar-fechar').addEventListener('click', closeModal);
                    backdrop.addEventListener('click', closeModal);
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        resultDiv.classList.remove('hidden');
                        resultDiv.className = 'mb-4 p-3 rounded text-sm ';
                        resultDiv.textContent = 'Enviando...';
                        resultDiv.classList.add('bg-gray-100', 'text-gray-700');
                        submitBtn.disabled = true;
                        var formData = new FormData(form);
                        formData.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: formData })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                submitBtn.disabled = false;
                                if (data.success) {
                                    resultDiv.textContent = data.message;
                                    resultDiv.classList.add('bg-green-100', 'text-green-800');
                                } else {
                                    resultDiv.textContent = data.message || 'Erro ao enviar.';
                                    resultDiv.classList.add('bg-red-100', 'text-red-800');
                                }
                            })
                            .catch(function() {
                                submitBtn.disabled = false;
                                resultDiv.textContent = 'Erro de conexão. Tente novamente.';
                                resultDiv.classList.add('bg-red-100', 'text-red-800');
                            });
                    });
                })();
                (function() {
                    var criarBtn = document.getElementById('evolution-criar-instancia-btn');
                    var qrContainer = document.getElementById('evolution-qr-container');
                    var qrImg = document.getElementById('evolution-qr-img');
                    var qrPairing = document.getElementById('evolution-qr-pairing');
                    var refreshQrBtn = document.getElementById('evolution-refresh-qr-btn');
                    var reloadPageBtn = document.getElementById('evolution-reload-page-btn');
                    var qrModal = document.getElementById('evolution-qr-modal');
                    var qrModalImg = document.getElementById('evolution-qr-modal-img');
                    var qrModalTitle = document.getElementById('evolution-qr-modal-title');
                    var qrModalPairing = document.getElementById('evolution-qr-modal-pairing');
                    var qrStatus = document.getElementById('evolution-qr-status');
                    var qrModalStatus = document.getElementById('evolution-qr-modal-status');
                    var qrModalRefresh = document.getElementById('evolution-qr-modal-refresh');
                    var qrModalFechar = document.getElementById('evolution-qr-modal-fechar');
                    var qrModalBackdrop = document.getElementById('evolution-qr-modal-backdrop');
                    var currentQrContaId = null;
                    var statusPollTimer = null;
                    var statusPollDeadline = 0;
                    function codeToImgSrc(code) {
                        if (!code) return '';
                        if (code.startsWith('data:') || code.startsWith('/9j/') || String(code).match(/^[A-Za-z0-9+\/=]{100,}$/))
                            return code.startsWith('data:') ? code : 'data:image/png;base64,' + code;
                        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' + encodeURIComponent(code) + '&color=000000&bgcolor=ffffff&qzone=1';
                    }
                    function setQrStatus(text, isError, isSuccess) {
                        if (qrStatus) {
                            qrStatus.textContent = text;
                            qrStatus.className = 'text-sm ' + (isError ? 'text-red-700' : (isSuccess ? 'text-emerald-700' : 'text-gray-600'));
                        }
                        if (qrModalStatus) {
                            qrModalStatus.textContent = text;
                            qrModalStatus.className = 'text-sm mb-4 ' + (isError ? 'text-red-700' : (isSuccess ? 'text-emerald-700' : 'text-gray-600'));
                        }
                    }
                    function markContaConnected(contaId) {
                        var el = document.querySelector('.evo-wa-state[data-evolution-wa-id="' + String(contaId) + '"]');
                        if (el) {
                            el.textContent = 'WhatsApp: Conectado';
                            el.className = 'evo-wa-state text-xs text-emerald-700 font-medium max-w-[220px] leading-snug';
                            return;
                        }
                        var btn = document.querySelector('.evolution-escanear-qr-btn[data-evolution-id="' + String(contaId) + '"]');
                        if (!btn) return;
                        var row = btn.closest('tr');
                        if (!row) return;
                        var statusCell = row.querySelector('td:nth-child(4)');
                        if (statusCell) {
                            statusCell.innerHTML = '<span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Conectado</span>';
                        }
                    }
                    function stopStatusPolling() {
                        if (statusPollTimer) {
                            clearInterval(statusPollTimer);
                            statusPollTimer = null;
                        }
                    }
                    function humanState(stateNorm) {
                        if (!stateNorm) return 'Aguardando leitura do QR code...';
                        if (stateNorm.indexOf('open') !== -1 || stateNorm.indexOf('connected') !== -1 || stateNorm.indexOf('online') !== -1) return 'Conectado';
                        if (stateNorm.indexOf('qr') !== -1 && stateNorm.indexOf('read') !== -1) return 'QR lido, aguardando confirmação...';
                        if (stateNorm.indexOf('pair') !== -1 || stateNorm.indexOf('sync') !== -1 || stateNorm.indexOf('connect') !== -1) return 'Conectando...';
                        if (stateNorm.indexOf('close') !== -1 || stateNorm.indexOf('disconnected') !== -1) return 'Aguardando leitura do QR code...';
                        if (stateNorm.indexOf('invalid') !== -1 || stateNorm.indexOf('fail') !== -1 || stateNorm.indexOf('error') !== -1) return 'Falha ao conectar.';
                        return 'Status: ' + stateNorm;
                    }
                    function startStatusPolling(contaId) {
                        stopStatusPolling();
                        if (!contaId) return;
                        statusPollDeadline = Date.now() + (3 * 60 * 1000);
                        var run = function() {
                            if (!currentQrContaId || Number(currentQrContaId) !== Number(contaId)) {
                                stopStatusPolling();
                                return;
                            }
                            if (Date.now() > statusPollDeadline) {
                                stopStatusPolling();
                                setQrStatus('Tempo limite para conexão. Atualize o QR code e tente novamente.', true, false);
                                return;
                            }
                            var fd = new FormData();
                            fd.append('evolution_action', 'connection_status');
                            fd.append('evolution_id', contaId);
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (!data || !data.success) {
                                        setQrStatus((data && data.message) ? data.message : 'Erro ao monitorar conexão.', true, false);
                                        return;
                                    }
                                    var stateNorm = data.state_norm || '';
                                    if (data.connected) {
                                        stopStatusPolling();
                                        setQrStatus(data.state_label || 'WhatsApp conectado com sucesso.', false, true);
                                        var cidConn = data.conta_id || contaId;
                                        var closeQrUi = function() {
                                            setTimeout(function() {
                                                if (qrContainer) qrContainer.classList.add('hidden');
                                                if (qrModal) qrModal.classList.add('hidden');
                                            }, 800);
                                        };
                                        var finishConn = function() {
                                            markContaConnected(cidConn);
                                            closeQrUi();
                                        };
                                        var afterRow = function() {
                                            if (window.achadinhosReplaceEvolutionContaRow) {
                                                window.achadinhosReplaceEvolutionContaRow(cidConn, closeQrUi);
                                            } else {
                                                finishConn();
                                            }
                                        };
                                        if (window.achadinhosEnsureContaTableRow) {
                                            window.achadinhosEnsureContaTableRow(cidConn, afterRow);
                                        } else {
                                            afterRow();
                                        }
                                        return;
                                    }
                                    if (stateNorm.indexOf('invalid') !== -1 || stateNorm.indexOf('fail') !== -1 || stateNorm.indexOf('error') !== -1) {
                                        stopStatusPolling();
                                        setQrStatus(humanState(stateNorm) + ' Gere um novo QR e tente novamente.', true, false);
                                        return;
                                    }
                                    setQrStatus(data.state_label || humanState(stateNorm), false, false);
                                })
                                .catch(function() {
                                    setQrStatus('Falha de rede ao monitorar conexão.', true, false);
                                });
                        };
                        run();
                        statusPollTimer = setInterval(run, 3000);
                    }
                    if (criarBtn && qrContainer) {
                        criarBtn.addEventListener('click', function() {
                            var nome = (document.getElementById('evolution_nova_nome') || {}).value || '';
                            var instancia = (document.getElementById('evolution_nova_instancia') || {}).value || '';
                            var apiUrl = (document.getElementById('evolution_api_url') || {}).value || '';
                            var apiKey = (document.getElementById('evolution_api_key_global') || {}).value || '';
                            if (!nome.trim() || !instancia.trim() || !apiUrl.trim() || !apiKey.trim()) {
                                alert('Preencha todos os campos: Nome, Instância, URL da API e API Key global.');
                                return;
                            }
                            criarBtn.disabled = true;
                            criarBtn.textContent = 'Criando...';
                            var fd = new FormData();
                            fd.append('evolution_action', 'create_instance');
                            fd.append('evolution_nome', nome.trim());
                            fd.append('evolution_instancia', instancia.trim());
                            fd.append('evolution_api_url', apiUrl.trim());
                            fd.append('evolution_api_key', apiKey.trim());
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    if (data.success) {
                                        if (data.needs_qr && !data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Conta salva. Gere o QR na lista de contas.');
                                            window.location.reload();
                                            return;
                                        }
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Instância criada, mas não há QR nem código de pareamento para mostrar.');
                                            return;
                                        }
                                        if (qrContainer) {
                                            qrContainer.classList.remove('hidden');
                                            var imgSrc = data.qr_code ? codeToImgSrc(data.qr_code) : '';
                                            if (qrImg) {
                                                if (imgSrc) { qrImg.src = imgSrc; qrImg.style.display = 'block'; } else { qrImg.style.display = 'none'; }
                                                qrImg.onerror = function() { this.style.display = 'none'; };
                                            }
                                            if (qrPairing) { qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : ''; qrPairing.classList.toggle('hidden', !data.pairing_code); }
                                            currentQrContaId = data.conta_id || null;
                                            setQrStatus('Aguardando leitura do QR code...', false, false);
                                            startStatusPolling(currentQrContaId);
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao criar instância.');
                                    }
                                })
                                .catch(function() {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    alert('Erro de conexão.');
                                });
                        });
                    }
                    if (reloadPageBtn) {
                        reloadPageBtn.addEventListener('click', function() { location.reload(); });
                    }
                    if (refreshQrBtn && qrImg) {
                        refreshQrBtn.addEventListener('click', function() {
                            if (!currentQrContaId) return;
                            var fd = new FormData();
                            fd.append('evolution_action', 'refresh_qr');
                            fd.append('evolution_id', currentQrContaId);
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) {
                                        if (data.qr_code) {
                                            qrImg.src = codeToImgSrc(data.qr_code);
                                            qrImg.style.display = 'block';
                                        } else {
                                            qrImg.style.display = 'none';
                                        }
                                        if (qrPairing) {
                                            qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : '';
                                            qrPairing.classList.toggle('hidden', !data.pairing_code);
                                        }
                                        setQrStatus('QR code atualizado. Aguardando leitura...', false, false);
                                        startStatusPolling(currentQrContaId);
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Não foi possível obter o QR.');
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao atualizar QR.');
                                    }
                                });
                        });
                    }
                    function openQrModal(contaId, contaNome) {
                        currentQrContaId = contaId;
                        qrModalTitle.textContent = 'Escanear QR: ' + contaNome;
                        qrModal.classList.remove('hidden');
                        var fd = new FormData();
                        fd.append('evolution_action', 'refresh_qr');
                        fd.append('evolution_id', contaId);
                        fd.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (data.qr_code) {
                                        qrModalImg.src = codeToImgSrc(data.qr_code);
                                        qrModalImg.style.display = 'block';
                                    } else {
                                        qrModalImg.style.display = 'none';
                                    }
                                    qrModalPairing.textContent = data.pairing_code ? 'Código: ' + data.pairing_code : '';
                                    qrModalPairing.classList.toggle('hidden', !data.pairing_code);
                                    setQrStatus('Aguardando leitura do QR code...', false, false);
                                    startStatusPolling(contaId);
                                    if (!data.qr_code && !data.pairing_code) {
                                        qrModalPairing.textContent = data.message || 'Não foi possível obter o QR code.';
                                        qrModalPairing.classList.remove('hidden');
                                    }
                                } else {
                                    qrModalImg.style.display = 'none';
                                    qrModalPairing.textContent = data.message || 'Não foi possível obter o QR code.';
                                    qrModalPairing.classList.remove('hidden');
                                }
                            });
                    }
                    function closeQrModal() {
                        qrModal.classList.add('hidden');
                        currentQrContaId = null;
                        stopStatusPolling();
                    }
                    window.achadinhosEvolutionQrOpen = openQrModal;
                    if (qrModalFechar) qrModalFechar.addEventListener('click', closeQrModal);
                    if (qrModalBackdrop) qrModalBackdrop.addEventListener('click', closeQrModal);
                    if (qrModalRefresh) {
                        qrModalRefresh.addEventListener('click', function() {
                            if (!currentQrContaId) return;
                            var fd = new FormData();
                            fd.append('evolution_action', 'refresh_qr');
                            fd.append('evolution_id', currentQrContaId);
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) {
                                        if (data.qr_code) {
                                            qrModalImg.src = codeToImgSrc(data.qr_code);
                                            qrModalImg.style.display = 'block';
                                        } else {
                                            qrModalImg.style.display = 'none';
                                        }
                                        if (qrModalPairing) {
                                            qrModalPairing.textContent = data.pairing_code ? 'Código: ' + data.pairing_code : '';
                                            qrModalPairing.classList.toggle('hidden', !data.pairing_code);
                                        }
                                        setQrStatus('QR code atualizado. Aguardando leitura...', false, false);
                                        startStatusPolling(currentQrContaId);
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Não foi possível obter o QR.');
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao atualizar QR.');
                                    }
                                });
                        });
                    }
                })();
                (function() {
                    function applyWaStateStyle(el, connected) {
                        el.className = 'evo-wa-state text-xs max-w-[220px] leading-snug ' + (connected ? 'text-emerald-700 font-medium' : 'text-slate-600');
                    }
                    function refreshPropriaConnectionStatus(contaId, el) {
                        if (!contaId || !el) return;
                        el.textContent = 'WhatsApp: consultando…';
                        var fd = new FormData();
                        fd.append('evolution_action', 'connection_status');
                        fd.append('evolution_id', String(contaId));
                        fd.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    el.textContent = 'WhatsApp: ' + ((data && data.message) ? data.message : 'erro ao consultar');
                                    applyWaStateStyle(el, false);
                                    return;
                                }
                                if (data.connected) {
                                    el.textContent = 'WhatsApp: Conectado';
                                    applyWaStateStyle(el, true);
                                    return;
                                }
                                var lbl = data.state_label || data.state_norm || '…';
                                if (String(lbl).toLowerCase() === 'array') {
                                    lbl = 'Indefinido';
                                }
                                el.textContent = 'WhatsApp: ' + lbl;
                                applyWaStateStyle(el, false);
                            })
                            .catch(function() {
                                el.textContent = 'WhatsApp: falha de rede';
                                applyWaStateStyle(el, false);
                            });
                    }
                    function refreshAllPropriaRows() {
                        document.querySelectorAll('.evo-wa-state[data-evolution-wa-id]').forEach(function(el) {
                            var id = el.getAttribute('data-evolution-wa-id');
                            if (id) refreshPropriaConnectionStatus(id, el);
                        });
                    }
                    if (document.querySelector('.evo-wa-state[data-evolution-wa-id]')) {
                        refreshAllPropriaRows();
                        setInterval(function() {
                            if (typeof document.hidden !== 'undefined' && document.hidden) return;
                            refreshAllPropriaRows();
                        }, 8000);
                        document.addEventListener('visibilitychange', function() {
                            if (!document.hidden) refreshAllPropriaRows();
                        });
                    }
                    window.achadinhosEvolutionStatusRefreshClick = function(contaId) {
                        var id = contaId;
                        var el = document.querySelector('.evo-wa-state[data-evolution-wa-id="' + String(id) + '"]');
                        refreshPropriaConnectionStatus(id, el);
                    };
                    function postEvolutionInstanceAction(action, contaId) {
                        var fd = new FormData();
                        fd.append('evolution_action', action);
                        fd.append('evolution_id', String(contaId));
                        fd.append('config_tab', 'evolution');
                        fetch('?tab=evolution', { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                alert((data && data.message) ? data.message : 'Concluído.');
                                refreshAllPropriaRows();
                            })
                            .catch(function() { alert('Erro de conexão.'); });
                    }
                    window.achadinhosEvolutionRestartClick = function(id) {
                        if (!id || !confirm('Reiniciar esta instância no servidor (Evolution ou Uazapi)?')) return;
                        postEvolutionInstanceAction('evolution_restart', id);
                    };
                    window.achadinhosEvolutionLogoutClick = function(id) {
                        if (!id || !confirm('Desconectar o WhatsApp desta instância? Será necessário escanear o QR de novo para conectar.')) return;
                        postEvolutionInstanceAction('evolution_logout', id);
                    };
                })();
                (function() {
                    window._achUazQrPollFactory = function(opts) {
                        opts = opts || {};
                        var timer = null;
                        var deadline = 0;
                        var activeId = null;
                        function stop() {
                            if (timer) { clearInterval(timer); timer = null; }
                            activeId = null;
                        }
                        function markRow(contaId) {
                            var el = document.querySelector('.evo-wa-state[data-evolution-wa-id="' + String(contaId) + '"]');
                            if (el) {
                                el.textContent = 'WhatsApp: Conectado';
                                el.className = 'evo-wa-state text-xs text-emerald-700 font-medium max-w-[220px] leading-snug';
                                return;
                            }
                            var btn = document.querySelector('.evolution-escanear-qr-btn[data-evolution-id="' + String(contaId) + '"]');
                            if (!btn) return;
                            var row = btn.closest('tr');
                            if (!row) return;
                            var statusCell = row.querySelector('td:nth-child(4)');
                            if (statusCell) {
                                statusCell.innerHTML = '<span class="inline-flex px-2 py-0.5 text-xs rounded-md bg-emerald-50 text-emerald-700">Conectado</span>';
                            }
                        }
                        function refreshTableRow(contaId, thenFn) {
                            var cid = String(contaId);
                            function done() {
                                if (typeof thenFn === 'function') thenFn();
                            }
                            if (window.achadinhosReplaceEvolutionContaRow) {
                                window.achadinhosReplaceEvolutionContaRow(cid, done);
                            } else if (window.achadinhosEnsureContaTableRow) {
                                window.achadinhosEnsureContaTableRow(cid, function() {
                                    markRow(cid);
                                    done();
                                });
                            } else {
                                markRow(cid);
                                done();
                            }
                        }
                        function tick() {
                            if (!activeId) return;
                            if (Date.now() > deadline) {
                                stop();
                                if (opts.statusEl) {
                                    opts.statusEl.textContent = 'Tempo esgotado. Atualize o QR.';
                                    opts.statusEl.className = 'text-sm text-amber-800 mb-2';
                                }
                                return;
                            }
                            var fd = new FormData();
                            fd.append('evolution_action', 'connection_status');
                            fd.append('evolution_id', String(activeId));
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (!data || !data.success) {
                                        if (opts.statusEl) {
                                            opts.statusEl.textContent = (data && data.message) ? data.message : 'Erro ao consultar status da instância.';
                                            opts.statusEl.className = 'text-sm text-red-600 mb-2';
                                        }
                                        return;
                                    }
                                    if (data.connected) {
                                        stop();
                                        var cidU = activeId;
                                        var payload = data;
                                        function afterUi() {
                                            if (opts.onConnected) opts.onConnected(cidU, payload);
                                        }
                                        if (window.achadinhosEnsureContaTableRow) {
                                            window.achadinhosEnsureContaTableRow(cidU, function() {
                                                refreshTableRow(cidU, afterUi);
                                            });
                                        } else {
                                            refreshTableRow(cidU, afterUi);
                                        }
                                        return;
                                    }
                                    var sn = (data.state_norm || '').toLowerCase();
                                    if (sn.indexOf('invalid') !== -1 || sn.indexOf('fail') !== -1 || sn.indexOf('refused') !== -1
                                        || (sn.indexOf('error') !== -1 && sn.indexOf('no error') === -1)) {
                                        stop();
                                        if (opts.statusEl) {
                                            opts.statusEl.textContent = (data.state_label || 'Falha ao conectar.') + ' Gere um novo QR code e tente novamente.';
                                            opts.statusEl.className = 'text-sm text-red-600 mb-2';
                                        }
                                        if (typeof opts.onConnectError === 'function') {
                                            opts.onConnectError(activeId, data);
                                        }
                                        return;
                                    }
                                    if (opts.statusEl) {
                                        opts.statusEl.textContent = opts.waitingText
                                            ? (data.state_label ? (opts.waitingText + ' (' + data.state_label + ')') : opts.waitingText)
                                            : (data.state_label || 'Aguardando…');
                                        opts.statusEl.className = 'text-sm text-gray-600 mb-2';
                                    }
                                })
                                .catch(function() {
                                    if (opts.statusEl) {
                                        opts.statusEl.textContent = 'Falha de rede ao consultar status.';
                                        opts.statusEl.className = 'text-sm text-red-600 mb-2';
                                    }
                                });
                        }
                        return {
                            start: function(contaId) {
                                stop();
                                if (!contaId) return;
                                activeId = contaId;
                                deadline = Date.now() + 3 * 60 * 1000;
                                timer = setInterval(tick, 2500);
                                tick();
                            },
                            stop: stop
                        };
                    };
                })();
                (function() {
                    var criarBtn = document.getElementById('uazapi-criar-instancia-btn');
                    var qrContainer = document.getElementById('uazapi-qr-container');
                    var qrImg = document.getElementById('uazapi-qr-img');
                    var qrPairing = document.getElementById('uazapi-qr-pairing');
                    var qrStatus = document.getElementById('uazapi-qr-status');
                    var refreshQrBtn = document.getElementById('uazapi-refresh-qr-btn');
                    var uazapiReloadPageBtn = document.getElementById('uazapi-reload-page-btn');
                    var uazapiCurrentQrContaId = null;
                    var poller = window._achUazQrPollFactory ? window._achUazQrPollFactory({
                        statusEl: qrStatus,
                        waitingText: 'Escaneie o QR no WhatsApp. Aguardando conexão…',
                        onConnected: function(id, data) {
                            if (qrImg) { qrImg.style.display = 'none'; try { qrImg.removeAttribute('src'); } catch (e) {} }
                            if (qrPairing) { qrPairing.classList.add('hidden'); qrPairing.textContent = ''; }
                            if (qrStatus) {
                                qrStatus.textContent = (data.state_label || 'Conectado.') + ' A lista de contas foi atualizada.';
                                qrStatus.className = 'text-sm text-emerald-700 mb-2';
                            }
                            window.setTimeout(function() {
                                if (qrContainer) qrContainer.classList.add('hidden');
                            }, 1200);
                        }
                    }) : { start: function() {}, stop: function() {} };
                    function codeToImgSrc(code) {
                        if (!code) return '';
                        if (code.startsWith('data:') || code.startsWith('/9j/') || String(code).match(/^[A-Za-z0-9+\/=]{100,}$/))
                            return code.startsWith('data:') ? code : 'data:image/png;base64,' + code;
                        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' + encodeURIComponent(code) + '&color=000000&bgcolor=ffffff&qzone=1';
                    }
                    if (criarBtn && qrContainer) {
                        criarBtn.addEventListener('click', function() {
                            var nome = (document.getElementById('uazapi_nova_nome') || {}).value || '';
                            var instancia = (document.getElementById('uazapi_nova_instancia') || {}).value || '';
                            var apiUrl = (document.getElementById('uazapi_nova_api_url') || {}).value || '';
                            var adminTok = (document.getElementById('uazapi_nova_admin_token') || {}).value || '';
                            if (!nome.trim() || !instancia.trim() || !apiUrl.trim() || !adminTok.trim()) {
                                alert('Preencha nome, instância, URL base da Uazapi e token.');
                                return;
                            }
                            poller.stop();
                            criarBtn.disabled = true;
                            criarBtn.textContent = 'Criando...';
                            var fd = new FormData();
                            fd.append('evolution_action', 'uazapi_create_instance');
                            fd.append('uazapi_nome', nome.trim());
                            fd.append('uazapi_instancia', instancia.trim());
                            fd.append('uazapi_api_url', apiUrl.trim());
                            fd.append('uazapi_admin_token', adminTok.trim());
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    if (data.success && qrContainer) {
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Instância criada, mas não há QR nem código de pareamento para mostrar.');
                                            return;
                                        }
                                        var newId = data.conta_id || null;
                                        function showQrAndPoll() {
                                            qrContainer.classList.remove('hidden');
                                            var imgSrc = data.qr_code ? codeToImgSrc(data.qr_code) : '';
                                            if (qrImg) {
                                                if (imgSrc) { qrImg.src = imgSrc; qrImg.style.display = 'block'; } else { qrImg.style.display = 'none'; }
                                                qrImg.onerror = function() { this.style.display = 'none'; };
                                            }
                                            if (qrPairing) { qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : ''; qrPairing.classList.toggle('hidden', !data.pairing_code); }
                                            uazapiCurrentQrContaId = newId;
                                            if (qrStatus) {
                                                qrStatus.textContent = 'Escaneie o QR no WhatsApp. Aguardando conexão…';
                                                qrStatus.className = 'text-sm text-gray-600 mb-2';
                                            }
                                            poller.start(uazapiCurrentQrContaId);
                                        }
                                        if (newId && window.achadinhosEnsureContaTableRow) {
                                            window.achadinhosEnsureContaTableRow(newId, showQrAndPoll);
                                        } else {
                                            showQrAndPoll();
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao criar instância Uazapi.');
                                    }
                                })
                                .catch(function() {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    alert('Erro de conexão.');
                                });
                        });
                    }
                    if (uazapiReloadPageBtn) {
                        uazapiReloadPageBtn.addEventListener('click', function() {
                            poller.stop();
                            location.reload();
                        });
                    }
                    if (refreshQrBtn && qrImg) {
                        refreshQrBtn.addEventListener('click', function() {
                            if (!uazapiCurrentQrContaId) return;
                            var fd = new FormData();
                            fd.append('evolution_action', 'refresh_qr');
                            fd.append('evolution_id', uazapiCurrentQrContaId);
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) {
                                        if (data.qr_code) {
                                            qrImg.src = codeToImgSrc(data.qr_code);
                                            qrImg.style.display = 'block';
                                        } else {
                                            qrImg.style.display = 'none';
                                        }
                                        if (qrPairing) {
                                            qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : '';
                                            qrPairing.classList.toggle('hidden', !data.pairing_code);
                                        }
                                        if (qrStatus) {
                                            qrStatus.textContent = 'Escaneie o QR no WhatsApp. Aguardando conexão…';
                                            qrStatus.className = 'text-sm text-gray-600 mb-2';
                                        }
                                        poller.start(uazapiCurrentQrContaId);
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Não foi possível obter o QR.');
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao atualizar QR.');
                                    }
                                });
                        });
                    }
                })();
                (function() {
                    var criarBtn = document.getElementById('uazapi-propria-criar-instancia-btn');
                    var qrContainer = document.getElementById('uazapi-propria-qr-container');
                    var qrImg = document.getElementById('uazapi-propria-qr-img');
                    var qrPairing = document.getElementById('uazapi-propria-qr-pairing');
                    var qrStatus = document.getElementById('uazapi-propria-qr-status');
                    var refreshQrBtn = document.getElementById('uazapi-propria-refresh-qr-btn');
                    var reloadBtn = document.getElementById('uazapi-propria-reload-page-btn');
                    var uazapiPropriaCurrentQrContaId = null;
                    var poller = window._achUazQrPollFactory ? window._achUazQrPollFactory({
                        statusEl: qrStatus,
                        waitingText: 'Escaneie o QR no WhatsApp. Aguardando conexão…',
                        onConnected: function(id, data) {
                            if (qrImg) { qrImg.style.display = 'none'; try { qrImg.removeAttribute('src'); } catch (e) {} }
                            if (qrPairing) { qrPairing.classList.add('hidden'); qrPairing.textContent = ''; }
                            if (qrStatus) {
                                qrStatus.textContent = (data.state_label || 'Conectado.') + ' A lista de contas foi atualizada.';
                                qrStatus.className = 'text-sm text-emerald-700 mb-2';
                            }
                            window.setTimeout(function() {
                                if (qrContainer) qrContainer.classList.add('hidden');
                            }, 1200);
                        }
                    }) : { start: function() {}, stop: function() {} };
                    function codeToImgSrc(code) {
                        if (!code) return '';
                        if (code.startsWith('data:') || code.startsWith('/9j/') || String(code).match(/^[A-Za-z0-9+\/=]{100,}$/))
                            return code.startsWith('data:') ? code : 'data:image/png;base64,' + code;
                        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' + encodeURIComponent(code) + '&color=000000&bgcolor=ffffff&qzone=1';
                    }
                    if (criarBtn && qrContainer) {
                        criarBtn.addEventListener('click', function() {
                            var nome = (document.getElementById('uazapi_propria_nova_nome') || {}).value || '';
                            var instancia = (document.getElementById('uazapi_propria_nova_instancia') || {}).value || '';
                            var apiUrl = (document.getElementById('uazapi_propria_api_url') || {}).value || '';
                            if (!nome.trim() || !instancia.trim() || !apiUrl.trim()) {
                                alert('Preencha nome da conta, nome da instância e salve a URL base da Uazapi no formulário acima.');
                                return;
                            }
                            poller.stop();
                            criarBtn.disabled = true;
                            criarBtn.textContent = 'Criando...';
                            var fd = new FormData();
                            fd.append('evolution_action', 'uazapi_create_instance');
                            fd.append('uazapi_nome', nome.trim());
                            fd.append('uazapi_instancia', instancia.trim());
                            fd.append('uazapi_api_url', apiUrl.trim());
                            fd.append('uazapi_admin_token', '');
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    if (data.success && qrContainer) {
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Instância criada, mas não há QR nem código de pareamento para mostrar.');
                                            return;
                                        }
                                        var newIdP = data.conta_id || null;
                                        function showQrAndPollP() {
                                            qrContainer.classList.remove('hidden');
                                            var imgSrc = data.qr_code ? codeToImgSrc(data.qr_code) : '';
                                            if (qrImg) {
                                                if (imgSrc) { qrImg.src = imgSrc; qrImg.style.display = 'block'; } else { qrImg.style.display = 'none'; }
                                                qrImg.onerror = function() { this.style.display = 'none'; };
                                            }
                                            if (qrPairing) { qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : ''; qrPairing.classList.toggle('hidden', !data.pairing_code); }
                                            uazapiPropriaCurrentQrContaId = newIdP;
                                            if (qrStatus) {
                                                qrStatus.textContent = 'Escaneie o QR no WhatsApp. Aguardando conexão…';
                                                qrStatus.className = 'text-sm text-gray-600 mb-2';
                                            }
                                            poller.start(uazapiPropriaCurrentQrContaId);
                                        }
                                        if (newIdP && window.achadinhosEnsureContaTableRow) {
                                            window.achadinhosEnsureContaTableRow(newIdP, showQrAndPollP);
                                        } else {
                                            showQrAndPollP();
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao criar instância Uazapi.');
                                    }
                                })
                                .catch(function() {
                                    criarBtn.disabled = false;
                                    criarBtn.textContent = 'Criar instância e escanear QR';
                                    alert('Erro de conexão.');
                                });
                        });
                    }
                    if (reloadBtn) reloadBtn.addEventListener('click', function() {
                        poller.stop();
                        location.reload();
                    });
                    if (refreshQrBtn && qrImg) {
                        refreshQrBtn.addEventListener('click', function() {
                            if (!uazapiPropriaCurrentQrContaId) return;
                            var fd = new FormData();
                            fd.append('evolution_action', 'refresh_qr');
                            fd.append('evolution_id', uazapiPropriaCurrentQrContaId);
                            fd.append('config_tab', 'evolution');
                            fetch('?tab=evolution', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) {
                                        if (data.qr_code) {
                                            qrImg.src = codeToImgSrc(data.qr_code);
                                            qrImg.style.display = 'block';
                                        } else {
                                            qrImg.style.display = 'none';
                                        }
                                        if (qrPairing) {
                                            qrPairing.textContent = data.pairing_code ? 'Código de pareamento: ' + data.pairing_code : '';
                                            qrPairing.classList.toggle('hidden', !data.pairing_code);
                                        }
                                        if (qrStatus) {
                                            qrStatus.textContent = 'Escaneie o QR no WhatsApp. Aguardando conexão…';
                                            qrStatus.className = 'text-sm text-gray-600 mb-2';
                                        }
                                        poller.start(uazapiPropriaCurrentQrContaId);
                                        if (!data.qr_code && !data.pairing_code) {
                                            alert(data.message || 'Não foi possível obter o QR.');
                                        }
                                    } else {
                                        alert(data.message || 'Erro ao atualizar QR.');
                                    }
                                });
                        });
                    }
                })();
                (function() {
                    var modal = document.getElementById('evolution-conta-modal');
                    if (!modal) return;

                    var cfg = <?php echo json_encode([
                        'evolutionTipo' => $evolutionTipo,
                        'uazapiApiUrl' => $uazapiApiUrl,
                        'formUazapi' => !empty($formUazapi),
                        'tituloEditar' => $evolutionTituloModalEditar ?? 'Editar conta WhatsApp',
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

                    var backdrop = document.getElementById('evolution-conta-modal-backdrop');
                    var btnNova = document.getElementById('evolution-abrir-modal-nova-conta');
                    var btnFechar = document.getElementById('evolution-conta-modal-fechar');
                    var btnCancel = document.getElementById('evolution-conta-modal-cancelar');
                    var hidId = document.getElementById('evolution_conta_hidden_id');
                    var form = document.getElementById('evolution-conta-form');
                    var titleText = document.getElementById('evolution-conta-modal-title-text');
                    var descUazapi = document.getElementById('evolution-conta-modal-desc-uazapi');
                    var wrapAdmin = document.getElementById('evolution-conta-uazapi-admin-wrap');
                    var lblUrl = document.getElementById('evolution_url_base_label');
                    var lblKey = document.getElementById('evolution_api_key_label');
                    var inpNome = document.getElementById('evolution_nome');
                    var inpInst = document.getElementById('evolution_instancia');
                    var inpUrl = document.getElementById('evolution_url_base');
                    var inpKey = document.getElementById('evolution_api_key');
                    var chkAtivo = document.getElementById('evolution_ativo_chk');
                    var tokAdmin = document.getElementById('uazapi_admin_token');

                    function uazapiUi(on) {
                        if (descUazapi) descUazapi.classList.toggle('hidden', !on);
                        if (wrapAdmin) wrapAdmin.classList.toggle('hidden', !on);
                        if (lblUrl) lblUrl.textContent = on ? 'URL base da API Uazapi *' : 'URL Base *';
                        if (lblKey) lblKey.textContent = on ? 'Token da instância *' : 'API Key *';
                        if (inpUrl) {
                            inpUrl.placeholder = on ? 'https://sua-api.uazapi.com' : 'https://evolution.exemplo.com';
                        }
                        if (inpKey) {
                            inpKey.placeholder = on ? 'Token retornado pela Uazapi' : 'Sua API Key';
                        }
                    }

                    function stripEditEvolutionFromUrl() {
                        if (!history.replaceState) return;
                        var u = new URL(window.location.href);
                        if (!u.searchParams.has('edit_evolution')) return;
                        u.searchParams.delete('edit_evolution');
                        var q = u.searchParams.toString();
                        history.replaceState({}, '', u.pathname + (q ? '?' + q : ''));
                    }

                    function openModal() {
                        modal.classList.remove('hidden');
                        document.body.classList.add('overflow-hidden');
                    }

                    function closeModal() {
                        modal.classList.add('hidden');
                        document.body.classList.remove('overflow-hidden');
                        stripEditEvolutionFromUrl();
                    }

                    function openNovaConta() {
                        if (hidId) hidId.value = '';
                        if (inpNome) inpNome.value = '';
                        if (inpInst) inpInst.value = '';
                        if (inpUrl) {
                            var isUaTipo = cfg.evolutionTipo === 'uazapi' || cfg.evolutionTipo === 'uazapi_propria';
                            inpUrl.value = (isUaTipo && cfg.uazapiApiUrl) ? String(cfg.uazapiApiUrl) : '';
                            inpUrl.required = true;
                        }
                        if (inpKey) {
                            inpKey.value = '';
                            inpKey.required = true;
                        }
                        if (tokAdmin) tokAdmin.value = '';
                        if (chkAtivo) chkAtivo.checked = true;
                        var isUa = cfg.evolutionTipo === 'uazapi' || cfg.evolutionTipo === 'uazapi_propria';
                        uazapiUi(isUa);
                        if (titleText) {
                            titleText.textContent = isUa ? 'Nova conta Uazapi (manual)' : 'Nova conta WhatsApp';
                        }
                        openModal();
                    }

                    if (btnNova) btnNova.addEventListener('click', function(e) { e.preventDefault(); openNovaConta(); });
                    if (btnFechar) btnFechar.addEventListener('click', closeModal);
                    if (btnCancel) btnCancel.addEventListener('click', closeModal);
                    if (backdrop) backdrop.addEventListener('click', closeModal);

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                            closeModal();
                        }
                    });

                    var autoOpenEdit = <?php echo ($editEvolution && (int) ($editEvolution['id'] ?? 0) > 0) ? 'true' : 'false'; ?>;
                    if (autoOpenEdit) {
                        document.addEventListener('DOMContentLoaded', function() {
                            uazapiUi(!!cfg.formUazapi);
                            if (titleText) titleText.textContent = cfg.tituloEditar || 'Editar conta WhatsApp';
                            openModal();
                            stripEditEvolutionFromUrl();
                        });
                    }
                })();
                (function() {
                    var tbody = document.getElementById('evolution-contas-tbody');
                    if (!tbody) return;
                    tbody.addEventListener('click', function(e) {
                        var t = e.target;
                        if (!t || !t.closest) return;
                        if (t.closest('form')) return;
                        var testar = t.closest('.evolution-testar-btn');
                        if (testar && window.achadinhosEvolutionTestarOpen) {
                            e.preventDefault();
                            window.achadinhosEvolutionTestarOpen(testar.getAttribute('data-evolution-id'), testar.getAttribute('data-evolution-nome'));
                            return;
                        }
                        var qr = t.closest('.evolution-escanear-qr-btn');
                        if (qr && window.achadinhosEvolutionQrOpen) {
                            e.preventDefault();
                            window.achadinhosEvolutionQrOpen(qr.getAttribute('data-evolution-id'), qr.getAttribute('data-evolution-nome'));
                            return;
                        }
                        var ref = t.closest('.evolution-status-refresh-btn');
                        if (ref && window.achadinhosEvolutionStatusRefreshClick) {
                            e.preventDefault();
                            window.achadinhosEvolutionStatusRefreshClick(ref.getAttribute('data-evolution-id'));
                            return;
                        }
                        var rst = t.closest('.evolution-restart-btn');
                        if (rst && window.achadinhosEvolutionRestartClick) {
                            e.preventDefault();
                            window.achadinhosEvolutionRestartClick(rst.getAttribute('data-evolution-id'));
                            return;
                        }
                        var lo = t.closest('.evolution-logout-btn');
                        if (lo && window.achadinhosEvolutionLogoutClick) {
                            e.preventDefault();
                            window.achadinhosEvolutionLogoutClick(lo.getAttribute('data-evolution-id'));
                            return;
                        }
                    });
                })();
                </script>
            </div>
            
            <?php elseif ($activeTab === 'telegram'): ?>
            <!-- Aba Telegram -->
            <form method="POST" action="?tab=telegram" class="space-y-8">
                <input type="hidden" name="config_tab" value="telegram">
                
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Telegram</h2>
                    <p class="text-sm text-gray-600 mb-6">Configure para que as automações publiquem as ofertas também em um grupo do Telegram, em paralelo ao envio para os grupos do WhatsApp.</p>
                    
                    <div class="space-y-6">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="telegram_ativo" value="1"
                                   <?php echo $telegramAtivo ? 'checked' : ''; ?>
                                   class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span class="text-sm font-medium text-gray-700">Ativar envio para Telegram</span>
                        </label>
                        
                        <div>
                            <label for="telegram_bot_token" class="block text-sm font-medium text-gray-700 mb-2">Bot Token</label>
                            <input type="text" id="telegram_bot_token" name="telegram_bot_token"
                                   value="<?php echo htmlspecialchars($telegramBotToken); ?>"
                                   placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="telegram_chat_id" class="block text-sm font-medium text-gray-700 mb-2">Chat ID do Grupo</label>
                            <input type="text" id="telegram_chat_id" name="telegram_chat_id"
                                   value="<?php echo htmlspecialchars($telegramChatId); ?>"
                                   placeholder="-1001234567890"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>

                        <div>
                            <label for="telegram_business_connection_id" class="block text-sm font-medium text-gray-700 mb-2">Business connection ID (Stories)</label>
                            <input type="text" id="telegram_business_connection_id" name="telegram_business_connection_id"
                                   value="<?php echo htmlspecialchars($telegramBusinessConnectionId); ?>"
                                   placeholder="Obtido ao conectar o bot ao Telegram Business"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                            <p class="mt-1 text-xs text-gray-500">Obrigatório para <strong>Stories</strong> por loja (método <code class="bg-gray-100 px-1 rounded">postStory</code> da API do Bot). A conta Business deve estar vinculada a este bot com permissão <code class="bg-gray-100 px-1 rounded">can_manage_stories</code>. Cada oferta usa a imagem do produto (redimensionada para 1080×1920 quando possível). <a href="https://core.telegram.org/bots/api#poststory" class="text-orange-600 hover:underline" target="_blank" rel="noopener">Documentação</a>.</p>
                        </div>

                        <div class="pt-4 border-t border-gray-100 space-y-4">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="dispatch_ativo_producao" value="1"
                                       class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500"
                                       <?php echo $dispatchAtivoProducao ? 'checked' : ''; ?>>
                                <span>
                                    <span class="block text-sm font-medium text-gray-800">Usar tabela Dispatches em produção (multi-conta / prioridades)</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">Se desmarcado, só em ambiente <code class="bg-gray-100 px-1 rounded">development</code> os dispatches são aplicados; em produção as automações usam os grupos configurados por loja. Marque após configurar <a href="dispatches.php" class="text-orange-600 hover:underline">Dispatches</a>.</span>
                                </span>
                            </label>
                            <div>
                            <label for="dispatch_admin_id" class="block text-sm font-medium text-gray-700 mb-2">Admin ID para dispatches (execução automática)</label>
                            <input type="number" id="dispatch_admin_id" name="dispatch_admin_id" min="1"
                                   value="<?php echo (int) $dispatchAdminId; ?>"
                                   class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">As automações usam os dispatches ativos deste usuário (<code class="bg-gray-100 px-1 rounded">admins.id</code>). Padrão: 1. Gerencie em <a href="dispatches.php" class="text-orange-600 hover:underline">Dispatches</a>.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-6">
                    <h3 class="font-bold text-amber-900 mb-3">📋 Como configurar o Telegram</h3>
                    <ol class="text-sm text-amber-900 space-y-3 list-decimal list-inside">
                        <li><strong>Criar o bot:</strong> No Telegram, busque por <code class="bg-amber-100 px-1 rounded">@BotFather</code>. Envie <code class="bg-amber-100 px-1 rounded">/newbot</code>, escolha um nome e username. O BotFather retornará um <strong>token</strong> (ex: 123456789:ABCdef...). Cole no campo Bot Token acima.</li>
                        <li><strong>Criar o grupo:</strong> Crie um grupo no Telegram ou use um existente. Adicione o bot que você criou como administrador (necessário para enviar mensagens).</li>
                        <li><strong>Obter o Chat ID:</strong> Envie uma mensagem no grupo. Depois, acesse no navegador: <code class="bg-amber-100 px-1 rounded break-all">https://api.telegram.org/bot<strong>SEU_TOKEN</strong>/getUpdates</code>. Procure por <code class="bg-amber-100 px-1 rounded">"chat":{"id":-1001234567890}</code>. O número <strong>id</strong> é o Chat ID (grupos geralmente começam com -100). Cole no campo Chat ID acima.</li>
                        <li><strong>Alternativa:</strong> Adicione <code class="bg-amber-100 px-1 rounded">@RawDataBot</code> no grupo e ele mostrará o ID do chat. Depois remova o bot.</li>
                        <li><strong>Salvar:</strong> Preencha os campos, marque "Ativar envio para Telegram" e clique em Salvar. As automações (Mercado Livre, Shopee, Magalu, etc.) passarão a enviar as ofertas também para esse grupo.</li>
                    </ol>
                    <p class="mt-4 text-xs text-amber-800">As mensagens serão enviadas no mesmo formato do WhatsApp (texto + imagem do produto + link). Integrado em todas as automações: Mercado Livre, Shopee, Magalu, Magalu Loja, AliExpress e Cupons ML.</p>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Salvar
                    </button>
                </div>
            </form>
            
            <?php elseif ($activeTab === 'crons'): ?>
            <!-- Aba Crons -->
            <?php
            $cronTabLoadError = '';
            $cron_intervalo = getConfig('cron_intervalo_minutos', '5');
            $cron_hora_inicio = getConfig('cron_hora_inicio', '8');
            $cron_hora_fim = getConfig('cron_hora_fim', '22');
            $produtos_dias_expiracao = getConfig('produtos_dias_expiracao', '30');
            $cron_job_org_api_key = getConfig('cron_job_org_api_key', '');
            $cron_global_job_id = trim((string) getConfig('cron_global_job_id', ''));
            $cron_api_integration_ativa = false;
            $cron_sync_last_error = '';
            $cron_public_base_url_input = '';
            $cron_public_base_url = '';
            $cron_base_preview = '';
            $cron_url = '';
            $cron_url_sem_query = '';
            $cron_url_para_job = '';
            $cron_token_global = '';
            $cron_token_masked = '—';
            $cron_auth_fallback_n = 0;
            $cron_prev_global = ['expr' => '*/5 8-22 * * *', 'hint' => '', 'apiNote' => null];
            $cron_expr = $cron_prev_global['expr'];
            $cron_expr_five = '*/5 8-22 * * *';
            $cron_hint_global = '';
            $cron_api_note_global = null;
            $cron_line_global = '';
            $cronMonitorGlobalUi = [
                'nivel' => 'off',
                'texto' => '—',
                'status_global_linha' => '—',
                'job_id' => '',
                'ultima_execucao_human' => '—',
                'ultima_execucao_ok_human' => '—',
                'url_public_dns_risk' => false,
            ];
            $cronMonitorMenuAtivo = getConfig('cron_monitor_menu_ativo', '0') === '1';
            $cronMonitorForcarRefresh = isset($_GET['force_refresh']) && (string) $_GET['force_refresh'] === '1';
            $cronMonitorIvMax = 720;
            $cronMonitorIvPadrao = 5;
            $cronMonitorH1Padrao = 8;
            $cronMonitorH2Padrao = 22;
            $cron_org_pendente_job_id = false;
            try {
                require_once __DIR__ . '/../core/cron/CronJobService.php';
                require_once __DIR__ . '/../core/db/SchemaHelper.php';

                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $siteUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

                $cron_intervalo = getConfig('cron_intervalo_minutos', '5');
                $cron_hora_inicio = getConfig('cron_hora_inicio', '8');
                $cron_hora_fim = getConfig('cron_hora_fim', '22');
                $produtos_dias_expiracao = getConfig('produtos_dias_expiracao', '30');
                $cron_job_org_api_key = getConfig('cron_job_org_api_key', '');
                $cron_global_job_id = trim((string) getConfig('cron_global_job_id', ''));
                // «Ativar API» = chave guardada; jobs na cron-job.org são por grupo, não exige ID global.
                $cron_api_integration_ativa = trim((string) $cron_job_org_api_key) !== '';
                $cron_org_pendente_job_id = false;
                $cron_sync_last_error = trim((string) getConfig('cron_global_sync_last_error', ''));
                $cron_public_base_url = rtrim(trim((string) getConfig('cron_public_base_url', '')), '/');
                $cron_public_from_req = cronPublicBaseUrlFromRequest();
                $cron_public_base_url_input = $cron_public_base_url !== '' ? $cron_public_base_url : $cron_public_from_req;
                $cron_base_preview = cronPublicBaseUrl();
                if ($cron_base_preview === '') {
                    $cron_base_preview = $cron_public_from_req !== '' ? $cron_public_from_req : rtrim($siteUrl, '/');
                }
                $cron_token_global = trim((string) getConfig('cron_token', ''));
                $cron_token_masked = function_exists('achadinhosCronMascarTokenAdmin')
                    ? achadinhosCronMascarTokenAdmin($cron_token_global)
                    : ($cron_token_global !== '' ? '•••• (definido)' : '— (ausente)');
                $cron_auth_fallback_n = function_exists('achadinhosCronTokensAutomacoesCronOrdenados')
                    ? count(achadinhosCronTokensAutomacoesCronOrdenados())
                    : 0;
                $cron_url_sem_query = cronJobUrlRodarTudo();
                if ($cron_url_sem_query === '' && $cron_base_preview !== '') {
                    $cron_url_sem_query = $cron_base_preview . '/cron/rodar-tudo.php';
                }
                $cron_url_para_job = cronJobUrlRodarTudoComQueryToken();
                if ($cron_url_para_job === '') {
                    $cron_url_para_job = $cron_url_sem_query;
                    if ($cron_token_global !== '' && $cron_url_para_job !== '' && strpos($cron_url_para_job, 'token=') === false) {
                        $cron_url_para_job .= '?token=' . rawurlencode($cron_token_global);
                    }
                }
                $cron_hdr_global = [];
                $cron_prev_global = cronPainelPreviewExemplo((int) $cron_intervalo, (int) $cron_hora_inicio, (int) $cron_hora_fim);
                $cron_expr = (string) ($cron_prev_global['expr'] ?? '');
                $cron_expr_norm = preg_replace('/\s+/', ' ', trim($cron_expr));
                $cron_expr_five = $cron_expr_norm;
                if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)/', $cron_expr_norm, $m)) {
                    $cron_expr_five = $m[1];
                }
                $cron_hint_global = (string) ($cron_prev_global['hint'] ?? '');
                $cron_api_note_global = $cron_prev_global['apiNote'] ?? null;
                $cron_line_global = cronPainelLinhaCurl($cron_expr, $cron_url_para_job, $cron_hdr_global);

                garantirTabelaCronExecucoes();
                $cronMonitorGlobalUi = cronMonitorPainelStatus('global', null);
                $cronMonitorIvMax = (int) CronPolicy::intervalMaxMinutes();
                $cronMonitorIvPadrao = CronPolicy::normalizeInterval((int) $cron_intervalo);
                $cronMonitorH1Padrao = max(0, min(23, (int) $cron_hora_inicio));
                $cronMonitorH2Padrao = max(0, min(23, (int) $cron_hora_fim));
            } catch (Throwable $e) {
                error_log('configuracoes.php tab=crons: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $cronTabLoadError = 'Ocorreu um erro ao preparar a aba Crons. Verifique os ficheiros core/cron/CronJobService.php, CronPolicy.php e core/db/SchemaHelper.php, e o log do PHP.';
            }
            ?>
            <?php if ($cronTabLoadError !== ''): ?>
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                <?php echo htmlspecialchars($cronTabLoadError); ?>
            </div>
            <?php endif; ?>
            <form method="post" action="javascript:void(0)" class="space-y-6" id="cronGlobalForm">
                <input type="hidden" id="cron_intervalo_minutos" name="cron_intervalo_minutos" value="<?php echo htmlspecialchars((string) $cron_intervalo); ?>">
                <input type="hidden" id="cron_hora_inicio" name="cron_hora_inicio" value="<?php echo htmlspecialchars((string) $cron_hora_inicio); ?>">
                <input type="hidden" id="cron_hora_fim" name="cron_hora_fim" value="<?php echo htmlspecialchars((string) $cron_hora_fim); ?>">

                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Cron-job.org</h2>
                    <p class="text-sm text-gray-600 mb-3">Guarde a <strong>chave API</strong> e a <strong>URL base pública</strong> do site aqui. Para cada grupo WhatsApp, o sistema cria ou atualiza na cron-job.org um job que chama <code class="text-xs bg-gray-100 px-1 rounded">cron/rodar-grupo.php?grupo=…</code> (página <a href="grupos.php?tab=lista" class="text-orange-600 font-medium underline hover:text-orange-700">Grupos</a>).</p>

                    <div class="rounded-lg border border-amber-200 bg-amber-50/90 p-4 mb-4 text-sm text-amber-950">
                        <p class="font-semibold text-amber-900 mb-1">Token HTTP principal (<code class="text-xs bg-white/80 px-1 rounded">cron_token</code>)</p>
                        <p class="text-amber-900/95">Estado: <?php echo $cron_token_global !== ''
                            ? 'definido — pré-visualização segura: <strong>' . htmlspecialchars($cron_token_masked) . '</strong> (valor completo nas configurações da loja / autosave, campo cron)'
                            : '<strong class="text-amber-800">ausente</strong> — ao sincronizar um grupo com a API ativa, o sistema gera e grava um token forte automaticamente.'; ?></p>
                        <?php if ($cron_auth_fallback_n > 0): ?>
                        <p class="text-xs mt-2 text-amber-900/90">Existem <strong><?php echo (int) $cron_auth_fallback_n; ?></strong> token(ns) distinto(s) nas crons por loja (<code class="text-[11px] bg-white/70 px-0.5 rounded">automacoes_cron</code>): continuam a permitir autenticação em <code class="text-[11px]">rodar-tudo.php</code> / <code class="text-[11px]">rodar-grupo.php</code>, mas a <strong>URL</strong> enviada à cron-job.org usa apenas o <strong>cron_token</strong> oficial. Re-sincronize os grupos após definir <code class="text-[11px]">cron_token</code> para alinhar o agendador.</p>
                        <?php endif; ?>
                        <p class="text-xs mt-2 text-gray-700 border-t border-amber-200/60 pt-2">Se o agendador externo receber <strong>403</strong> e a resposta <em>não</em> incluir o cabeçalho <code class="text-[11px] bg-white px-0.5 rounded">X-Achadinhos-Cron-Error</code>, a negação provavelmente vem do proxy, Cloudflare ou WAF antes do PHP — crie exceção para <code class="text-[11px]">/cron/*</code> ou desative bloqueio de bot para esse caminho.</p>
                    </div>

                    <div class="rounded-lg border border-sky-200 bg-sky-50/80 p-4 mb-6 text-sm text-sky-950">
                        <h3 class="font-semibold text-sky-900 mb-2">Como obter a chave API no cron-job.org</h3>
                        <ol class="list-decimal list-inside space-y-1.5 text-sky-900/90">
                            <li>Crie uma conta ou faça login em <a href="https://cron-job.org/" target="_blank" rel="noopener noreferrer" class="font-medium text-orange-600 underline hover:text-orange-700">cron-job.org</a>.</li>
                            <li>Abra o <a href="https://console.cron-job.org/" target="_blank" rel="noopener noreferrer" class="font-medium text-orange-600 underline hover:text-orange-700">console</a> (painel de cron jobs).</li>
                            <li>Clique no seu <strong>utilizador</strong> (canto superior direito) → <strong>Settings</strong>.</li>
                            <li>Na secção <strong>API</strong>, crie ou copie a <strong>API key</strong> e cole no campo abaixo. A documentação oficial da API está em <a href="https://cron-job.org/en/api/" target="_blank" rel="noopener noreferrer" class="font-medium text-orange-600 underline hover:text-orange-700">cron-job.org/en/api</a>.</li>
                        </ol>
                        <p class="mt-3 text-xs text-sky-800/90">Depois de colar a chave, use <strong>Ativar API</strong>. Os jobs no painel cron-job.org surgem ao <strong>salvar cada grupo</strong>.</p>
                    </div>

                    <div class="mb-4">
                        <label for="cron_public_base_url" class="block text-sm font-medium text-gray-700 mb-2">URL base pública do site</label>
                        <input type="text" id="cron_public_base_url" name="cron_public_base_url" autocomplete="off"
                               value="<?php echo htmlspecialchars($cron_public_base_url_input); ?>"
                               placeholder="https://…"
                               class="w-full font-mono text-sm border border-gray-300 rounded-md px-3 py-2 bg-white text-gray-900 focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Raiz onde existem <code class="text-[11px]">cron/</code> e <code class="text-[11px]">admin/</code> (HTTPS recomendado).</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label for="cron_job_org_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave API</label>
                            <div class="relative flex items-stretch">
                            <input type="password" id="cron_job_org_api_key" name="cron_job_org_api_key" autocomplete="off"
                                   value="<?php echo htmlspecialchars($cron_job_org_api_key); ?>"
                                       placeholder="API Key"
                                       class="w-full pl-3 pr-11 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                                <button type="button" id="btn_toggle_cron_api_key" class="absolute right-0 top-0 bottom-0 px-3 flex items-center justify-center text-gray-500 hover:text-gray-800 rounded-r-md focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500"
                                        aria-label="Mostrar ou ocultar chave" title="Mostrar / ocultar">
                                    <svg id="cron_api_key_icon_show" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    <svg id="cron_api_key_icon_hide" class="hidden h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                                </button>
                        </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" id="btn_criar_api_global" title="Ativar ou desativar a API cron-job.org"
                                class="<?php echo $cron_api_integration_ativa ? 'bg-emerald-600 hover:bg-emerald-700 ring-2 ring-emerald-300/50' : 'bg-slate-500 hover:bg-slate-600 ring-2 ring-slate-300/40'; ?> text-white px-4 py-2 rounded-lg transition-colors font-semibold">
                            <?php echo $cron_api_integration_ativa ? 'Desativar API' : 'Ativar API'; ?>
                        </button>
                    </div>
                    <?php if (!empty($cron_sync_last_error)): ?>
                    <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950" role="status">
                        <span class="block text-xs font-mono text-amber-900/90"><?php echo htmlspecialchars($cron_sync_last_error); ?></span>
                    </div>
                    <?php endif; ?>

                    <p class="mt-4 text-xs text-gray-600 border-t border-gray-100 pt-4"><strong>Horários de envio</strong> e o agendamento na cron-job.org ficam por grupo em <a href="grupos.php?tab=lista" class="text-orange-600 font-medium underline hover:text-orange-700">Grupos</a>. O botão <strong>Criar hora</strong> abaixo apenas executa uma rodada manual do fluxo global legado (<code class="text-[11px]">rodar-tudo.php</code>), opcional.</p>

                    <div class="bg-gray-50 border border-gray-200 p-4 rounded-lg mt-4" id="cron_global_cronjob_sync" data-cron-token="<?php echo htmlspecialchars($cron_token_global, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" id="cron_global_job_toggle"
                                class="flex w-full items-center justify-between gap-2 text-left text-sm font-semibold text-gray-800 rounded-md py-1 -my-1 px-1 -mx-1 hover:bg-gray-100/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500"
                                aria-expanded="false" aria-controls="cron_global_job_panel"
                                aria-label="Mostrar ou ocultar detalhes do job na cron-job.org">
                            <span>Job na cron-job.org (URL, expressão e exemplo curl)</span>
                            <svg id="cron_global_job_chevron" class="h-5 w-5 shrink-0 text-gray-500 transition-transform duration-200 -rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="cron_global_job_panel" class="hidden mt-4 space-y-3">
                            <p class="text-xs text-gray-600">Valores abaixo vêm da configuração interna (intervalo e janela usados na sincronização com a API). Para alterar, edite o job no console da cron-job.org ou use a lista «Crons da sua conta» com edição avançada.</p>
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:gap-6">
                                <div class="min-w-0 flex-1">
                                    <label for="cron_global_preview_url" class="text-xs font-medium text-gray-600 mb-1 block">URL do job (como na cron-job.org)</label>
                                    <input type="text" readonly id="cron_global_preview_url" name="cron_global_url_display"
                                           value="<?php echo htmlspecialchars($cron_url_para_job !== '' ? $cron_url_para_job : $cron_url_sem_query); ?>"
                                           class="w-full font-mono text-sm border border-gray-300 rounded-md px-3 py-2 bg-gray-100 text-gray-900"
                                           autocomplete="off">
                                    <p class="text-[11px] text-gray-500 mt-1">Com token global definido, inclui <code class="rounded bg-white px-0.5">?token=</code> na URL (o agendador não depende de cabeçalhos HTTP). Após mudar o token, use «Criar hora» / sincronizar de novo.</p>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <label for="cron_global_expr_five" class="text-xs font-medium text-gray-600 mb-1 block">Expressão Cron (5 campos)</label>
                                    <input type="text" id="cron_global_expr_five" name="cron_global_expr_five"
                                           value="<?php echo htmlspecialchars($cron_expr_five); ?>"
                                           class="w-full font-mono text-sm border border-gray-300 rounded-md px-3 py-2 bg-white text-gray-900"
                                           placeholder="*/30 8-22 * * *" autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                            <p id="cron_global_preview_hint" class="text-xs text-gray-500 mb-1"><?php echo htmlspecialchars($cron_hint_global); ?></p>
                            <p id="cron_global_preview_note" class="text-xs text-amber-800 <?php echo $cron_api_note_global !== null && $cron_api_note_global !== '' ? '' : 'hidden'; ?>"><?php echo $cron_api_note_global !== null && $cron_api_note_global !== '' ? htmlspecialchars($cron_api_note_global) : ''; ?></p>
                            <p class="text-xs text-gray-500 mb-1">Crontab + curl</p>
                            <pre id="cron_global_preview_line" class="text-xs font-mono text-gray-800 break-all whitespace-pre-wrap border border-gray-200 rounded-md p-3 bg-white max-h-40 overflow-y-auto"><?php echo htmlspecialchars($cron_line_global); ?></pre>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Catálogo</h2>
                    <p class="text-sm text-gray-600 mb-4">Manutenção automática dos produtos publicados no site.</p>
                    <div class="max-w-xs">
                        <label for="produtos_dias_expiracao" class="block text-sm font-medium text-gray-700 mb-2">Dias para remover produtos antigos</label>
                        <input type="number" id="produtos_dias_expiracao" name="produtos_dias_expiracao" min="1" max="365"
                               value="<?php echo htmlspecialchars($produtos_dias_expiracao); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Remove produtos criados há mais do que este número de dias (conforme lógica do cron).</p>
                    </div>
                </div>
            </form>

                <div class="flex flex-wrap items-center justify-between gap-4 w-full">
                    <form method="post" action="configuracoes.php" class="inline-flex flex-wrap items-center gap-3">
                        <input type="hidden" name="config_tab" value="crons">
                        <input type="hidden" name="cron_monitor_menu_submit" value="1">
                        <input type="hidden" name="cron_monitor_menu_acao" value="<?php echo $cronMonitorMenuAtivo ? '0' : '1'; ?>">
                        <button type="submit"
                                class="rounded-lg border-2 py-2 px-6 text-sm font-bold transition-colors <?php echo $cronMonitorMenuAtivo ? 'border-orange-500 bg-orange-500 text-white hover:bg-orange-600' : 'border-orange-500 bg-white text-orange-600 hover:bg-orange-500 hover:text-white'; ?>">
                            <?php echo $cronMonitorMenuAtivo ? 'Ocultar Crons' : 'Ver Crons'; ?>
                        </button>
                    </form>
                    <div class="flex flex-col items-end gap-2 max-w-xl">
                    <p class="text-xs text-gray-600 text-right sm:text-left self-end sm:self-start">«Criar hora» executa o <strong>cron global</strong> no servidor (<code class="text-[11px] bg-gray-100 px-1 rounded">rodar-tudo.php</code>). <strong>Não</strong> cria nem atualiza o job global na API da cron-job.org neste botão — os agendamentos por URL são geridos por <strong>regra</strong> na página Grupos (um job por linha).</p>
                    <div class="flex flex-wrap items-center gap-2">
                    <span id="cronGlobalSyncBusy" class="hidden text-sm text-gray-600" aria-live="polite">A sincronizar…</span>
                    <button type="button" id="btnCronGlobalExecutar" onclick="executarCronGlobal()"
                            class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded-lg transition-colors flex items-center gap-2 shrink-0">
                        <span id="btnCronGlobalExecutarTexto">Criar hora</span>
                        <span id="btnCronGlobalExecutarSpinner" class="hidden">
                            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </button>
                    </div>
                    </div>
                </div>
                <?php if ($cronMonitorMenuAtivo):
                    $cronEmbNivel = (string) ($cronMonitorGlobalUi['nivel'] ?? 'off');
                    $cronEmbBannerClass = 'border-slate-200 bg-slate-50 text-slate-900';
                    if ($cronEmbNivel === 'ok') {
                        $cronEmbBannerClass = 'border-emerald-200 bg-emerald-50 text-emerald-950';
                    } elseif ($cronEmbNivel === 'warn' || $cronEmbNivel === 'unknown') {
                        $cronEmbBannerClass = 'border-amber-200 bg-amber-50 text-amber-950';
                    } elseif ($cronEmbNivel !== 'off') {
                        $cronEmbBannerClass = 'border-red-200 bg-red-50 text-red-950';
                    }
                ?>
                <div class="mt-6 space-y-6">
                    <div class="rounded-xl border-2 p-5 text-sm shadow-sm <?php echo $cronEmbBannerClass; ?>">
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-600 mb-2">Status global</p>
                        <p class="text-base font-semibold leading-snug mb-3"><?php echo htmlspecialchars((string) ($cronMonitorGlobalUi['status_global_linha'] ?? $cronMonitorGlobalUi['texto'] ?? '—')); ?></p>
                        <ul class="space-y-1 text-xs sm:text-sm opacity-90">
                            <li><span class="font-medium">Job sincronizado:</span>
                                <?php echo ($cronMonitorGlobalUi['job_id'] ?? '') !== '' ? '<code class="rounded bg-white/70 px-1 border border-black/5">' . htmlspecialchars((string) $cronMonitorGlobalUi['job_id']) . '</code>' : '<span class="italic">—</span>'; ?>
                            </li>
                            <li><span class="font-medium">Última execução (qualquer):</span> <?php echo htmlspecialchars((string) ($cronMonitorGlobalUi['ultima_execucao_human'] ?? '—')); ?></li>
                            <li><span class="font-medium">Último sucesso:</span> <?php echo htmlspecialchars((string) ($cronMonitorGlobalUi['ultima_execucao_ok_human'] ?? '—')); ?></li>
                            <?php if (!empty($cronMonitorGlobalUi['url_public_dns_risk'])): ?>
                            <li class="text-amber-900 font-medium">Host da URL do job parece não público: a API pode estar OK mesmo com falhas de execução (DNS) na cron-job.org — corrija a URL base pública acima.</li>
                            <?php endif; ?>
                        </ul>
                        <?php if ($cron_sync_last_error !== ''): ?>
                        <p class="mt-3 text-xs font-medium text-red-800 border-t border-red-200/60 pt-2">Erro na última sincronização da API: <?php echo htmlspecialchars($cron_sync_last_error); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="mb-3">
                            <h2 class="text-lg font-semibold text-gray-800">Crons da sua conta (cron-job.org)</h2>
                        </div>
                        <?php if ($cronMonitorForcarRefresh): ?>
                            <p class="mb-3 text-xs text-slate-500">Atualização manual executada sem cache.</p>
                        <?php endif; ?>
                        <?php if ($cron_job_org_api_key === ''): ?>
                            <p class="text-sm text-amber-700">Chave da API não configurada. Adicione acima para carregar os jobs da conta.</p>
                        <?php else: ?>
                            <p id="cron-org-jobs-loading" class="text-sm text-gray-600 py-2">Carregando jobs da cron-job.org…</p>
                            <p id="cron-org-jobs-error" class="hidden text-sm text-red-700 py-2"></p>
                            <p id="cron-org-jobs-empty" class="hidden text-sm text-gray-600 py-2">Nenhum job retornado pela API.</p>
                            <div id="cron-org-jobs-table-wrap" class="hidden overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase text-gray-600">
                                        <tr>
                                            <th class="px-3 py-2">Job ID</th>
                                            <th class="px-3 py-2">Título</th>
                                            <th class="px-3 py-2">URL</th>
                                            <th class="px-3 py-2">Ativar / pausar</th>
                                            <th class="px-3 py-2 text-right align-top min-w-[5.5rem]">
                                                <div class="flex flex-col items-end gap-1">
                                                    <span>Ações</span>
                                                    <button type="button" id="cron-org-delete-all-btn" class="hidden font-normal normal-case tracking-normal text-[10px] font-medium text-red-600 hover:text-red-700 underline decoration-dotted" title="Remover todos os jobs listados na cron-job.org">Excluir todos</button>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="cron-org-jobs-tbody" class="divide-y divide-gray-100"></tbody>
                                </table>
                            </div>
                            <script>
                            (function () {
                                var refreshInicial = <?php echo $cronMonitorForcarRefresh ? 'true' : 'false'; ?>;
                                function fillCronOrgJobs(jobs) {
                                    var loading = document.getElementById('cron-org-jobs-loading');
                                    var errEl = document.getElementById('cron-org-jobs-error');
                                    var emptyEl = document.getElementById('cron-org-jobs-empty');
                                    var wrap = document.getElementById('cron-org-jobs-table-wrap');
                                    var tbody = document.getElementById('cron-org-jobs-tbody');
                                    var delAllBtnHead = document.getElementById('cron-org-delete-all-btn');
                                    if (delAllBtnHead) {
                                        delAllBtnHead.classList.add('hidden');
                                        delAllBtnHead.disabled = false;
                                        delAllBtnHead.onclick = null;
                                    }
                                    if (!tbody || !wrap || !emptyEl || !errEl) return;
                                    if (loading) loading.classList.add('hidden');
                                    errEl.classList.add('hidden');
                                    emptyEl.classList.add('hidden');
                                    wrap.classList.add('hidden');
                                    tbody.textContent = '';
                                    if (!jobs || jobs.length === 0) {
                                        emptyEl.classList.remove('hidden');
                                        return;
                                    }
                                    wrap.classList.remove('hidden');
                                    var idsExcluirTodos = [];
                                    jobs.forEach(function (job) {
                                        if (!job || typeof job !== 'object') return;
                                        var jid = String(job.jobId != null ? job.jobId : (job.id != null ? job.id : ''));
                                        if (jid !== '') {
                                            idsExcluirTodos.push(jid);
                                        }
                                        var nested = job.job && typeof job.job === 'object' ? job.job : null;
                                        var ttl = String(job.title != null ? job.title : (nested && nested.title != null ? nested.title : '—'));
                                        var url = String(job.url != null ? job.url : (nested && nested.url != null ? nested.url : ''));
                                        var enabled = job.enabled != null ? job.enabled : (nested && nested.enabled != null ? nested.enabled : true);
                                        enabled = !!enabled;
                                        var tr = document.createElement('tr');
                                        tr.className = 'hover:bg-gray-50/80';
                                        var tdId = document.createElement('td');
                                        tdId.className = 'px-3 py-2 tabular-nums';
                                        tdId.textContent = jid !== '' ? jid : '—';
                                        var tdTtl = document.createElement('td');
                                        tdTtl.className = 'px-3 py-2';
                                        tdTtl.textContent = ttl;
                                        var tdUrl = document.createElement('td');
                                        tdUrl.className = 'px-3 py-2 max-w-[28rem] truncate';
                                        if (url !== '') {
                                            var a = document.createElement('a');
                                            a.href = url;
                                            a.target = '_blank';
                                            a.rel = 'noopener';
                                            a.className = 'text-orange-700 hover:underline';
                                            a.textContent = url;
                                            tdUrl.appendChild(a);
                                        } else {
                                            tdUrl.textContent = '—';
                                        }
                                        var tdEn = document.createElement('td');
                                        tdEn.className = 'px-3 py-2';
                                        if (jid !== '') {
                                            var toggleBtn = document.createElement('button');
                                            toggleBtn.type = 'button';
                                            toggleBtn.setAttribute('data-enabled', enabled ? '1' : '0');
                                            function estiloBotaoCronOrgAtivo(el, en) {
                                                el.setAttribute('data-enabled', en ? '1' : '0');
                                                el.setAttribute('aria-pressed', en ? 'true' : 'false');
                                                el.setAttribute('aria-label', en ? 'Job ativo na cron-job.org; clicar para desativar' : 'Job inativo na cron-job.org; clicar para ativar');
                                                if (en) {
                                                    el.textContent = 'Desativar na cron-job';
                                                    el.title = 'Pausa o job na cron-job.org (mantém URL e agenda)';
                                                    el.className = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-1 disabled:opacity-60';
                                                } else {
                                                    el.textContent = 'Ativar na cron-job';
                                                    el.title = 'Liga o job na cron-job.org para executar na agenda';
                                                    el.className = 'inline-flex items-center justify-center rounded-lg border border-emerald-600 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 disabled:opacity-60';
                                                }
                                            }
                                            estiloBotaoCronOrgAtivo(toggleBtn, enabled);
                                            toggleBtn.addEventListener('click', function () {
                                                var cur = this.getAttribute('data-enabled') === '1';
                                                if (typeof window.cronJobOrgAlternarAtivo !== 'function') {
                                                    window.alert('Recarregue a página e tente novamente.');
                                                    return;
                                                }
                                                window.cronJobOrgAlternarAtivo(jid, !cur, this, estiloBotaoCronOrgAtivo);
                                            });
                                            tdEn.appendChild(toggleBtn);
                                        } else {
                                            tdEn.textContent = '—';
                                        }
                                        var tdAc = document.createElement('td');
                                        tdAc.className = 'px-3 py-2 text-right whitespace-nowrap';
                                        if (jid !== '') {
                                            var spanBtns = document.createElement('span');
                                            spanBtns.className = 'inline-flex flex-nowrap items-center justify-end gap-0.5';
                                            var iconLapis = '<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>';
                                            var iconLixeira = '<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
                                            var b1 = document.createElement('button');
                                            b1.type = 'button';
                                            b1.className = 'inline-flex items-center justify-center rounded-md p-1.5 text-orange-600 hover:bg-orange-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-1';
                                            b1.setAttribute('aria-label', 'Editar');
                                            b1.title = 'Editar';
                                            b1.innerHTML = iconLapis;
                                            b1.addEventListener('click', function () { abrirEdicaoCronJob(jid); });
                                            var b2 = document.createElement('button');
                                            b2.type = 'button';
                                            b2.className = 'inline-flex items-center justify-center rounded-md p-1.5 text-red-600 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-1';
                                            b2.setAttribute('aria-label', 'Excluir na cron-job.org');
                                            b2.title = 'Excluir na cron-job.org';
                                            b2.innerHTML = iconLixeira;
                                            b2.addEventListener('click', function () { excluirCronJobOrg(jid, ttl); });
                                            spanBtns.appendChild(b1);
                                            spanBtns.appendChild(b2);
                                            tdAc.appendChild(spanBtns);
                                        } else {
                                            tdAc.textContent = '—';
                                        }
                                        tr.appendChild(tdId);
                                        tr.appendChild(tdTtl);
                                        tr.appendChild(tdUrl);
                                        tr.appendChild(tdEn);
                                        tr.appendChild(tdAc);
                                        tbody.appendChild(tr);
                                    });
                                    if (delAllBtnHead && idsExcluirTodos.length > 0) {
                                        delAllBtnHead.classList.remove('hidden');
                                        delAllBtnHead.onclick = function () {
                                            if (typeof window.excluirTodosCronOrgJobs === 'function') {
                                                window.excluirTodosCronOrgJobs(idsExcluirTodos.slice());
                                            } else {
                                                window.alert('Recarregue a página e tente novamente.');
                                            }
                                        };
                                    }
                                }
                                function carregarListaCronOrgJobs(forcarRefresh, mostrarLoading) {
                                    var loading = document.getElementById('cron-org-jobs-loading');
                                    var errEl = document.getElementById('cron-org-jobs-error');
                                    var dabLoad = document.getElementById('cron-org-delete-all-btn');
                                    if (mostrarLoading && dabLoad) {
                                        dabLoad.classList.add('hidden');
                                    }
                                    if (mostrarLoading && loading) {
                                        loading.classList.remove('hidden');
                                    }
                                    if (errEl) {
                                        errEl.classList.add('hidden');
                                        errEl.textContent = '';
                                    }
                                    var q = forcarRefresh ? '?refresh=1' : '';
                                    return fetch('api/cron-jobs-list.php' + q, { credentials: 'same-origin' })
                                        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                                        .then(function (x) {
                                            if (!x.ok || !x.j) {
                                                if (loading) loading.classList.add('hidden');
                                                if (errEl) {
                                                    errEl.textContent = 'Resposta inválida do servidor.';
                                                    errEl.classList.remove('hidden');
                                                }
                                                return;
                                            }
                                            if (!x.j.success) {
                                                if (loading) loading.classList.add('hidden');
                                                if (errEl) {
                                                    errEl.textContent = x.j.message || 'Falha ao consultar cron-job.org';
                                                    errEl.classList.remove('hidden');
                                                }
                                                return;
                                            }
                                            fillCronOrgJobs(x.j.jobs || []);
                                        })
                                        .catch(function () {
                                            if (loading) loading.classList.add('hidden');
                                            if (errEl) {
                                                errEl.textContent = 'Erro de rede ao carregar jobs.';
                                                errEl.classList.remove('hidden');
                                            }
                                        });
                                }
                                window.achadinhosRecarregarCronOrgJobs = function (forcarRefresh) {
                                    return carregarListaCronOrgJobs(!!forcarRefresh, true);
                                };
                                carregarListaCronOrgJobs(refreshInicial, false);
                            })();
                            </script>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 pt-4">
                        <a href="configuracoes.php?tab=crons&amp;force_refresh=1&amp;t=<?php echo urlencode((string) microtime(true)); ?>"
                           class="inline-flex items-center justify-center rounded-lg bg-orange-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2">
                            Sincronizar agora
                        </a>
                    </div>
                </div>
                <div id="cron-edit-modal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/50 p-4">
                    <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                        <div class="flex items-center justify-between border-b px-4 py-3">
                            <h3 class="text-base font-semibold text-gray-800">Editar cron (cron-job.org)</h3>
                            <button type="button" onclick="fecharEdicaoCronJob()" class="rounded-md px-2 py-1 text-sm text-gray-600 hover:bg-gray-100">Fechar</button>
                        </div>
                        <div class="max-h-[75vh] overflow-auto p-4 space-y-3">
                            <p id="cron-edit-status" class="hidden text-sm"></p>
                            <input type="hidden" id="cron-edit-job-id" value="">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Job ID</label>
                                <input type="text" id="cron-edit-job-id-ro" readonly class="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Título</label>
                                <input type="text" id="cron-edit-title" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">URL</label>
                                <input type="text" id="cron-edit-url" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono text-xs">
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="cron-edit-enabled" class="rounded border-gray-300">
                                <label for="cron-edit-enabled" class="text-sm text-gray-800">Job ativo (executar conforme agenda)</label>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-2">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" id="cron-edit-update-schedule" class="rounded border-gray-300">
                                    <label for="cron-edit-update-schedule" class="text-sm font-medium text-gray-800">Atualizar agenda (intervalo + janela, mesmo padrão do painel)</label>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Intervalo (min)</label>
                                        <input type="number" id="cron-edit-iv" min="1" max="<?php echo (int) $cronMonitorIvMax; ?>" value="<?php echo (int) $cronMonitorIvPadrao; ?>" class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Hora início</label>
                                        <input type="number" id="cron-edit-h1" min="0" max="23" value="<?php echo (int) $cronMonitorH1Padrao; ?>" class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Hora fim</label>
                                        <input type="number" id="cron-edit-h2" min="0" max="23" value="<?php echo (int) $cronMonitorH2Padrao; ?>" class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500">Alterações são enviadas para a API da cron-job.org (PATCH). Campos não marcados como agenda permanecem como estão até você salvar com “Atualizar agenda”.</p>
                            <a id="cron-edit-console-link" href="#" target="_blank" rel="noopener" class="hidden text-xs text-orange-600 hover:underline">Abrir job no console cron-job.org</a>
                            <div class="flex flex-wrap justify-end gap-2 pt-2">
                                <button type="button" onclick="fecharEdicaoCronJob()" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                                <button type="button" id="cron-edit-delete" onclick="excluirCronJobOrgDoModal()" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-800 hover:bg-red-100">Excluir na cron-job.org</button>
                                <button type="button" id="cron-edit-save" onclick="salvarEdicaoCronJob()" class="rounded-md bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">Salvar e sincronizar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                window.CRON_MONITOR_IV_MAX = <?php echo (int) $cronMonitorIvMax; ?>;
                function cronManageToken() {
                    var b = document.body;
                    return b ? (b.getAttribute('data-admin-autosave-token') || '') : '';
                }
                /**
                 * Lista “Crons da sua conta”: liga/desliga o job na cron-job.org (PATCH enabled).
                 */
                window.cronJobOrgAlternarAtivo = function (jobId, nextEnabled, btnEl, aplicarEstilo) {
                    var tok = cronManageToken();
                    if (!jobId || !tok) {
                        window.alert('Não foi possível obter o token de sessão. Recarregue a página.');
                        return;
                    }
                    btnEl.disabled = true;
                    btnEl.setAttribute('aria-busy', 'true');
                    fetch('api/cron-job-manage.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': tok },
                        body: JSON.stringify({ action: 'patch', token: tok, job_id: jobId, enabled: !!nextEnabled })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        btnEl.disabled = false;
                        btnEl.removeAttribute('aria-busy');
                        if (!res.ok || !res.j || !res.j.success) {
                            var err = (res.j && res.j.error) ? res.j.error : 'Falha ao atualizar o job na cron-job.org.';
                            window.alert(err);
                            return;
                        }
                        if (typeof aplicarEstilo === 'function') aplicarEstilo(btnEl, !!nextEnabled);
                        if (typeof window.achadinhosRecarregarCronOrgJobs === 'function') {
                            window.achadinhosRecarregarCronOrgJobs(true);
                        }
                    }).catch(function () {
                        btnEl.disabled = false;
                        btnEl.removeAttribute('aria-busy');
                        window.alert('Erro de rede ao contactar a cron-job.org.');
                    });
                };
                function abrirEdicaoCronJob(jobId) {
                    var modal = document.getElementById('cron-edit-modal');
                    var st = document.getElementById('cron-edit-status');
                    if (!modal) return;
                    st.classList.add('hidden');
                    st.textContent = '';
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.getElementById('cron-edit-job-id').value = jobId;
                    document.getElementById('cron-edit-job-id-ro').value = jobId;
                    var link = document.getElementById('cron-edit-console-link');
                    if (link) {
                        link.href = 'https://console.cron-job.org/jobs/' + encodeURIComponent(jobId);
                        link.classList.remove('hidden');
                    }
                    var saveBtn = document.getElementById('cron-edit-save');
                    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Carregando...'; }
                    fetch('api/cron-job-manage.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': cronManageToken() },
                        body: JSON.stringify({ action: 'get', token: cronManageToken(), job_id: jobId })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Salvar e sincronizar'; }
                        if (!res.ok || !res.j || !res.j.success || !res.j.job) {
                            var msg = (res.j && res.j.error) ? res.j.error : 'Não foi possível carregar o job.';
                            st.textContent = msg;
                            st.classList.remove('hidden');
                            st.className = 'text-sm text-red-700';
                            return;
                        }
                        var j = res.j.job;
                        document.getElementById('cron-edit-title').value = (j.title != null) ? String(j.title) : '';
                        document.getElementById('cron-edit-url').value = (j.url != null) ? String(j.url) : '';
                        document.getElementById('cron-edit-enabled').checked = !!j.enabled;
                        document.getElementById('cron-edit-update-schedule').checked = false;
                    }).catch(function () {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Salvar e sincronizar'; }
                        st.textContent = 'Erro de rede ao carregar o job.';
                        st.classList.remove('hidden');
                        st.className = 'text-sm text-red-700';
                    });
                }
                function fecharEdicaoCronJob() {
                    var modal = document.getElementById('cron-edit-modal');
                    if (!modal) return;
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
                window.excluirTodosCronOrgJobs = function (jobIds) {
                    if (!jobIds || !jobIds.length) {
                        return;
                    }
                    var tok = cronManageToken();
                    if (!tok) {
                        window.alert('Não foi possível obter o token de sessão. Recarregue a página.');
                        return;
                    }
                    var n = jobIds.length;
                    if (!window.confirm('Excluir TODOS os ' + n + ' job(s) na cron-job.org?\n\nOs IDs guardados em grupos e lojas serão limpos quando coincidirem. Esta ação não pode ser desfeita.')) {
                        return;
                    }
                    var btn = document.getElementById('cron-org-delete-all-btn');
                    if (btn) {
                        btn.disabled = true;
                    }
                    var i = 0;
                    function step() {
                        if (i >= jobIds.length) {
                            if (btn) {
                                btn.disabled = false;
                            }
                            fecharEdicaoCronJob();
                            if (typeof window.achadinhosRecarregarCronOrgJobs === 'function') {
                                window.achadinhosRecarregarCronOrgJobs(true).then(function () {
                                    try {
                                        if (window.history && window.history.replaceState) {
                                            window.history.replaceState({}, '', 'configuracoes.php?tab=crons');
                                        }
                                    } catch (e1) { /* ignore */ }
                                });
                            } else {
                                window.location.href = 'configuracoes.php?tab=crons&force_refresh=1&t=' + Date.now();
                            }
                            return;
                        }
                        var jobId = String(jobIds[i++]);
                        fetch('api/cron-job-manage.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': tok },
                            body: JSON.stringify({ action: 'delete', token: tok, job_id: jobId })
                        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                        .then(function (res) {
                            if (!res.ok || !res.j || !res.j.success) {
                                if (btn) {
                                    btn.disabled = false;
                                }
                                var err = (res.j && res.j.error) ? res.j.error : ('Falha ao excluir job #' + jobId);
                                window.alert(err);
                                return;
                            }
                            step();
                        }).catch(function () {
                            if (btn) {
                                btn.disabled = false;
                            }
                            window.alert('Erro de rede ao excluir.');
                        });
                    }
                    step();
                };
                function excluirCronJobOrg(jobId, titulo) {
                    titulo = titulo || '';
                    var msg = 'Excluir o job #' + jobId + (titulo ? ' (“' + titulo + '”)' : '') + ' na cron-job.org? Esta ação não pode ser desfeita.';
                    if (!window.confirm(msg)) return;
                    var st = document.getElementById('cron-edit-status');
                    fetch('api/cron-job-manage.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': cronManageToken() },
                        body: JSON.stringify({ action: 'delete', token: cronManageToken(), job_id: jobId })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok || !res.j || !res.j.success) {
                            var err = (res.j && res.j.error) ? res.j.error : 'Falha ao excluir.';
                            window.alert(err);
                            return;
                        }
                        fecharEdicaoCronJob();
                        if (typeof window.achadinhosRecarregarCronOrgJobs === 'function') {
                            window.achadinhosRecarregarCronOrgJobs(true).then(function () {
                                try {
                                    if (window.history && window.history.replaceState) {
                                        window.history.replaceState({}, '', 'configuracoes.php?tab=crons');
                                    }
                                } catch (e) { /* ignore */ }
                            });
                        } else {
                            window.location.href = 'configuracoes.php?tab=crons&force_refresh=1&t=' + Date.now();
                        }
                    }).catch(function () {
                        window.alert('Erro de rede ao excluir.');
                    });
                }
                function excluirCronJobOrgDoModal() {
                    var jobId = document.getElementById('cron-edit-job-id').value;
                    var titulo = document.getElementById('cron-edit-title').value || '';
                    if (!jobId) return;
                    excluirCronJobOrg(jobId, titulo);
                }
                function salvarEdicaoCronJob() {
                    var st = document.getElementById('cron-edit-status');
                    var jobId = document.getElementById('cron-edit-job-id').value;
                    var saveBtn = document.getElementById('cron-edit-save');
                    var ivMax = (typeof window.CRON_MONITOR_IV_MAX === 'number') ? window.CRON_MONITOR_IV_MAX : 720;
                    var body = {
                        action: 'patch',
                        token: cronManageToken(),
                        job_id: jobId,
                        title: document.getElementById('cron-edit-title').value,
                        url: document.getElementById('cron-edit-url').value,
                        enabled: document.getElementById('cron-edit-enabled').checked
                    };
                    if (document.getElementById('cron-edit-update-schedule').checked) {
                        body.update_schedule = true;
                        var iv = parseInt(document.getElementById('cron-edit-iv').value, 10);
                        if (isNaN(iv)) iv = 5;
                        iv = Math.max(1, Math.min(ivMax, iv));
                        body.intervalo_minutos = iv;
                        body.hora_inicio = parseInt(document.getElementById('cron-edit-h1').value, 10) || 0;
                        body.hora_fim = parseInt(document.getElementById('cron-edit-h2').value, 10) || 23;
                    }
                    st.classList.add('hidden');
                    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Salvando...'; }
                    fetch('api/cron-job-manage.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': cronManageToken() },
                        body: JSON.stringify(body)
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Salvar e sincronizar'; }
                        if (!res.ok || !res.j || !res.j.success) {
                            var msg = (res.j && res.j.error) ? res.j.error : 'Falha ao salvar.';
                            st.textContent = msg;
                            st.classList.remove('hidden');
                            st.className = 'text-sm text-red-700';
                            return;
                        }
                        st.textContent = res.j.message || 'Job atualizado.';
                        st.classList.remove('hidden');
                        st.className = 'text-sm text-emerald-700';
                        window.location.href = 'configuracoes.php?tab=crons&force_refresh=1&t=' + Date.now();
                    }).catch(function () {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Salvar e sincronizar'; }
                        st.textContent = 'Erro de rede ao salvar.';
                        st.classList.remove('hidden');
                        st.className = 'text-sm text-red-700';
                    });
                }
                document.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Escape') {
                        fecharEdicaoCronJob();
                    }
                });
                </script>
                <?php endif; ?>
                <p id="cronAutosaveFeedback" class="mt-3 hidden text-sm font-medium text-gray-500" aria-live="polite"></p>

            <div id="cronGlobalExecutarResultado" class="mt-6 hidden"></div>
            <script>
            (function () {
                var site = <?php echo json_encode($cron_base_preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                var cronGlobalToken = <?php echo json_encode($cron_token_global, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                var ivEl = document.getElementById('cron_intervalo_minutos');
                var h1El = document.getElementById('cron_hora_inicio');
                var h2El = document.getElementById('cron_hora_fim');
                var urlOut = document.getElementById('cron_global_preview_url');
                var exprFiveEl = document.getElementById('cron_global_expr_five');
                var hintOut = document.getElementById('cron_global_preview_hint');
                var noteOut = document.getElementById('cron_global_preview_note');
                var lineOut = document.getElementById('cron_global_preview_line');
                if (!urlOut || !hintOut || !lineOut || !exprFiveEl) return;

                function cronUrlAtePhpSemQuery(base) {
                    var b = String(base || '').replace(/\/$/, '');
                    return b ? (b + '/cron/rodar-tudo.php') : '';
                }

                function extrairExprCincoCampos(exprLinha) {
                    var s = String(exprLinha || '').trim().replace(/\s+/g, ' ');
                    var m = s.match(/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)/);
                    return m ? m[1] : s;
                }

                function parseCronLineToIvH1H2(raw) {
                    var s = String(raw || '').trim().replace(/\s+/g, ' ');
                    var m = s.match(/^\*\/(\d+)\s+(\d+)-(\d+)\s+\*\s+\*\s+\*$/);
                    if (m) {
                        return {
                            ok: true,
                            iv: parseInt(m[1], 10),
                            h1: parseInt(m[2], 10),
                            h2: parseInt(m[3], 10)
                        };
                    }
                    m = s.match(/^0\s+([\d,\s]+)\s+\*\s+\*\s+\*$/);
                    if (m) {
                        var parts = m[1].split(',').map(function (x) {
                            return parseInt(String(x).trim(), 10);
                        }).filter(function (x) { return !isNaN(x) && x >= 0 && x <= 23; });
                        if (parts.length > 0) {
                            var mn = Math.min.apply(null, parts);
                            var mx = Math.max.apply(null, parts);
                            return { ok: true, iv: 60, h1: mn, h2: mx, approx: true };
                        }
                    }
                    return { ok: false };
                }

                function setCronUrlField(el, v) {
                    if (!el) return;
                    el.value = v;
                }

                function horasNaJanela(h1, h2) {
                    var out = [];
                    if (h1 <= h2) {
                        for (var h = h1; h <= h2; h++) out.push(h);
                    } else {
                        for (var h = h1; h <= 23; h++) out.push(h);
                        for (var h = 0; h <= h2; h++) out.push(h);
                    }
                    return out;
                }
                function horasACadaK(h1, h2, k) {
                    k = Math.max(1, Math.min(23, k));
                    var ordered = horasNaJanela(h1, h2);
                    var allowed = {};
                    ordered.forEach(function (x) { allowed[x] = true; });
                    if (ordered.length === 0) return [];
                    var out = [];
                    var h = h1;
                    for (var guard = 0; guard < 48; guard++) {
                        if (!allowed[h]) break;
                        if (out.indexOf(h) !== -1) break;
                        out.push(h);
                        h = (h + k) % 24;
                    }
                    return out;
                }
                var _cronCfg = window.CRON_CONFIG || {};
                var CRON_IV_MAX = (typeof _cronCfg.maxInterval === 'number') ? _cronCfg.maxInterval : 720;
                var CRON_K_POR_IV = (_cronCfg.kPorIv && typeof _cronCfg.kPorIv === 'object') ? _cronCfg.kPorIv : { 60: 1, 120: 2, 180: 3, 240: 4, 360: 6, 480: 8, 720: 12 };
                function kHorasDoIntervaloCron(iv) {
                    return Object.prototype.hasOwnProperty.call(CRON_K_POR_IV, iv) ? CRON_K_POR_IV[iv] : null;
                }
                function previewCronPainel(iv, h1, h2) {
                    iv = Math.max(1, Math.min(CRON_IV_MAX, iv));
                    h1 = Math.max(0, Math.min(23, h1));
                    h2 = Math.max(0, Math.min(23, h2));
                    var apiNote = null;
                    if (iv < 60) {
                        if (h1 <= h2) {
                            return {
                                expr: '*/' + iv + ' ' + h1 + '-' + h2 + ' * * *',
                                hint: 'Exemplo de expressão Cron (a cada ' + iv + ' min, entre ' + h1 + 'h e ' + h2 + 'h):',
                                apiNote: null
                            };
                        }
                        return {
                            expr: '*/' + iv + ' * * * * (janela noturna ' + h1 + 'h–' + h2 + 'h: agendamento enviado à API cron-job.org)',
                            hint: 'Exemplo de expressão Cron (a cada ' + iv + ' min; janela noturna ' + h1 + 'h–' + h2 + 'h — veja linha abaixo):',
                            apiNote: null
                        };
                    }
                    var k = kHorasDoIntervaloCron(iv);
                    if (k === null) {
                        apiNote = 'Intervalo de ' + iv + ' min não pode ser reproduzido exatamente na API (limite horas×minutos); o job foi configurado como a cada 60 min (:00) na janela.';
                    }
                    var hours;
                    var hint;
                    if (k !== null) {
                        hours = horasACadaK(h1, h2, k);
                        hint = k === 1
                            ? 'Exemplo ilustrativo (a cada hora no :00; janela ' + h1 + 'h–' + h2 + 'h):'
                            : 'Exemplo ilustrativo (a cada ' + k + ' h no :00; janela ' + h1 + 'h–' + h2 + 'h):';
                    } else {
                        hours = horasNaJanela(h1, h2);
                        hint = 'Exemplo ilustrativo (a cada 60 min no :00 na janela; o intervalo de ' + iv + ' min não é representável de forma exata na API):';
                    }
                    var expr = '0 ' + hours.join(',') + ' * * *';
                    return { expr: expr, hint: hint, apiNote: apiNote };
                }

                function escShellArg(s) {
                    s = String(s);
                    if (s.indexOf("'") === -1) {
                        return "'" + s + "'";
                    }
                    return "'" + s.replace(/'/g, "'\"'\"'") + "'";
                }

                function buildCronCurlLine(expr, url, token) {
                    var h = '';
                    if (token) {
                        h = ' -H ' + escShellArg('X-Cron-Token: ' + token);
                    }
                    return expr + ' curl -s' + h + ' ' + escShellArg(url) + ' > /dev/null 2>&1';
                }

                function refresh() {
                    var iv = ivEl ? parseInt(ivEl.value, 10) : 5;
                    if (isNaN(iv)) iv = 5;
                    iv = Math.max(1, Math.min(CRON_IV_MAX, iv));
                    var h1 = h1El ? parseInt(h1El.value, 10) : 8;
                    var h2 = h2El ? parseInt(h2El.value, 10) : 22;
                    if (isNaN(h1)) h1 = 8;
                    if (isNaN(h2)) h2 = 22;
                    h1 = Math.max(0, Math.min(23, h1));
                    h2 = Math.max(0, Math.min(23, h2));
                    var u = cronUrlAtePhpSemQuery(site);

                    var pr = previewCronPainel(iv, h1, h2);
                    var line = buildCronCurlLine(pr.expr, u, cronGlobalToken);
                    setCronUrlField(urlOut, u);
                    exprFiveEl.value = extrairExprCincoCampos(pr.expr);
                    hintOut.textContent = pr.hint;
                    if (noteOut) {
                        if (pr.apiNote) {
                            noteOut.textContent = pr.apiNote;
                            noteOut.classList.remove('hidden');
                        } else {
                            noteOut.textContent = '';
                            noteOut.classList.add('hidden');
                        }
                    }
                    lineOut.textContent = line;
                }
                [ivEl, h1El, h2El].forEach(function (el) {
                    if (el) {
                        el.addEventListener('input', refresh);
                        el.addEventListener('change', refresh);
                    }
                });
                exprFiveEl.addEventListener('blur', function () {
                    var parsed = parseCronLineToIvH1H2(exprFiveEl.value);
                    if (!parsed.ok || !ivEl || !h1El || !h2El) return;
                    ivEl.value = String(Math.max(1, Math.min(CRON_IV_MAX, parsed.iv)));
                    h1El.value = String(Math.max(0, Math.min(23, parsed.h1)));
                    h2El.value = String(Math.max(0, Math.min(23, parsed.h2)));
                    ivEl.dispatchEvent(new Event('input', { bubbles: true }));
                    refresh();
                });
                refresh();
            })();
            </script>
            <script>
            (function () {
                var cronJobPanelToggle = document.getElementById('cron_global_job_toggle');
                var cronJobPanel = document.getElementById('cron_global_job_panel');
                var cronJobChevron = document.getElementById('cron_global_job_chevron');
                if (cronJobPanelToggle && cronJobPanel) {
                    cronJobPanelToggle.addEventListener('click', function () {
                        var expanded = cronJobPanelToggle.getAttribute('aria-expanded') === 'true';
                        var nextOpen = !expanded;
                        cronJobPanelToggle.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
                        cronJobPanel.classList.toggle('hidden', !nextOpen);
                        if (cronJobChevron) {
                            cronJobChevron.classList.toggle('rotate-0', nextOpen);
                            cronJobChevron.classList.toggle('-rotate-90', !nextOpen);
                        }
                    });
                }
                var form = document.getElementById('cronGlobalForm');
                if (!form) return;
                var apiKeyInp = document.getElementById('cron_job_org_api_key');
                var btnToggleKey = document.getElementById('btn_toggle_cron_api_key');
                var icKeyShow = document.getElementById('cron_api_key_icon_show');
                var icKeyHide = document.getElementById('cron_api_key_icon_hide');
                if (btnToggleKey && apiKeyInp) {
                    btnToggleKey.addEventListener('click', function () {
                        var show = apiKeyInp.type === 'password';
                        apiKeyInp.type = show ? 'text' : 'password';
                        if (icKeyShow && icKeyHide) {
                            icKeyShow.classList.toggle('hidden', show);
                            icKeyHide.classList.toggle('hidden', !show);
                        }
                        btnToggleKey.setAttribute('aria-label', show ? 'Ocultar chave' : 'Mostrar chave');
                    });
                }
                var feedback = document.getElementById('cronAutosaveFeedback');
                var timer = null;
                var saving = false;
                var pending = false;
                var token = (document.body && document.body.getAttribute('data-admin-autosave-token')) || '';

                function show(text, ok, variant) {
                    if (!feedback) return;
                    feedback.textContent = text;
                    feedback.classList.remove('hidden', 'text-green-700', 'text-red-600', 'text-amber-800', 'text-gray-500');
                    if (variant === 'warn') {
                        feedback.classList.add('text-amber-800');
                    } else {
                        feedback.classList.add(ok ? 'text-green-700' : 'text-red-600');
                    }
                    if (ok || variant === 'warn') {
                        clearTimeout(feedback._t);
                        feedback._t = setTimeout(function () { feedback.classList.add('hidden'); }, variant === 'warn' ? 5000 : 2200);
                    }
                }

                function payload(syncApi) {
                    return {
                        token: token,
                        sync_api: !!syncApi,
                        cron_intervalo_minutos: (document.getElementById('cron_intervalo_minutos') || {}).value || '',
                        cron_hora_inicio: (document.getElementById('cron_hora_inicio') || {}).value || '',
                        cron_hora_fim: (document.getElementById('cron_hora_fim') || {}).value || '',
                        produtos_dias_expiracao: (document.getElementById('produtos_dias_expiracao') || {}).value || '',
                        cron_job_org_api_key: (document.getElementById('cron_job_org_api_key') || {}).value || '',
                        cron_public_base_url: (document.getElementById('cron_public_base_url') || {}).value || ''
                    };
                }

                function saveCron(syncApi) {
                    if (!token) {
                        return Promise.reject(new Error('Token de sessão ausente.'));
                    }
                    var busyEl = document.getElementById('cronGlobalSyncBusy');
                    if (syncApi && busyEl) busyEl.classList.remove('hidden');
                    return fetch('api/crons-patch.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': token },
                        body: JSON.stringify(payload(syncApi))
                    }).then(function (r) {
                        return r.json().then(function (j) {
                            if (!r.ok || !j.ok) throw new Error((j && (j.error || j.message)) || ('HTTP ' + r.status));
                            return j;
                        });
                    }).finally(function () {
                        if (syncApi && busyEl) busyEl.classList.add('hidden');
                    });
                }

                function runAutosave() {
                    if (saving) {
                        pending = true;
                        return;
                    }
                    saving = true;
                    saveCron(false)
                        .then(function () {
                            saving = false;
                            show('Salvo automaticamente', true);
                            if (pending) {
                                pending = false;
                                runAutosave();
                            }
                        })
                        .catch(function (e) {
                            saving = false;
                            show(e && e.message ? e.message : 'Erro ao salvar', false);
                            if (pending) {
                                pending = false;
                                runAutosave();
                            }
                        });
                }

                form.addEventListener('submit', function (e) { e.preventDefault(); });
                form.addEventListener('input', function (e) {
                    var t = e.target;
                    if (!t || !t.name || t.type === 'button' || t.type === 'submit' || t.type === 'file') return;
                    clearTimeout(timer);
                    timer = setTimeout(runAutosave, 700);
                }, true);
                form.addEventListener('change', function (e) {
                    var t = e.target;
                    if (!t || !t.name || t.type === 'button' || t.type === 'submit' || t.type === 'file') return;
                    clearTimeout(timer);
                    timer = setTimeout(runAutosave, 250);
                }, true);

                window.salvarCronGlobalAntesExecutar = function () {
                    return saveCron(false).then(function () { show('Configurações salvas.', true); });
                };

                var btnCriarApiGlobal = document.getElementById('btn_criar_api_global');
                if (btnCriarApiGlobal) {
                    btnCriarApiGlobal.addEventListener('click', function () {
                        var desativar = (btnCriarApiGlobal.textContent.trim().indexOf('Desativar') === 0);
                        var prevKey = '';
                        if (!desativar) {
                            var keyVal = (apiKeyInp && apiKeyInp.value) ? String(apiKeyInp.value).trim() : '';
                            if (!keyVal) {
                                show('Informe a chave da API do cron-job.org antes de ativar a integração.', false);
                                return;
                            }
                        } else if (apiKeyInp) {
                            prevKey = apiKeyInp.value;
                            apiKeyInp.value = '';
                        }
                        btnCriarApiGlobal.disabled = true;
                        var txtOrig = btnCriarApiGlobal.textContent;
                        btnCriarApiGlobal.textContent = desativar ? 'Desativando API...' : 'Ativando API...';
                        saveCron(true)
                            .then(function (j) {
                                if (!desativar && j && j.sync_partial_no_job_id) {
                                    show('Integração ativada: o job foi criado na cron-job.org, mas o ID ainda não foi obtido (limite da API). Aguarde alguns segundos e use «Ativar API» de novo ou confira o painel cron-job.org.', true, 'warn');
                                } else {
                                    show(desativar ? 'Integração desativada localmente.' : 'Integração ativada e job sincronizado.', true);
                                }
                                window.location.reload();
                            })
                            .catch(function (e) {
                                if (desativar && apiKeyInp && prevKey !== '') {
                                    apiKeyInp.value = prevKey;
                                }
                                var err = e && e.message ? e.message : 'Erro ao sincronizar API.';
                                show((desativar ? 'Erro ao desativar. ' : 'Erro ao sincronizar API. ') + err, false);
                            })
                            .finally(function () {
                                btnCriarApiGlobal.disabled = false;
                                btnCriarApiGlobal.textContent = txtOrig;
                            });
                    });
                }
            })();

            function executarCronGlobal() {
                var btn = document.getElementById('btnCronGlobalExecutar');
                var txt = document.getElementById('btnCronGlobalExecutarTexto');
                var spi = document.getElementById('btnCronGlobalExecutarSpinner');
                var box = document.getElementById('cronGlobalExecutarResultado');
                if (!btn || !txt || !spi || !box) return;
                btn.disabled = true;
                txt.textContent = 'Criando...';
                spi.classList.remove('hidden');
                box.classList.add('hidden');
                box.innerHTML = '';

                var before = window.salvarCronGlobalAntesExecutar ? window.salvarCronGlobalAntesExecutar() : Promise.resolve();
                before.then(function () {
                    var controller = new AbortController();
                    var timeoutId = setTimeout(function () { controller.abort(); }, 600000);
                    var tk = (document.body && document.body.getAttribute('data-admin-autosave-token')) || '';
                    return fetch('api/cron-sync.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: controller.signal,
                        headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': tk },
                        body: JSON.stringify({ tipo: 'global', token: tk })
                    })
                    .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, status: r.status, text: t }; }); })
                    .then(function (res) {
                        clearTimeout(timeoutId);
                        btn.disabled = false;
                        txt.textContent = 'Criar hora';
                        spi.classList.add('hidden');
                        box.classList.remove('hidden');
                        var d = null;
                        try {
                            d = JSON.parse(res.text);
                        } catch (e) {
                            d = null;
                        }
                        if (!d) {
                            box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                            box.innerHTML = '<p class="font-bold">Erro</p><p class="mt-1">Resposta inválida do servidor.</p><pre class="mt-2 text-xs opacity-90 whitespace-pre-wrap">' + escapeHtmlCronGlobal(res.text.slice(0, 2000)) + '</pre>';
                            return;
                        }
                        if (res.status === 401) {
                            box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                            box.innerHTML = '<p class="font-bold">Não autorizado</p><p class="mt-1">' + escapeHtmlCronGlobal(d.message || 'Faça login novamente.') + '</p>';
                            return;
                        }
                        var isOk = d.success === true;
                        var syncAviso = isOk && d.sincronizacao === 'aviso';
                        box.className = 'mt-6 p-4 rounded ' + (isOk ? (syncAviso ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800') : 'bg-red-100 text-red-800');
                        box.innerHTML = '<p class="font-bold">' + (isOk ? (syncAviso ? 'Concluído com aviso' : 'Concluído') : 'Erro') + '</p>';
                        if (isOk) {
                            var syncText = (d.sincronizacao === 'ok')
                                ? (d.sincronizacao_msg ? String(d.sincronizacao_msg) : 'Concluído.')
                                : (d.sincronizacao === 'aviso'
                                    ? (d.sincronizacao_msg ? String(d.sincronizacao_msg) : 'Job criado na cron-job.org; ID será guardado na próxima sincronização.')
                                    : (d.sincronizacao === 'erro' ? 'Erro ao sincronizar cron' : 'Cron ainda não sincronizada'));
                            box.innerHTML += '<p class="mt-1">Execução: ok</p><p class="mt-1">Sincronização: ' + escapeHtmlCronGlobal(syncText) + '</p>';
                        } else if (d.error) {
                            box.innerHTML += '<p class="mt-1">' + escapeHtmlCronGlobal(d.error) + '</p>';
                        }
                        if (d.resultados) {
                            box.innerHTML += '<pre class="mt-3 text-sm opacity-90 whitespace-pre-wrap overflow-x-auto">' + escapeHtmlCronGlobal(typeof d.resultados === 'string' ? d.resultados : JSON.stringify(d.resultados, null, 2)) + '</pre>';
                        }
                    }).catch(function (e) {
                        clearTimeout(timeoutId);
                        throw e;
                    });
                }).catch(function (e) {
                    btn.disabled = false;
                    txt.textContent = 'Criar hora';
                    spi.classList.add('hidden');
                    box.classList.remove('hidden');
                    box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                    var msg = (e && e.name === 'AbortError') ? 'Tempo limite excedido (10 min). A execução global pode continuar em segundo plano no servidor.' : (e && e.message ? e.message : 'Falha na requisição.');
                    box.innerHTML = '<p class="font-bold">Erro</p><p>' + escapeHtmlCronGlobal(String(msg)) + '</p>';
                });
            }
            function escapeHtmlCronGlobal(s) {
                if (s == null) return '';
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }
            </script>

            <?php elseif ($activeTab === 'conta'): ?>
            <!-- Aba Conta admin: foto, usuário e senha -->
            <?php $adminUsernameAtual = $_SESSION['admin_username'] ?? ''; ?>
            <div class="space-y-8 max-w-xl">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Foto do perfil</h2>
                    <p class="text-sm text-gray-600 mb-4">Aparece no menu do canto superior direito do painel.</p>
                    <div class="flex flex-wrap items-center gap-6">
                        <?php if (!empty($adminAvatarPathUi)): ?>
                        <img src="../<?php echo htmlspecialchars($adminAvatarPathUi); ?>" alt="" class="h-20 w-20 rounded-full object-cover ring-2 ring-slate-200">
                        <?php else: ?>
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-slate-200 text-2xl font-bold text-slate-500 ring-2 ring-slate-200">
                            <?php
                            $iniConta = '?';
                            if ($adminUsernameAtual !== '') {
                                $c0 = function_exists('mb_substr') ? mb_substr($adminUsernameAtual, 0, 1, 'UTF-8') : substr($adminUsernameAtual, 0, 1);
                                if ($c0 !== '' && $c0 !== false) {
                                    $iniConta = function_exists('mb_strtoupper') ? mb_strtoupper($c0, 'UTF-8') : strtoupper($c0);
                                }
                            }
                            echo htmlspecialchars($iniConta);
                            ?>
                        </div>
                        <?php endif; ?>
                        <div class="flex flex-col gap-3 min-w-0">
                            <form method="POST" action="?tab=conta" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                                <input type="hidden" name="config_tab" value="conta">
                                <input type="hidden" name="admin_save_avatar_only" value="1">
                                <div>
                                    <label for="admin_avatar" class="block text-sm font-medium text-gray-700 mb-1">Nova foto</label>
                                    <input type="file" id="admin_avatar" name="admin_avatar" accept="image/jpeg,image/png,image/gif,image/webp"
                                           class="block w-full max-w-xs text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-orange-700 hover:file:bg-orange-100">
                                </div>
                                <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-4 rounded transition-colors text-sm">
                                    Enviar foto
                                </button>
                            </form>
                            <?php if (!empty($adminAvatarPathUi)): ?>
                            <form method="POST" action="?tab=conta" onsubmit="return confirm('Remover a foto do perfil?');">
                                <input type="hidden" name="config_tab" value="conta">
                                <input type="hidden" name="admin_remove_avatar" value="1">
                                <button type="submit" class="text-sm text-red-600 hover:text-red-800 font-medium">Remover foto</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <form method="POST" action="?tab=conta" class="space-y-8">
                <input type="hidden" name="config_tab" value="conta">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Alterar usuário e senha</h2>
                    <p class="text-sm text-gray-600 mb-6">Para alterar o login do painel admin, preencha a senha atual e o novo usuário e/ou a nova senha.</p>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Usuário atual</label>
                            <p class="text-gray-900 font-mono bg-gray-100 px-3 py-2 rounded"><?php echo htmlspecialchars($adminUsernameAtual); ?></p>
                        </div>
                        <div>
                            <label for="admin_username" class="block text-sm font-medium text-gray-700 mb-2">Novo nome de usuário</label>
                            <input type="text" id="admin_username" name="admin_username" autocomplete="username"
                                   value=""
                                   placeholder="Deixe em branco para não alterar"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Mínimo 3 caracteres. Deixe em branco para manter o atual.</p>
                        </div>
                        <div>
                            <label for="admin_senha_atual" class="block text-sm font-medium text-gray-700 mb-2">Senha atual *</label>
                            <input type="password" id="admin_senha_atual" name="admin_senha_atual" required autocomplete="current-password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Obrigatória para confirmar alterações.</p>
                        </div>
                        <div>
                            <label for="admin_senha_nova" class="block text-sm font-medium text-gray-700 mb-2">Nova senha</label>
                            <input type="password" id="admin_senha_nova" name="admin_senha_nova" autocomplete="new-password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Mínimo 6 caracteres. Deixe em branco para não alterar.</p>
                        </div>
                        <div>
                            <label for="admin_senha_nova_confirm" class="block text-sm font-medium text-gray-700 mb-2">Confirmar nova senha</label>
                            <input type="password" id="admin_senha_nova_confirm" name="admin_senha_nova_confirm" autocomplete="new-password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-6">
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                            Salvar alterações
                        </button>
                    </div>
                </div>
            </form>
            </div>
            <?php endif; ?>
        </main>
        
        <script>
        function deleteBanner(index) {
            if (confirm('Tem certeza que deseja deletar este banner?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_banner';
                input.value = index;
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleBannerType() {
            const imagesDiv = document.getElementById('banners-images');
            const defaultDiv = document.getElementById('banners-default');
            const radioImages = document.querySelector('input[name="banner_type"][value="images"]');
            const radioDefault = document.querySelector('input[name="banner_type"][value="default"]');
            
            if (radioImages.checked) {
                imagesDiv.style.display = 'block';
                defaultDiv.style.display = 'none';
            } else {
                imagesDiv.style.display = 'none';
                defaultDiv.style.display = 'block';
            }
        }
        
        function restoreDefaultBanners() {
            const defaultBanners = JSON.stringify([
                {"id": 1, "title": "Até 60% OFF", "subtitle": "em Eletrônicos", "description": "Ofertas imperdíveis para você", "bgGradient": "from-[hsl(var(--primary))] via-orange-500 to-orange-600"},
                {"id": 2, "title": "Super Ofertas", "subtitle": "em Celulares", "description": "Os melhores smartphones com desconto", "bgGradient": "from-orange-600 via-[hsl(var(--primary))] to-red-500"},
                {"id": 3, "title": "Black Friday", "subtitle": "Todo Dia", "description": "Preços baixos o ano inteiro", "bgGradient": "from-red-500 via-orange-500 to-[hsl(var(--primary))]"}
            ], null, 2);
            
            document.getElementById('banners_json').value = defaultBanners;
        }
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
