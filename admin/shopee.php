<?php
/**
 * Configurações da automação Shopee
 * API Afiliados Shopee → IA (copy) → WhatsApp/Telegram (via Grupos) → Site
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

$shopee_automacao_ativa     = getConfig('shopee_automacao_ativa', '0') === '1';
$shopee_app_id              = getConfig('shopee_app_id', '');
$shopee_secret              = getConfig('shopee_secret', '');
$shopee_openai_model        = getConfig('shopee_openai_model', 'gpt-4o-mini');
$shopee_openai_prompt       = getConfig('shopee_openai_prompt', '');
if ($shopee_openai_prompt === '') {
    $shopee_openai_prompt = "Você é especialista em copywriting para WhatsApp da SHOPEE.\n\n" .
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
}
$shopee_site_publicar       = getConfig('shopee_site_publicar', '1') === '1';
$shopee_site_categoria_id   = getConfig('shopee_site_categoria_id', '-1');

$shopee_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('shopee', telegramLojaOwnerUserId()));
$shopee_whatsapp_status_ativo = getConfig('shopee_whatsapp_status_ativo', '0') === '1';
$shopee_telegram_envio_ativo = getConfig('shopee_telegram_envio_ativo', '1') === '1';
$shopee_telegram_story_ativo = getConfig('shopee_telegram_story_ativo', '0') === '1';

$pageTitle = 'Shopee';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Shopee</h1>
                </div>
                <?php
                $lojaEnviarAgora = ['api' => 'api/shopee-enviar-agora.php', 'prefix' => 'shopee'];
                require_once __DIR__ . '/includes/loja-enviar-agora-btn.php';
                ?>
            </div>
            <?php require_once __DIR__ . '/includes/loja-enviar-agora-resultado.php'; ?>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8" data-loja-autosave="shopee" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'Shopee';
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="shopee_automacao_ativa" value="1" <?php echo $shopee_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Shopee – API Afiliados</h2>
                    <p class="text-sm text-gray-600 mb-4">App ID e Secret em <a href="https://affiliate.shopee.com.br" target="_blank" rel="noopener" class="text-orange-600 underline">affiliate.shopee.com.br</a>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_app_id" class="block text-sm font-medium text-gray-700 mb-2">App ID</label>
                            <input type="text" id="shopee_app_id" name="shopee_app_id" placeholder="App ID da API"
                                   value="<?php echo htmlspecialchars($shopee_app_id); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_secret" class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                            <input type="password" id="shopee_secret" name="shopee_secret" placeholder="Secret da API"
                                   value="<?php echo htmlspecialchars($shopee_secret); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <p class="text-sm text-gray-600 mb-4">Produtos em <strong>destaque</strong> e categoria automática (ou fixa).</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="shopee_site_publicar" value="1" <?php echo $shopee_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="shopee-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático). Categoria pai inclui subcategorias em filtros. «Mais vendidos» usa slug <code class="text-xs">mais-vendidos</code>.</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'shopee_site_categoria_id';
                        $lojaCategoriaFixaValor = $shopee_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status (Stories) do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Publica a mesma oferta nos <strong>Status</strong> da instância usada no envio (conta Evolution de cada grupo em <strong>Grupos</strong>), na mesma execução quando a automação estiver ativa.</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $shopee_whatsapp_status_ativo ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                    </label>
                </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave da API OpenAI é a definida em <strong>Configurações → OpenAI</strong>. Não é necessário informar aqui.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="shopee_openai_model" name="shopee_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $shopee_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $shopee_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $shopee_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="shopee_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="shopee_openai_prompt" name="shopee_openai_prompt" rows="12"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($shopee_openai_prompt); ?></textarea>
                        <p class="mt-1 text-xs text-gray-500">Define o estilo e a estrutura da mensagem. Dados do produto são enviados automaticamente ao modelo.</p>
                    </div>
                </div>
                </div>

                <div id="tab-telegram" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Telegram</h2>
                    <p class="text-sm text-gray-600 mb-6">Envios aos <strong>grupos/canais Telegram</strong> e opcionalmente aos <strong>Stories</strong> da conta <strong>Telegram Business</strong> ligada ao bot (Configurações → Telegram). Na mesma rodada automática por <strong>horário</strong>, com automação ativa na aba Geral.</p>

                    <div class="border border-gray-200 rounded-lg p-4 mb-6">
                        <input type="hidden" name="telegram_envio_ativo" value="0">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $shopee_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $shopee_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="shopee_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="shopee_telegram_chat_ids" name="shopee_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($shopee_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
