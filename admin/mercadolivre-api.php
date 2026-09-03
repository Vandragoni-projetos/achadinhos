<?php
/**
 * Integração com API do Mercado Livre para buscar cupons
 * - Autenticação OAuth 2.0
 * - Busca de cupons/promoções disponíveis
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

// Processar upload de imagem
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_imagem') {
    if (isset($_FILES['imagem_cupom']) && $_FILES['imagem_cupom']['error'] === UPLOAD_ERR_OK) {
        $uploadedFile = uploadImagem($_FILES['imagem_cupom'], 'uploads/banners/');
        if ($uploadedFile) {
            setConfig('ml_cupons_imagem', $uploadedFile);
            $message = 'Imagem enviada com sucesso!';
            $messageType = 'success';
        } else {
            $message = 'Erro ao fazer upload da imagem. Verifique se é uma imagem válida.';
            $messageType = 'error';
        }
    }
}

// Processar callback OAuth
if (isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] === 'ml_oauth') {
    $code = $_GET['code'];
    $clientId = getConfig('ml_api_client_id', '');
    $clientSecret = getConfig('ml_api_client_secret', '');
    $redirectUri = getConfig('ml_api_redirect_uri', '');
    
    if (!empty($clientId) && !empty($clientSecret) && !empty($redirectUri)) {
        // Trocar código por access token
        $tokenUrl = 'https://api.mercadolibre.com/oauth/token';
        $postData = http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri
        ]);
        
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $tokenData = json_decode($response, true);
            if (isset($tokenData['access_token'])) {
                setConfig('ml_api_access_token', $tokenData['access_token']);
                if (isset($tokenData['refresh_token'])) {
                    setConfig('ml_api_refresh_token', $tokenData['refresh_token']);
                }
                if (isset($tokenData['user_id'])) {
                    setConfig('ml_api_user_id', $tokenData['user_id']);
                }
                
                $message = 'Autenticação realizada com sucesso!';
                $messageType = 'success';
            } else {
                $message = 'Erro ao obter token: ' . ($response ?? 'Resposta inválida');
                $messageType = 'error';
            }
        } else {
            $message = 'Erro na autenticação (HTTP ' . $httpCode . '): ' . $response;
            $messageType = 'error';
        }
    }
}

// Buscar cupons
$cupons = [];
$erroBusca = '';
if (isset($_GET['action']) && $_GET['action'] === 'buscar_cupons') {
    // Usar função de busca de cupons de afiliados
    require_once __DIR__ . '/../config/automacao-cupons-ml.php';
    
    $errors = [];
    $cupons = buscarCuponsDisponiveisML($errors);
    
    if (!empty($errors)) {
        $erroBusca = implode('. ', $errors);
    }
}

// Carregar valores atuais
$ml_api_client_id = getConfig('ml_api_client_id', '');
$ml_api_client_secret = getConfig('ml_api_client_secret', '');
$ml_api_redirect_uri = getConfig('ml_api_redirect_uri', '');
$ml_api_access_token = getConfig('ml_api_access_token', '');
$ml_api_refresh_token = getConfig('ml_api_refresh_token', '');
$ml_api_user_id = getConfig('ml_api_user_id', '');

// Configurações de automação
$ml_cupons_automacao_ativa = getConfig('ml_cupons_automacao_ativa', '0') === '1';
$ml_cupons_evolution_conta_id = (int)getConfig('ml_cupons_evolution_conta_id', '0');
$ml_cupons_grupos_ids_json = getConfig('ml_cupons_grupos_ids', '[]');
$ml_cupons_grupos_ids = json_decode($ml_cupons_grupos_ids_json, true) ?: [];
$ml_cupons_link_ativacao = getConfig('ml_cupons_link_ativacao', '');
$ml_cupons_delay_entre_envios = getConfig('ml_cupons_delay_entre_envios', '10');
$ml_cupons_produtos_por_execucao = getConfig('ml_cupons_produtos_por_execucao', '1');
$ml_cupons_dias_evitar_repetir = getConfig('ml_cupons_dias_evitar_repetir', '1');
$ml_cupons_imagem = getConfig('ml_cupons_imagem', '');
$ml_cupons_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('ml_cupons', telegramLojaOwnerUserId()));
$ml_cupons_cookie = getConfig('ml_cupons_cookie', '');
$ml_cupons_csrf = getConfig('ml_cupons_csrf_token', '');
$ml_cupons_whatsapp_status_ativo = getConfig('ml_cupons_whatsapp_status_ativo', '0') === '1';
$ml_cupons_telegram_envio_ativo = getConfig('ml_cupons_telegram_envio_ativo', '1') === '1';
$ml_cupons_telegram_story_ativo = getConfig('ml_cupons_telegram_story_ativo', '0') === '1';

// Carregar contas Evolution e grupos do banco
$pdo = getDB();
$contasEvolution = [];
$gruposWhatsApp = [];
try {
    $contasEvolution = $pdo->query("SELECT id, nome FROM evolution_contas WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $gruposWhatsApp = $pdo->query("SELECT g.id, g.nome, g.grupo_id, e.nome as evolution_nome FROM grupos_whatsapp g LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id WHERE g.ativo = 1 ORDER BY g.nome")->fetchAll();
} catch (Exception $e) {
    // Tabelas podem não existir ainda
}

// Se não tiver redirect_uri configurado, usar a URL atual
if (empty($ml_api_redirect_uri)) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $ml_api_redirect_uri = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/mercadolivre-api.php';
}

$pageTitle = 'ML Cupons';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">ML Cupons</h1>
                </div>
                <span id="lojaAutosaveFeedback" class="text-sm font-medium text-gray-500 self-center hidden shrink-0" aria-live="polite"></span>
            </div>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form id="form-ml-cupons-upload" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_imagem">
            </form>

            <form method="post" class="space-y-8" data-loja-autosave="ml_cupons" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'ML Cupons';
                $lojaTabsTightMobile = true;
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>
                <?php
                $lojaEvolutionLojaKey = 'ml_cupons';
                $lojaEvolutionContaId = (int) $ml_cupons_evolution_conta_id;
                $lojaEvolutionPainelPrefix = 'ml_cupons';
                require __DIR__ . '/includes/loja-evolution-status.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 class="font-semibold text-blue-900 mb-2">ℹ️ Sobre esta integração</h3>
                    <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                        <li><strong>Busca automática de cupons:</strong> O sistema busca automaticamente os cupons disponíveis na página de afiliados e gera os códigos automaticamente</li>
                        <li><strong>Autenticação específica:</strong> É necessário configurar Cookie e CSRF Token específicos da área de cupons de afiliados (diferentes dos usados para criar links de produtos)</li>
                        <li><strong>Geração automática:</strong> Os códigos são gerados automaticamente, sem necessidade de fazer manualmente na interface</li>
                        <li><strong>Validade e condições:</strong> As informações de validade e produtos/lojas são obtidas automaticamente dos cupons disponíveis</li>
                    </ul>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="ml_cupons_automacao_ativa" value="1" <?php echo $ml_cupons_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa (quando chegar o horário, a automação rodará se estiver marcada)</span>
                    </label>
                </div>

            <!-- Configurações de Autenticação -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Configurações de Autenticação</h2>
                <p class="text-sm text-gray-600 mb-4">Para buscar cupons de afiliados automaticamente, você precisa configurar o <strong>Cookie</strong> e <strong>x-csrf-token</strong> da área de afiliados do Mercado Livre. Use os mesmos valores configurados na página "Mercado Livre" (para criar links de afiliado).</p>
                
                <details class="mb-4 rounded-md border border-amber-200 bg-amber-50 open:shadow-sm [&[open]>summary_.ml-cupons-tutorial-chev]:rotate-180">
                    <summary class="cursor-pointer select-none list-none px-4 py-3 text-sm font-medium text-amber-800 hover:bg-amber-100/70 rounded-md flex items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                        <span>📝 Como obter Cookie e CSRF Token da área de cupons</span>
                        <span class="ml-cupons-tutorial-chev inline-block text-amber-600 text-xs shrink-0 transition-transform duration-200" aria-hidden="true">▼</span>
                    </summary>
                    <div class="px-4 pb-4 pt-1 border-t border-amber-200/80">
                        <ol class="text-sm text-amber-700 list-decimal list-inside space-y-2 mt-3">
                            <li>Acesse: <a href="https://www.mercadolivre.com.br/afiliados/coupons" target="_blank" class="underline font-medium">https://www.mercadolivre.com.br/afiliados/coupons</a></li>
                            <li>Pressione <kbd class="px-1 bg-gray-200 rounded">F12</kbd> para abrir o DevTools</li>
                            <li>Vá para a aba <strong>Network (Rede)</strong></li>
                            <li>Recarregue a página (F5) ou clique em qualquer ação na página de cupons</li>
                            <li><strong>Procure requisições onde o "Initiator" mostra <code>coupons:5</code></strong> (essas são as específicas da área de cupons)</li>
                            <li>As requisições mais importantes são:
                                <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                    <li>Requisições <code>NRBR-...</code> (xhr) - geralmente contêm dados dos cupons</li>
                                    <li>Requisição <code>header</code> (xhr) - pode conter headers de autenticação</li>
                                    <li>Requisição <code>last?nc=...</code> (xhr) - pode buscar lista de cupons</li>
                                </ul>
                            </li>
                            <li>Clique em qualquer uma dessas requisições iniciadas por <code>coupons:5</code></li>
                            <li>Vá para a aba <strong>Headers</strong> da requisição</li>
                            <li>Na seção <strong>Request Headers</strong>, procure:
                                <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                    <li><code>cookie:</code> - copie todo o valor (pode ser muito longo)</li>
                                    <li><code>x-csrf-token:</code> - copie o valor completo</li>
                                </ul>
                            </li>
                            <li>Cole os valores nos campos abaixo</li>
                        </ol>
                        <div class="mt-3 p-3 bg-yellow-100 border border-yellow-300 rounded">
                            <p class="text-xs font-medium text-yellow-900">⚠️ Importante:</p>
                            <p class="text-xs text-yellow-800 mt-1">Use apenas requisições onde o "Initiator" é <code>coupons:5</code>. Requisições iniciadas por outros scripts (como <code>melidata.min.js</code>) não contêm as informações corretas da área de cupons.</p>
                        </div>
                    </div>
                </details>

                <div class="space-y-4">
                    <div>
                        <label for="ml_cupons_cookie" class="block text-sm font-medium text-gray-700 mb-2">Cookie (da área de cupons de afiliados) *</label>
                        <textarea id="ml_cupons_cookie" name="ml_cupons_cookie" rows="4" 
                                  placeholder="Cole aqui o cookie completo da área de cupons de afiliados"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-xs"><?php echo htmlspecialchars($ml_cupons_cookie); ?></textarea>
                        <p class="mt-1 text-xs text-gray-500">Este cookie é específico da área de cupons de afiliados e é diferente do cookie usado para criar links de produtos.</p>
                    </div>
                    
                    <div>
                        <label for="ml_cupons_csrf_token" class="block text-sm font-medium text-gray-700 mb-2">x-csrf-token (da área de cupons) *</label>
                        <input type="text" id="ml_cupons_csrf_token" name="ml_cupons_csrf_token" 
                               value="<?php echo htmlspecialchars($ml_cupons_csrf); ?>"
                               placeholder="Valor do header x-csrf-token da área de cupons"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Este CSRF token é específico da área de cupons de afiliados.</p>
                    </div>
                    
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-md">
                        <p class="text-sm text-blue-800"><strong>ℹ️ Importante:</strong></p>
                        <p class="text-xs text-blue-700 mt-1">Estes campos são específicos para a área de cupons de afiliados e são diferentes dos campos usados na página "Mercado Livre" para criar links de produtos. Configure-os separadamente.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">API OAuth (opcional)</h2>
                <p class="text-sm text-gray-600 mb-4">Credenciais do aplicativo Mercado Livre e tokens retornados pelo fluxo OAuth. Os campos são gravados automaticamente ao editar; o Client Secret só muda quando você digitar um novo valor.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ml_api_client_id" class="block text-sm font-medium text-gray-700 mb-2">Client ID</label>
                        <input type="text" id="ml_api_client_id" name="ml_api_client_id" autocomplete="off"
                               value="<?php echo htmlspecialchars($ml_api_client_id); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                    </div>
                    <div>
                        <label for="ml_api_client_secret" class="block text-sm font-medium text-gray-700 mb-2">Client Secret</label>
                        <input type="password" id="ml_api_client_secret" name="ml_api_client_secret" autocomplete="new-password"
                               placeholder="<?php echo $ml_api_client_secret !== '' ? 'Preencha apenas para alterar o secret' : 'Client secret'; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                        <?php if ($ml_api_client_secret !== ''): ?>
                        <p class="mt-1 text-xs text-gray-500">Secret já cadastrado. Deixe em branco ao salvar para manter o valor atual.</p>
                        <?php endif; ?>
                    </div>
                    <div class="md:col-span-2">
                        <label for="ml_api_redirect_uri" class="block text-sm font-medium text-gray-700 mb-2">Redirect URI</label>
                        <input type="url" id="ml_api_redirect_uri" name="ml_api_redirect_uri"
                               value="<?php echo htmlspecialchars($ml_api_redirect_uri); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label for="ml_api_access_token" class="block text-sm font-medium text-gray-700 mb-2">Access token</label>
                        <textarea id="ml_api_access_token" name="ml_api_access_token" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-xs"><?php echo htmlspecialchars($ml_api_access_token); ?></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label for="ml_api_refresh_token" class="block text-sm font-medium text-gray-700 mb-2">Refresh token</label>
                        <textarea id="ml_api_refresh_token" name="ml_api_refresh_token" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-xs"><?php echo htmlspecialchars($ml_api_refresh_token); ?></textarea>
                    </div>
                    <div>
                        <label for="ml_api_user_id" class="block text-sm font-medium text-gray-700 mb-2">User ID</label>
                        <input type="text" id="ml_api_user_id" name="ml_api_user_id"
                               value="<?php echo htmlspecialchars($ml_api_user_id); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm">
                    </div>
                </div>
            </div>

            <!-- Buscar Cupons -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Buscar Cupons de Afiliados</h2>
                <p class="text-sm text-gray-600 mb-4">Busque automaticamente os cupons disponíveis na área de afiliados do Mercado Livre. O sistema irá gerar os códigos automaticamente.</p>
                
                <?php if ($erroBusca): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded">
                    <?php echo htmlspecialchars($erroBusca); ?>
                </div>
                <?php endif; ?>
                
                <div class="flex gap-4 mb-4">
                    <a href="?action=buscar_cupons" 
                       class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors inline-flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Buscar Meus Cupons
                    </a>
                </div>
                
                <?php if (!empty($cupons)): ?>
                <div class="mt-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Cupons Encontrados (<?php echo count($cupons); ?>)</h3>
                    <div class="space-y-4">
                        <?php foreach ($cupons as $cupom): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h4 class="font-semibold text-gray-800">Cupom de Afiliado</h4>
                                    <?php if (!empty($cupom['codigo'])): ?>
                                    <p class="text-lg font-bold text-orange-600 font-mono mt-1"><?php echo htmlspecialchars($cupom['codigo']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($cupom['id'])): ?>
                                    <p class="text-sm text-gray-600">ID: <?php echo htmlspecialchars($cupom['id']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($cupom['validade'])): ?>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                                    Válido até <?php 
                                    try {
                                        $data = new DateTime($cupom['validade']);
                                        echo $data->format('d/m/Y');
                                    } catch (Exception $e) {
                                        echo htmlspecialchars($cupom['validade']);
                                    }
                                    ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($cupom['desconto'])): ?>
                            <p class="text-lg font-bold text-green-600 mb-2">
                                <?php echo htmlspecialchars($cupom['desconto']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($cupom['condicoes'])): ?>
                            <p class="text-sm text-gray-700 mb-2">
                                <strong>Condições:</strong> <?php echo htmlspecialchars($cupom['condicoes']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($cupom['produtos'])): ?>
                            <p class="text-sm text-gray-700 mb-2">
                                <strong>Produtos/Lojas:</strong> <?php echo htmlspecialchars($cupom['produtos']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($cupom['orçamento'])): ?>
                            <p class="text-sm text-gray-700">
                                <strong>Orçamento restante:</strong> R$ <?php 
                                if (is_numeric($cupom['orçamento'])) {
                                    echo number_format($cupom['orçamento'], 2, ',', '.');
                                } else {
                                    echo htmlspecialchars($cupom['orçamento']);
                                }
                                ?>
                            </p>
                            <?php endif; ?>
                            
                            <details class="mt-3">
                                <summary class="text-sm text-gray-600 cursor-pointer hover:text-gray-800">Ver detalhes completos</summary>
                                <pre class="mt-2 p-3 bg-gray-50 rounded text-xs overflow-auto"><?php echo htmlspecialchars(json_encode($cupom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                            </details>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php elseif (isset($_GET['action']) && $_GET['action'] === 'buscar_cupons' && empty($erroBusca)): ?>
                <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-yellow-800">Nenhum cupom encontrado.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Upload de Imagem -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Imagem dos Cupons</h2>
                <p class="text-sm text-gray-600 mb-4">Faça upload da imagem que será enviada junto com os cupons. Esta imagem aparecerá no topo da mensagem.</p>
                <div class="space-y-4">
                    <div>
                        <label for="imagem_cupom" class="block text-sm font-medium text-gray-700 mb-2">Selecionar Imagem</label>
                        <input type="file" id="imagem_cupom" name="imagem_cupom" form="form-ml-cupons-upload" accept="image/*"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Formatos aceitos: JPG, PNG, GIF, WEBP</p>
                    </div>
                    <?php if (!empty($ml_cupons_imagem)): ?>
                    <div class="mt-4">
                        <p class="text-sm font-medium text-gray-700 mb-2">Imagem atual:</p>
                        <img src="/<?php echo htmlspecialchars($ml_cupons_imagem); ?>" alt="Imagem dos cupons"
                             class="max-w-md border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-2"><?php echo htmlspecialchars($ml_cupons_imagem); ?></p>
                    </div>
                    <?php endif; ?>
                    <button type="submit" form="form-ml-cupons-upload" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Enviar Imagem
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Link de ativação</h2>
                <div>
                    <label for="ml_cupons_link_ativacao" class="block text-sm font-medium text-gray-700 mb-2">Link para ativação dos cupons</label>
                    <input type="url" id="ml_cupons_link_ativacao" name="ml_cupons_link_ativacao" placeholder="https://mercadolivre.com/sec/1Bfp7jH"
                           value="<?php echo htmlspecialchars($ml_cupons_link_ativacao); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    <p class="mt-1 text-xs text-gray-500">Este link será incluído na mensagem dos cupons.</p>
                    <p class="mt-1 text-xs text-amber-600">ℹ️ <strong>Nota:</strong> Atualmente não é possível obter este link automaticamente via API do Mercado Livre. Configure manualmente quando necessário.</p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                <p class="text-sm text-gray-600 mb-4">Conta e grupos que receberão os cupons (complementa o cadastro em <strong>Grupos</strong>).</p>
                <div class="space-y-4">
                    <div>
                        <label for="ml_cupons_evolution_conta_id" class="block text-sm font-medium text-gray-700 mb-2">Conta Evolution *</label>
                        <select id="ml_cupons_evolution_conta_id" name="ml_cupons_evolution_conta_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">Selecione uma conta</option>
                            <?php foreach ($contasEvolution as $conta): ?>
                            <option value="<?php echo $conta['id']; ?>"
                                    <?php echo $ml_cupons_evolution_conta_id == $conta['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($conta['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($contasEvolution)): ?>
                        <p class="mt-1 text-xs text-red-500">Nenhuma conta Evolution cadastrada. Cadastre em Configurações → WhatsApp.</p>
                        <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500">Conta usada para enviar os cupons.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Grupos WhatsApp *</label>
                        <div class="border border-gray-300 rounded-md p-3 max-h-64 overflow-y-auto">
                            <?php if (empty($gruposWhatsApp)): ?>
                            <p class="text-sm text-gray-500">Nenhum grupo cadastrado. Cadastre grupos em <strong>Grupos</strong>.</p>
                            <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($gruposWhatsApp as $grupo): ?>
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-2 rounded">
                                    <input type="checkbox" name="ml_cupons_grupos[]" value="<?php echo $grupo['id']; ?>"
                                           <?php echo in_array($grupo['id'], $ml_cupons_grupos_ids) ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                    <div class="flex-1">
                                        <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($grupo['nome']); ?></span>
                                        <span class="text-xs text-gray-500 ml-2">(<?php echo htmlspecialchars($grupo['grupo_id']); ?>)</span>
                                        <?php if (!empty($grupo['evolution_nome'])): ?>
                                        <span class="text-xs text-gray-400 ml-2">- <?php echo htmlspecialchars($grupo['evolution_nome']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Horários de postagem ficam no cadastro de cada grupo.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Status (Stories) do WhatsApp</h2>
                <p class="text-sm text-gray-600 mb-4">Publica nos <strong>Status</strong> da instância da conta Evolution acima, na mesma execução (automação ativa e janelas em <strong>Grupos</strong>).</p>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $ml_cupons_whatsapp_status_ativo ? 'checked' : ''; ?>
                           class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                    <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                </label>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento da execução</h2>
                <?php
                $lojaHorariosPrefix = 'ml_cupons';
                $lojaHorariosProdutos = $ml_cupons_produtos_por_execucao;
                $lojaHorariosDelay = $ml_cupons_delay_entre_envios;
                $lojaHorariosDias = $ml_cupons_dias_evitar_repetir;
                require __DIR__ . '/includes/loja-horarios-comportamento-fields.php';
                ?>
            </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt</h2>
                    <p class="text-sm text-gray-600">Não há campo de prompt OpenAI dedicado para ML Cupons nesta página. A automação de cupons usa o fluxo próprio de mensagens.</p>
                </div>
                </div>

                <div id="tab-telegram" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Telegram</h2>
                    <p class="text-sm text-gray-600 mb-6">Envios aos <strong>grupos/canais Telegram</strong> e opcionalmente aos <strong>Stories</strong> da conta <strong>Telegram Business</strong> ligada ao bot (Configurações → Telegram). Na mesma rodada automática por <strong>horário</strong>, com automação ativa na aba Geral.</p>

                    <div class="border border-gray-200 rounded-lg p-4 mb-6">
                        <input type="hidden" name="telegram_envio_ativo" value="0">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $ml_cupons_telegram_envio_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span>
                                <span class="text-gray-800 font-medium">Ativar envio para Telegram nesta loja</span>
                                <span class="block text-xs text-gray-500 mt-1">Desmarcado: não envia para os chats listados abaixo nem usa o chat global como fallback.</span>
                            </span>
                        </label>
                    </div>

                    <div class="border border-gray-200 rounded-lg p-4 mb-6">
                        <h3 class="text-sm font-semibold text-gray-800 mb-3">Stories do Telegram</h3>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $ml_cupons_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="ml_cupons_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="ml_cupons_telegram_chat_ids" name="ml_cupons_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($ml_cupons_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações.</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
