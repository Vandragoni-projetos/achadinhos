<?php
/**
 * Configurações da automação AliExpress
 * API Affiliate → IA (copy) → Evolution (WhatsApp) → Site
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

$aliexpress_automacao_ativa     = getConfig('aliexpress_automacao_ativa', '0') === '1';
$aliexpress_app_key             = getConfig('aliexpress_app_key', '');
$aliexpress_app_secret          = getConfig('aliexpress_app_secret', '');
$aliexpress_openai_model        = getConfig('aliexpress_openai_model', 'gpt-4o-mini');
$aliexpress_openai_prompt       = getConfig('aliexpress_openai_prompt', '');
if ($aliexpress_openai_prompt === '') {
    $aliexpress_openai_prompt = "Você é um especialista em copy para promoções no WhatsApp do ALIEXPRESS.\n\n" .
        "🎯 ESTRUTURA OBRIGATÓRIA (siga nesta ordem):\n\n" .
        "1. 🔥 **TÍTULO EM NEGRITO E CAIXA ALTA** (com 2-3 emojis)\n" .
        "2. *Nome do produto em itálico*\n" .
        "3. ❌ ~~Preço original riscado~~ (se houver)\n" .
        "4. 💚 **Preço promocional em negrito**\n" .
        "5. 💥 **% OFF em negrito** (se houver)\n" .
        "6. ✅ 2-3 benefícios principais\n" .
        "7. 🌍 Destaque para frete grátis/internacional\n" .
        "8. **👉 CALL-TO-ACTION em negrito**\n\n" .
        "⚠️ REGRAS CRÍTICAS:\n\n" .
        "- Máximo 12 linhas. Resposta deve conter SOMENTE o copy, sem link nem URL.\n" .
        "- NUNCA coloque link no texto - será adicionado automaticamente depois.\n" .
        "- Formatação: **negrito**, *itálico*, ~~riscado~~.\n" .
        "- SEMPRE termine com frase de exclusividade em negrito (ex: **Oferta exclusiva do grupo!**).";
}
$aliexpress_site_publicar       = getConfig('aliexpress_site_publicar', '1') === '1';
$aliexpress_site_categoria_id   = getConfig('aliexpress_site_categoria_id', '-1');

$aliexpress_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('aliexpress', telegramLojaOwnerUserId()));
$aliexpress_whatsapp_status_ativo = getConfig('aliexpress_whatsapp_status_ativo', '0') === '1';
$aliexpress_telegram_envio_ativo = getConfig('aliexpress_telegram_envio_ativo', '1') === '1';
$aliexpress_telegram_story_ativo = getConfig('aliexpress_telegram_story_ativo', '0') === '1';

$pageTitle = 'AliExpress';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">AliExpress</h1>
                </div>
                <?php
                $lojaEnviarAgora = ['api' => 'api/aliexpress-enviar-agora.php', 'prefix' => 'aliexpress'];
                require_once __DIR__ . '/includes/loja-enviar-agora-btn.php';
                ?>
            </div>
            <?php require_once __DIR__ . '/includes/loja-enviar-agora-resultado.php'; ?>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8" data-loja-autosave="aliexpress" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'AliExpress';
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="aliexpress_automacao_ativa" value="1" <?php echo $aliexpress_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">AliExpress – API Affiliate</h2>
                    <p class="text-sm text-gray-600 mb-4">Credenciais em <a href="https://openservice.aliexpress.com" target="_blank" rel="noopener" class="text-orange-600 underline">openservice.aliexpress.com</a>.</p>                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="aliexpress_app_key" class="block text-sm font-medium text-gray-700 mb-2">App Key</label>
                            <input type="text" id="aliexpress_app_key" name="aliexpress_app_key" placeholder="App Key"
                                   value="<?php echo htmlspecialchars($aliexpress_app_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="aliexpress_app_secret" class="block text-sm font-medium text-gray-700 mb-2">App Secret</label>
                            <input type="password" id="aliexpress_app_secret" name="aliexpress_app_secret" placeholder="App Secret"
                                   value="<?php echo htmlspecialchars($aliexpress_app_secret); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <p class="text-sm text-gray-600">A <strong>categoria de produtos</strong> da API de afiliados é definida por <strong>grupo</strong>, em <a href="grupos.php?tab=adicionar" class="text-orange-600 underline">Grupos → Adicionar grupo</a> (ou editando o grupo), quando a loja do grupo for AliExpress. Lá a lista aparece com nomes traduzidos para português quando possível.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="aliexpress_site_publicar" value="1" <?php echo $aliexpress_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="aliexpress-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático). Categoria pai inclui subcategorias em filtros. «Mais vendidos» usa slug <code class="text-xs">mais-vendidos</code>.</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'aliexpress_site_categoria_id';
                        $lojaCategoriaFixaValor = $aliexpress_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status (Stories) do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Publica a mesma oferta nos <strong>Status</strong> da instância usada no envio (conta Evolution de cada grupo em <strong>Grupos</strong>), na mesma execução quando a automação estiver ativa.</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $aliexpress_whatsapp_status_ativo ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                    </label>
                </div>

                <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                    <h3 class="text-lg font-bold text-green-800 mb-2">✅ Integração ativa</h3>
                    <p class="text-sm text-green-700">A automação busca produtos na API Affiliate do AliExpress pela <strong>categoria configurada em cada grupo</strong> (Grupos), gera o texto com o prompt configurado e pode publicar no site e enviar para WhatsApp. Preencha App Key e App Secret aqui; horário, intervalo e categoria ficam no cadastro do grupo. Os links no WhatsApp são <strong>encurtados automaticamente</strong> pelo <code class="text-xs">r.php</code> do seu site (URL inferida do acesso ao painel ou de <strong>Configurações → URL base</strong> / URL da loja).</p>
                </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave OpenAI global será usada (Configurações → OpenAI). Se não houver, use a chave específica da loja nas configurações gerais, quando existir.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="aliexpress_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="aliexpress_openai_model" name="aliexpress_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $aliexpress_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $aliexpress_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $aliexpress_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="aliexpress_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="aliexpress_openai_prompt" name="aliexpress_openai_prompt" rows="12"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($aliexpress_openai_prompt); ?></textarea>
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
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $aliexpress_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $aliexpress_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="aliexpress_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="aliexpress_telegram_chat_ids" name="aliexpress_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($aliexpress_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
