<?php
/**
 * Configurações da automação Shein
 * API Afiliados → IA (copy) → Evolution (WhatsApp) → Site
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

$shein_automacao_ativa     = getConfig('shein_automacao_ativa', '0') === '1';
$shein_api_key             = getConfig('shein_api_key', '');
$shein_api_secret          = getConfig('shein_api_secret', '');
$shein_openai_model        = getConfig('shein_openai_model', 'gpt-4o-mini');
$shein_openai_prompt       = getConfig('shein_openai_prompt', '');
if ($shein_openai_prompt === '') {
    $shein_openai_prompt = "Você é um especialista em copy para promoções no WhatsApp da SHEIN.\n\n" .
        "🎯 ESTRUTURA OBRIGATÓRIA:\n\n" .
        "1. 🔥 **TÍTULO EM NEGRITO E CAIXA ALTA** (com 2-3 emojis)\n" .
        "2. *Nome do produto em itálico*\n" .
        "3. ❌ ~~Preço original riscado~~ (se houver)\n" .
        "4. 💚 **Preço promocional em negrito**\n" .
        "5. 💥 **% OFF em negrito** (se houver)\n" .
        "6. ✅ 2-3 benefícios principais\n" .
        "7. 👗 Destaque para moda/estilo\n" .
        "8. **👉 CALL-TO-ACTION em negrito**\n\n" .
        "⚠️ REGRAS CRÍTICAS:\n\n" .
        "- Máximo 12 linhas\n" .
        "- NUNCA coloque o link no texto - ele será adicionado automaticamente depois\n" .
        "- Use apenas emojis, negrito e itálico\n" .
        "- SEMPRE termine com frase de exclusividade em negrito";
}
$shein_evolution_conta_id  = getConfig('shein_evolution_conta_id', '0');
$shein_grupos_ids          = getConfig('shein_grupos_ids', '');
$shein_produtos_por_execucao = getConfig('shein_produtos_por_execucao', '1');
$shein_delay_entre_envios  = getConfig('shein_delay_entre_envios', '10');
$shein_dias_evitar_repetir = getConfig('shein_dias_evitar_repetir', '1');
$shein_site_publicar       = getConfig('shein_site_publicar', '1') === '1';
$shein_site_categoria_id   = getConfig('shein_site_categoria_id', '-1');

$pdo = getDB();
// Buscar contas Evolution e grupos WhatsApp
$contasEvolution = $pdo->query("SELECT id, nome, url_base, instancia, api_key FROM evolution_contas WHERE ativo = 1 ORDER BY nome")->fetchAll();
$gruposWhatsApp = $pdo->query("SELECT id, nome, grupo_id FROM grupos_whatsapp WHERE ativo = 1 ORDER BY nome")->fetchAll();
$shein_grupos_selecionados = !empty($shein_grupos_ids) ? explode(',', $shein_grupos_ids) : [];
$shein_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('shein', telegramLojaOwnerUserId()));
$shein_whatsapp_status_ativo = getConfig('shein_whatsapp_status_ativo', '0') === '1';
$shein_telegram_envio_ativo = getConfig('shein_telegram_envio_ativo', '1') === '1';
$shein_telegram_story_ativo = getConfig('shein_telegram_story_ativo', '0') === '1';

$pageTitle = 'Shein';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Shein</h1>
                </div>
                <span id="lojaAutosaveFeedback" class="text-sm font-medium text-gray-500 self-center hidden shrink-0" aria-live="polite"></span>
            </div>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-8" data-loja-autosave="shein" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'Shein';
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>
                <?php
                $lojaEvolutionLojaKey = 'shein';
                $lojaEvolutionContaId = (int) $shein_evolution_conta_id;
                $lojaEvolutionPainelPrefix = 'shein';
                require __DIR__ . '/includes/loja-evolution-status.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="shein_automacao_ativa" value="1" <?php echo $shein_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Shein – API Afiliados</h2>
                    <p class="text-sm text-gray-600 mb-4">Credenciais em <a href="https://affiliate.shein.com" target="_blank" rel="noopener" class="text-orange-600 underline">affiliate.shein.com</a>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shein_api_key" class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                            <input type="text" id="shein_api_key" name="shein_api_key" placeholder="API Key"
                                   value="<?php echo htmlspecialchars($shein_api_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shein_api_secret" class="block text-sm font-medium text-gray-700 mb-2">API Secret</label>
                            <input type="password" id="shein_api_secret" name="shein_api_secret" placeholder="API Secret"
                                   value="<?php echo htmlspecialchars($shein_api_secret); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="shein_site_publicar" value="1" <?php echo $shein_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="shein-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático). Categoria pai inclui subcategorias em filtros. «Mais vendidos» usa slug <code class="text-xs">mais-vendidos</code>.</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'shein_site_categoria_id';
                        $lojaCategoriaFixaValor = $shein_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                    <p class="text-sm text-gray-600 mb-4">Conta e grupos para envio desta loja (complementa o cadastro em <strong>Grupos</strong>).</p>

                    <div class="mb-4">
                        <label for="shein_evolution_conta_id" class="block text-sm font-medium text-gray-700 mb-2">Conta Evolution *</label>
                        <select id="shein_evolution_conta_id" name="shein_evolution_conta_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">-- Selecione uma conta --</option>
                            <?php foreach ($contasEvolution as $conta): ?>
                            <option value="<?php echo $conta['id']; ?>" <?php echo $shein_evolution_conta_id == $conta['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($conta['nome']); ?> (<?php echo htmlspecialchars($conta['instancia']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Grupos WhatsApp *</label>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 max-h-64 overflow-y-auto border border-gray-200 rounded-md p-4">
                            <?php if (empty($gruposWhatsApp)): ?>
                            <p class="text-sm text-gray-500 col-span-full">Nenhum grupo cadastrado. Cadastre em <strong>Grupos</strong>.</p>
                            <?php else: ?>
                            <?php foreach ($gruposWhatsApp as $grupo): ?>
                            <label class="flex items-center gap-2 cursor-pointer p-2 hover:bg-gray-50 rounded">
                                <input type="checkbox" name="shein_grupos_ids[]" value="<?php echo $grupo['id']; ?>"
                                       <?php echo in_array($grupo['id'], $shein_grupos_selecionados) ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="text-sm text-gray-700"><?php echo htmlspecialchars($grupo['nome']); ?></span>
                            </label>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Horários de postagem ficam no cadastro de cada grupo.</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status (Stories) do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Publica a mesma oferta nos <strong>Status</strong> da instância da conta Evolution acima, na mesma execução (automação ativa e janelas em <strong>Grupos</strong>).</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $shein_whatsapp_status_ativo ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento da execução</h2>
                    <?php
                    $lojaHorariosPrefix = 'shein';
                    $lojaHorariosProdutos = $shein_produtos_por_execucao;
                    $lojaHorariosDelay = $shein_delay_entre_envios;
                    $lojaHorariosDias = $shein_dias_evitar_repetir;
                    require __DIR__ . '/includes/loja-horarios-comportamento-fields.php';
                    ?>
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                    <h3 class="text-lg font-bold text-yellow-800 mb-2">⚠️ Atenção</h3>
                    <p class="text-sm text-yellow-700">A automação Shein ainda não está completamente implementada. Configure as credenciais acima e aguarde a implementação da API.</p>
                </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave OpenAI global será usada (Configurações → OpenAI). Se não houver, use a chave específica da loja nas configurações gerais, quando existir.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shein_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="shein_openai_model" name="shein_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $shein_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $shein_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $shein_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="shein_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="shein_openai_prompt" name="shein_openai_prompt" rows="12"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($shein_openai_prompt); ?></textarea>
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
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $shein_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $shein_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="shein_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="shein_telegram_chat_ids" name="shein_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($shein_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
