<?php
/**
 * Configurações da automação Magalu (Minha Loja Magazine Voce)
 * Loja Magazine Voce (sua URL) → scraping ofertas → copy com IA → Evolution (WhatsApp) → opcional site
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

$magalu_automacao_ativa     = getConfig('magalu_automacao_ativa', '0') === '1';
$magalu_loja_url            = getConfig('magalu_loja_url', '');
$magalu_loja_url_alternativa = getConfig('magalu_loja_url_alternativa', '');
$magalu_scraper_api_key      = getConfig('magalu_scraper_api_key', '');
$magalu_openai_model        = getConfig('magalu_openai_model', 'gpt-4o-mini');
$magalu_openai_prompt       = getConfig('magalu_openai_prompt', '');
if ($magalu_openai_prompt === '') {
    $magalu_openai_prompt = "Você é especialista em copy para ofertas Magalu no WhatsApp.\n\n" .
        "Crie mensagens curtas (máx. 12 linhas), com gancho, nome do produto em *negrito*, preço em destaque (R$), % de desconto se houver, benefícios com ✅, CTA em *negrito*.\n\n" .
        "Use formatação WhatsApp: *texto* e ~~riscado~~. Emojis com moderação. Foco em conversão. Não invente parcelamento.";
}
$magalu_evolution_conta_id  = getConfig('magalu_evolution_conta_id', '0');
$magalu_grupos_ids          = getConfig('magalu_grupos_ids', '');
$magalu_produtos_por_execucao = getConfig('magalu_produtos_por_execucao', '1');
$magalu_delay_entre_envios  = getConfig('magalu_delay_entre_envios', '10');
$magalu_dias_evitar_repetir = getConfig('magalu_dias_evitar_repetir', '1');
$magalu_site_publicar       = getConfig('magalu_site_publicar', '1') === '1';
$magalu_site_categoria_id   = getConfig('magalu_site_categoria_id', '-1');

$pdo = getDB();
// Buscar contas Evolution e grupos WhatsApp
$contasEvolution = $pdo->query("SELECT id, nome, url_base, instancia, api_key FROM evolution_contas WHERE ativo = 1 ORDER BY nome")->fetchAll();
$gruposWhatsApp = $pdo->query("SELECT id, nome, grupo_id FROM grupos_whatsapp WHERE ativo = 1 ORDER BY nome")->fetchAll();
$magalu_grupos_selecionados = !empty($magalu_grupos_ids) ? explode(',', $magalu_grupos_ids) : [];
$magalu_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('magalu', telegramLojaOwnerUserId()));
$magalu_whatsapp_status_ativo = getConfig('magalu_whatsapp_status_ativo', '0') === '1';
$magalu_telegram_envio_ativo = getConfig('magalu_telegram_envio_ativo', '1') === '1';
$magalu_telegram_story_ativo = getConfig('magalu_telegram_story_ativo', '0') === '1';

$pageTitle = 'Magalu';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Magalu</h1>
                </div>
                <span id="lojaAutosaveFeedback" class="text-sm font-medium text-gray-500 self-center hidden shrink-0" aria-live="polite"></span>
            </div>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-8" data-loja-autosave="magalu" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'Magalu';
                $lojaOcultarAbaWhatsapp = true;
                $lojaOcultarAbaHorarios = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>
                <?php
                $lojaEvolutionLojaKey = 'magalu';
                $lojaEvolutionContaId = (int) $magalu_evolution_conta_id;
                $lojaEvolutionPainelPrefix = 'magalu';
                require __DIR__ . '/includes/loja-evolution-status.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="magalu_automacao_ativa" value="1" <?php echo $magalu_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Sua loja Magazine Voce</h2>
                    <p class="text-sm text-gray-600 mb-4">A Magalu cria uma loja personalizada para você. Exemplo: <code class="bg-gray-100 px-1 rounded">https://www.magazinevoce.com.br/magazineinovapub/</code> — todos os produtos comprados por esse link geram sua comissão.</p>
                    <div>
                        <label for="magalu_loja_url" class="block text-sm font-medium text-gray-700 mb-2">URL da sua loja *</label>
                        <input type="url" id="magalu_loja_url" name="magalu_loja_url" placeholder="https://www.magazinevoce.com.br/sua-loja/"
                               value="<?php echo htmlspecialchars($magalu_loja_url); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="text-xs text-amber-600 mt-1">Use a URL <strong>raiz</strong> (ex.: .../magazineinovapub/). Evite /destaques/ ou /ofertas/ — podem retornar CAPTCHA.</p>
                    </div>
                    <div class="mt-4">
                        <label for="magalu_loja_url_alternativa" class="block text-sm font-medium text-gray-700 mb-2">URLs alternativas para scraping (opcional)</label>
                        <textarea id="magalu_loja_url_alternativa" name="magalu_loja_url_alternativa" rows="3" placeholder="https://www.magazinevoce.com.br/sua-loja/ofertas/&#10;https://www.magazinevoce.com.br/sua-loja/destaques/"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($magalu_loja_url_alternativa); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">URLs adicionais da sua loja (uma por linha ou separadas por vírgula). Ex.: ofertas, destaques, categorias. Quanto mais URLs, mais produtos para variar. Deve ser magazinevoce.com.br ou magazineluiza.com.br.</p>
                    </div>
                    <div class="mt-4">
                        <label for="magalu_scraper_api_key" class="block text-sm font-medium text-gray-700 mb-2">ScraperAPI (contornar CAPTCHA)</label>
                        <input type="text" id="magalu_scraper_api_key" name="magalu_scraper_api_key" placeholder="Sua API key do ScraperAPI.com"
                               value="<?php echo htmlspecialchars($magalu_scraper_api_key); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="text-xs text-gray-500 mt-1">Se a Magalu exibir CAPTCHA, use o <a href="https://www.scraperapi.com/" target="_blank" class="text-orange-600 hover:underline">ScraperAPI</a> (plano gratuito: 5000 req/mês). Cole aqui a API key.</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="magalu_site_publicar" value="1" <?php echo $magalu_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="magalu-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático). Categoria pai inclui subcategorias em filtros. «Mais vendidos» usa slug <code class="text-xs">mais-vendidos</code>.</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'magalu_site_categoria_id';
                        $lojaCategoriaFixaValor = $magalu_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                    <p class="text-sm text-gray-600 mb-4">Conta e grupos para envio desta loja (complementa o cadastro em <strong>Grupos</strong>).</p>

                    <div class="mb-4">
                        <label for="magalu_evolution_conta_id" class="block text-sm font-medium text-gray-700 mb-2">Conta Evolution *</label>
                        <select id="magalu_evolution_conta_id" name="magalu_evolution_conta_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">-- Selecione uma conta --</option>
                            <?php foreach ($contasEvolution as $conta): ?>
                            <option value="<?php echo $conta['id']; ?>" <?php echo $magalu_evolution_conta_id == $conta['id'] ? 'selected' : ''; ?>>
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
                                <input type="checkbox" name="magalu_grupos_ids[]" value="<?php echo $grupo['id']; ?>"
                                       <?php echo in_array($grupo['id'], $magalu_grupos_selecionados) ? 'checked' : ''; ?>
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
                        <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $magalu_whatsapp_status_ativo ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento da execução</h2>
                    <?php
                    $lojaHorariosPrefix = 'magalu';
                    $lojaHorariosProdutos = $magalu_produtos_por_execucao;
                    $lojaHorariosDelay = $magalu_delay_entre_envios;
                    $lojaHorariosDias = $magalu_dias_evitar_repetir;
                    require __DIR__ . '/includes/loja-horarios-comportamento-fields.php';
                    ?>
                </div>
                </div>

                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave da API OpenAI é a definida em <strong>Configurações → OpenAI</strong>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="magalu_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="magalu_openai_model" name="magalu_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $magalu_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $magalu_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $magalu_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="magalu_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="magalu_openai_prompt" name="magalu_openai_prompt" rows="10"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($magalu_openai_prompt); ?></textarea>
                        <p class="mt-1 text-xs text-gray-500">Define o estilo e a estrutura da mensagem.</p>
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
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $magalu_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $magalu_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. Imagem do produto obrigatória; redimensionamento 1080×1920 com GD quando disponível.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="magalu_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="magalu_telegram_chat_ids" name="magalu_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($magalu_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
