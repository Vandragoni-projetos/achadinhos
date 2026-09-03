<?php
/**
 * Configurações da automação Amazon
 * API Associates → IA (copy) → grupos WhatsApp (cadastro em Grupos) → Site
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

$amazon_automacao_ativa     = getConfig('amazon_automacao_ativa', '0') === '1';
$amazon_access_key          = getConfig('amazon_access_key', '');
$amazon_secret_key          = getConfig('amazon_secret_key', '');
$amazon_associate_tag       = getConfig('amazon_associate_tag', '');
$amazon_region              = getConfig('amazon_region', 'com.br');
$amazon_search_keywords     = getConfig('amazon_search_keywords', '');
$amazon_openai_model        = getConfig('amazon_openai_model', 'gpt-4o-mini');
$amazon_openai_prompt       = getConfig('amazon_openai_prompt', '');
if ($amazon_openai_prompt === '') {
    $amazon_openai_prompt = "Você é um especialista em copy para promoções no WhatsApp da AMAZON.\n\n" .
        "🎯 ESTRUTURA OBRIGATÓRIA:\n\n" .
        "1. 🔥 **TÍTULO EM NEGRITO E CAIXA ALTA** (com 2-3 emojis)\n" .
        "2. *Nome do produto em itálico*\n" .
        "3. ❌ ~~Preço original riscado~~ (só se a linha «Preço:» indicar valor anterior explícito)\n" .
        "4. 💚 **Preço promocional em negrito** (use exatamente os valores da linha «Preço:» do sistema)\n" .
        "5. 💥 **% OFF em negrito** (só se «Preço:» trouxer percentual; não invente)\n" .
        "6. ✅ 2-3 benefícios principais\n" .
        "7. ⭐ Prova social (avaliação se > 4.0 e você tiver certeza)\n" .
        "8. **👉 CALL-TO-ACTION em negrito**\n\n" .
        "⚠️ REGRAS CRÍTICAS:\n\n" .
        "- Máximo 12 linhas\n" .
        "- NUNCA coloque o link no texto - ele será adicionado automaticamente depois\n" .
        "- Use apenas emojis, negrito e itálico\n" .
        "- Se «Preço:» for «Ver preço na Amazon», NÃO escreva preço nem % OFF nem «Consulte na Amazon»; foque em benefícios e CTA\n" .
        "- NUNCA use frases genéricas tipo «Consulte na Amazon» no lugar de números de preço\n" .
        "- SEMPRE termine com frase de exclusividade em negrito";
}
$amazon_site_publicar       = getConfig('amazon_site_publicar', '1') === '1';
$amazon_site_categoria_id   = getConfig('amazon_site_categoria_id', '-1');

$pdo = getDB();
$amazon_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('amazon', telegramLojaOwnerUserId()));
$amazon_telegram_envio_ativo = getConfig('amazon_telegram_envio_ativo', '1') === '1';
$amazon_telegram_story_ativo = getConfig('amazon_telegram_story_ativo', '0') === '1';

$pageTitle = 'Amazon';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Amazon</h1>
                </div>
                <?php
                $lojaEnviarAgora = ['api' => 'api/amazon-enviar-agora.php', 'prefix' => 'amazon'];
                require_once __DIR__ . '/includes/loja-enviar-agora-btn.php';
                ?>
            </div>
            <?php require_once __DIR__ . '/includes/loja-enviar-agora-resultado.php'; ?>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-8" data-loja-autosave="amazon" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'Amazon';
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="amazon_automacao_ativa" value="1" <?php echo $amazon_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Amazon Associates – API</h2>
                    <p class="text-sm text-gray-600 mb-4">Credenciais em <a href="https://associados.amazon.com.br" target="_blank" rel="noopener" class="text-orange-600 underline">associados.amazon.com.br</a>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="amazon_access_key" class="block text-sm font-medium text-gray-700 mb-2">Access Key</label>
                            <input type="text" id="amazon_access_key" name="amazon_access_key" placeholder="Access Key"
                                   value="<?php echo htmlspecialchars($amazon_access_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="amazon_secret_key" class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                            <input type="password" id="amazon_secret_key" name="amazon_secret_key" placeholder="Secret Key"
                                   value="<?php echo htmlspecialchars($amazon_secret_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="amazon_associate_tag" class="block text-sm font-medium text-gray-700 mb-2">Associate Tag</label>
                            <input type="text" id="amazon_associate_tag" name="amazon_associate_tag" placeholder="Associate Tag"
                                   value="<?php echo htmlspecialchars($amazon_associate_tag); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="amazon_region" class="block text-sm font-medium text-gray-700 mb-2">Região</label>
                            <select id="amazon_region" name="amazon_region" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="com.br" <?php echo $amazon_region === 'com.br' ? 'selected' : ''; ?>>Brasil (com.br)</option>
                                <option value="com" <?php echo $amazon_region === 'com' ? 'selected' : ''; ?>>EUA (com)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="amazon_search_keywords" class="block text-sm font-medium text-gray-700 mb-2">Palavras-chave para busca (PA-API)</label>
                        <p class="text-xs text-gray-500 mb-2">Uma por linha. A cada execução é usada <strong>uma</strong> linha aleatória no <code class="text-xs bg-gray-100 px-1 rounded">SearchItems</code>. Se ficar vazio, o sistema usa termos genéricos em português.</p>
                        <textarea id="amazon_search_keywords" name="amazon_search_keywords" rows="4" placeholder="ofertas do dia&#10;fone bluetooth&#10;panela de pressão"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($amazon_search_keywords); ?></textarea>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <p class="text-sm text-gray-600 mb-4">Conta WhatsApp, horários, intervalo entre posts e <strong>categoria Amazon (Browse Node)</strong> por grupo: <a href="grupos.php" class="text-orange-600 font-medium hover:underline">Grupos</a>.</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="amazon_site_publicar" value="1" <?php echo $amazon_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="amazon-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático). Categoria pai inclui subcategorias em filtros. «Mais vendidos» usa slug <code class="text-xs">mais-vendidos</code>.</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'amazon_site_categoria_id';
                        $lojaCategoriaFixaValor = $amazon_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                    <h3 class="text-lg font-bold text-blue-900 mb-2">Product Advertising API</h3>
                    <p class="text-sm text-blue-900/90">É necessário <strong>registo na PA-API 5</strong> no portal de associados (mesmo país do Associate Tag) e chaves ativas. Sem vendas recentes a Amazon pode limitar ou recusar chamadas. Use palavras-chave acima para orientar as ofertas.</p>
                </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave OpenAI global será usada (Configurações → OpenAI). Se não houver, use a chave específica da loja nas configurações gerais, quando existir.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="amazon_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="amazon_openai_model" name="amazon_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $amazon_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $amazon_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $amazon_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="amazon_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="amazon_openai_prompt" name="amazon_openai_prompt" rows="12"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($amazon_openai_prompt); ?></textarea>
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
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $amazon_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $amazon_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="amazon_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="amazon_telegram_chat_ids" name="amazon_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($amazon_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
