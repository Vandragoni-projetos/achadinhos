<?php
/**
 * Configurações da automação Mercado Livre
 * - Scraping de ofertas, conversão para link de afiliado, IA, envio WhatsApp (Evolution API)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

// Carregar valores atuais
$ml_automacao_ativa     = getConfig('ml_automacao_ativa', '0') === '1';
$ml_tag_afiliado        = getConfig('ml_tag_afiliado', '');
$ml_csrf_token          = getConfig('ml_csrf_token', '');
$ml_cookie              = getConfig('ml_cookie', '');
$ml_openai_model        = getConfig('ml_openai_model', 'gpt-4.1-mini');
$ml_openai_prompt       = getConfig('ml_openai_prompt', '');
if ($ml_openai_prompt === '') {
    $ml_openai_prompt = "Você é um especialista em copy para promoções no WhatsApp (Mercado Livre/outlet). Crie mensagens curtas (máx. 12 linhas), com gancho, nome em *negrito*, preço (~~antigo~~ → *atual*), % de desconto em *negrito*, 3 benefícios com ✅, CTA em *negrito*. Use formatação WhatsApp: *texto* e ~~riscado~~. Emojis com moderação. Nunca invente parcelamento; omita se não tiver certeza. Foco em conversão.";
}
$ml_site_publicar       = getConfig('ml_site_publicar', '1') === '1';
$ml_site_categoria_id   = getConfig('ml_site_categoria_id', '-1');
$ml_createlink_debug    = getConfig('ml_createlink_last_response', '');

$pdo = getDB();

$ml_grupos_ids = getConfig('ml_grupos_ids', '');

// Buscar contas Evolution e grupos WhatsApp
$gruposWhatsApp = $pdo->query("SELECT id, nome, grupo_id FROM grupos_whatsapp WHERE ativo = 1 ORDER BY nome")->fetchAll();
$ml_grupos_selecionados = trim($ml_grupos_ids) !== '' ? array_values(array_filter(array_map('intval', explode(',', $ml_grupos_ids)))) : [];
$ml_telegram_chat_ids_text = implode("\n", getTelegramChatIdsPorLoja('ml', telegramLojaOwnerUserId()));
$ml_whatsapp_status_ativo = getConfig('ml_whatsapp_status_ativo', '0') === '1';
$ml_telegram_envio_ativo = getConfig('ml_telegram_envio_ativo', '1') === '1';
$ml_telegram_story_ativo = getConfig('ml_telegram_story_ativo', '0') === '1';
$ml_whatsapp_exigir_foto = getConfig('ml_whatsapp_exigir_foto', '1') === '1';

$pageTitle = 'Mercado Livre';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Mercado Livre</h1>
                    <p class="text-sm text-gray-500 mt-1">Afiliados</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="mlBtnEnviarAgora"
                            class="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span id="mlEnviarAgoraTexto">Enviar agora</span>
                        <span id="mlEnviarAgoraSpinner" class="hidden h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true"></span>
                    </button>
                    <span id="lojaAutosaveFeedback" class="text-sm font-medium text-gray-500 hidden shrink-0" aria-live="polite"></span>
                </div>
            </div>
            <div id="mlEnviarAgoraResultado" class="mb-4 hidden rounded-lg border p-4 text-sm" role="status" aria-live="polite"></div>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-8" data-loja-autosave="ml" action="javascript:void(0)">
                <div class="loja-tabs-root space-y-6">
                <?php
                $lojaNomeAbaPrincipal = 'Mercado Livre';
                $lojaTabsTightMobile = true;
                $lojaOcultarAbaHorarios = true;
                $lojaOcultarAbaWhatsapp = true;
                require_once __DIR__ . '/includes/loja-form-tabs.php';
                ?>

                <div id="tab-geral" class="tab-content space-y-6">
                <!-- Ativar automação -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="ml_automacao_ativa" value="1" <?php echo $ml_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa (quando chegar o horário, a automação rodará se estiver marcada)</span>
                    </label>
                </div>

                <!-- Mercado Livre - Afiliados -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Mercado Livre – Programa de Afiliados</h2>
                    <p class="text-sm text-gray-600 mb-4">Para converter links em links de afiliado é obrigatório estar logado no ML. O <strong>x-csrf-token</strong> e o <strong>cookie</strong> expiram; quando a conversão parar de funcionar, atualize os valores abaixo.</p>
                    
                    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <button type="button" onclick="mlToggleAfiliadosPainel('csrf');"
                                class="shrink-0 text-left text-orange-600 hover:text-orange-700 text-sm font-medium">
                            ► Como obter o CSRF e o Cookie
                        </button>
                        <button type="button" onclick="mlToggleAfiliadosPainel('ext');"
                                class="shrink-0 text-left text-orange-600 hover:text-orange-700 text-sm font-medium">
                            ► Como instalar a Extensão
                        </button>
                        <a href="<?php echo htmlspecialchars(adminBaixarExtensaoMlUrl(), ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex shrink-0 items-center rounded-md bg-orange-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-500">
                            Baixar Extensão
                        </a>
                    </div>
                    <div id="ml-instrucoes" class="hidden mb-4 p-4 bg-gray-50 rounded border border-gray-200 text-sm text-gray-700">
                        <ol class="list-decimal list-inside space-y-2">
                            <li>Abra o Chrome/Edge em modo anônimo e faça login no Mercado Livre.</li>
                            <li>Acesse: <a href="https://www.mercadolivre.com.br/afiliados/linkbuilder" target="_blank" rel="noopener" class="text-orange-600 underline">Link Builder</a>.</li>
                            <li>Pressione <kbd class="px-1 bg-gray-200 rounded">F12</kbd> (DevTools) → aba <strong>Network (Rede)</strong> → filtre por <strong>Fetch/XHR</strong>.</li>
                            <li>Na página do Link Builder, gere um link de afiliado manualmente.</li>
                            <li>Procure a requisição <code>createLink</code> → clique com o botão direito → <strong>Copy</strong> → <strong>Copy as cURL</strong>.</li>
                            <li>Cole em um editor e copie o valor do header <code>x-csrf-token</code> e do header <code>cookie</code> (completo).</li>
                        </ol>
                    </div>
                    <div id="ml-ext-instrucoes" class="hidden mb-4 p-4 bg-gray-50 rounded border border-gray-200 text-sm text-gray-700">
                        <ol class="list-decimal list-inside space-y-2">
                            <li>Clique em <strong>Baixar Extensão</strong> e salve o arquivo <code class="bg-gray-100 px-1 rounded">extensao-ml-afiliados.zip</code> no seu computador.</li>
                            <li>Extraia o ZIP para uma pasta (ex.: <code class="bg-gray-100 px-1 rounded">extensao-ml-afiliados</code>). Deve existir um arquivo <code class="bg-gray-100 px-1 rounded">manifest.json</code> dentro da pasta que você vai carregar.</li>
                            <li>Abra o <strong>Google Chrome</strong> ou <strong>Microsoft Edge</strong> (Chromium).</li>
                            <li>Acesse <code class="bg-gray-100 px-1 rounded">chrome://extensions</code> ou <code class="bg-gray-100 px-1 rounded">edge://extensions</code>.</li>
                            <li>Ative o <strong>Modo do desenvolvedor</strong> (canto superior direito).</li>
                            <li>Clique em <strong>Carregar sem compactação</strong> / <strong>Load unpacked</strong> e selecione a pasta extraída da extensão (a que contém o <code class="bg-gray-100 px-1 rounded">manifest.json</code>).</li>
                            <li>Confirme se a extensão aparece na lista e está <strong>ativada</strong>. Em atualizações futuras, substitua os arquivos ou remova a extensão e carregue a pasta novamente.</li>
                            <li>Em caso de erro ao carregar, verifique se não há subpasta a mais (às vezes o ZIP cria uma pasta dupla); o navegador deve apontar direto para a pasta com o <code class="bg-gray-100 px-1 rounded">manifest.json</code>.</li>
                        </ol>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_tag_afiliado" class="block text-sm font-medium text-gray-700 mb-2">Tag de afiliado</label>
                            <input type="text" id="ml_tag_afiliado" name="ml_tag_afiliado" placeholder="ex: dv20251007071953"
                                   value="<?php echo htmlspecialchars($ml_tag_afiliado); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_csrf_token" class="block text-sm font-medium text-gray-700 mb-2">x-csrf-token</label>
                            <input type="text" id="ml_csrf_token" name="ml_csrf_token" placeholder="valor do header x-csrf-token"
                                   value="<?php echo htmlspecialchars($ml_csrf_token); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="ml_cookie" class="block text-sm font-medium text-gray-700 mb-2">Cookie (completo)</label>
                        <textarea id="ml_cookie" name="ml_cookie" rows="4" placeholder="Cole aqui o valor do header cookie (pode ser longo)"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($ml_cookie); ?></textarea>
                    </div>
                    <?php if ($ml_createlink_debug !== ''): ?>
                    <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded">
                        <button type="button" onclick="document.getElementById('ml-debug-resposta').classList.toggle('hidden');" class="text-amber-800 font-medium text-sm">► Última resposta da API createLink (quando deu erro)</button>
                        <pre id="ml-debug-resposta" class="mt-2 text-xs overflow-x-auto max-h-48 overflow-y-auto bg-white p-2 rounded border border-amber-200 hidden"><?php echo htmlspecialchars($ml_createlink_debug); ?></pre>
                        <p class="mt-1 text-xs text-amber-700">Se o createLink falhar de novo, expanda acima e envie esse texto para ajustar o sistema.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Publicar no site -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site de ofertas</h2>
                    <p class="text-sm text-gray-600 mb-4">Ao enviar no WhatsApp, o produto também será criado aqui no site: imagem, nome e link de afiliado. Os produtos entram como <strong>destaque</strong> e a <strong>categoria</strong> é definida automaticamente (por palavra‑chave nas existentes ou sugerida automaticamente se não houver uma adequada).</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="ml_site_publicar" value="1" <?php echo $ml_site_publicar ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="ml-site-categoria-id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Padrão: Todos (automático) — palavras‑chave ou classificação automática. Categoria pai inclui subcategorias em filtros de publicação. «Mais vendidos» usa a categoria com slug <code class="text-xs">mais-vendidos</code> (não aparece duplicada na lista).</p>
                        <?php
                        $lojaCategoriaFixaFieldName = 'ml_site_categoria_id';
                        $lojaCategoriaFixaValor = $ml_site_categoria_id;
                        require __DIR__ . '/includes/loja-select-categoria-fixa.php';
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">WhatsApp — envio com imagem</h2>
                    <p class="text-sm text-gray-600 mb-4">Por padrão, se o download da foto do anúncio falhar, o envio segue <strong>só com texto</strong>. Ative a opção abaixo para <strong>não enviar</strong> ao grupo quando não houver imagem (útil para manter posts sempre com foto).</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="ml_whatsapp_exigir_foto" value="1" <?php echo $ml_whatsapp_exigir_foto ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Exigir foto no envio ao WhatsApp (cancelar envio se a imagem não estiver disponível)</span>
                    </label>
                    <p class="mt-2 text-xs text-gray-500">Contas <strong>Uazapi</strong> não publicam Status; use Evolution para Stories ou desative Status nesta loja.</p>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status (Stories) do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Publica a mesma oferta nos <strong>Status</strong> da instância usada pelo envio (mesma execução da automação, com automação ativa acima). Requer <strong>Evolution</strong>; com Uazapi o fluxo ignora Status.</p>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="whatsapp_status_ativo" value="1" <?php echo $ml_whatsapp_status_ativo ? 'checked' : ''; ?>
                               class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Publicar ofertas nos Status do WhatsApp automaticamente</span>
                    </label>
                </div>
                </div>

                <!-- Prompt (OpenAI) -->
                <div id="tab-ia" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Prompt e modelo</h2>
                    <p class="text-sm text-gray-600 mb-4">A chave da API OpenAI é a definida em <strong>Configurações → OpenAI</strong>. Não é necessário informar aqui.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="ml_openai_model" name="ml_openai_model"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4.1-mini" <?php echo $ml_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o-mini" <?php echo $ml_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4o" <?php echo $ml_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                                <option value="gpt-4-turbo" <?php echo $ml_openai_model === 'gpt-4-turbo' ? 'selected' : ''; ?>>gpt-4-turbo</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="ml_openai_prompt" class="block text-sm font-medium text-gray-700 mb-2">Prompt (instruções para o texto do envio)</label>
                        <textarea id="ml_openai_prompt" name="ml_openai_prompt" rows="8"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($ml_openai_prompt); ?></textarea>
                        <p class="mt-1 text-xs text-gray-500">Define o estilo e a estrutura da mensagem. Nome do produto, preço e dados do anúncio são enviados automaticamente ao modelo.</p>
                    </div>
                </div>
                </div>

                <div id="tab-telegram" class="tab-content space-y-6 hidden">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Telegram</h2>
                    <p class="text-sm text-gray-600 mb-6">Envios aos <strong>grupos/canais Telegram</strong> e opcionalmente aos <strong>Stories</strong> da conta <strong>Telegram Business</strong> ligada ao bot (Configurações → Telegram). Tudo na mesma rodada automática por <strong>horário</strong>, com automação ativa na aba Geral.</p>

                    <div class="border border-gray-200 rounded-lg p-4 mb-6">
                        <input type="hidden" name="telegram_envio_ativo" value="0">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="telegram_envio_ativo" value="1" <?php echo $ml_telegram_envio_ativo ? 'checked' : ''; ?>
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
                            <input type="checkbox" name="telegram_story_ativo" value="1" <?php echo $ml_telegram_story_ativo ? 'checked' : ''; ?>
                                   class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                            <span class="text-gray-700">Publicar ofertas nos Stories do Telegram (conta Business)</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">Usa o <strong>mesmo bot</strong> e o <strong>Business connection ID</strong> em Configurações → Telegram. A API exige imagem do produto; o sistema redimensiona para 1080×1920 quando o servidor tem GD. Requer Telegram Business conectado ao bot com permissão de stories.</p>
                    </div>

                    <h3 class="text-sm font-semibold text-gray-800 mb-2">Grupos Telegram</h3>
                    <p class="text-sm text-gray-600 mb-4">Destinos extras por loja (além do chat global em Configurações).</p>
                    <div>
                        <label for="ml_telegram_chat_ids" class="block text-sm font-medium text-gray-700 mb-2">Grupos Telegram (opcional)</label>
                        <textarea id="ml_telegram_chat_ids" name="ml_telegram_chat_ids" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"
                                  placeholder="-1001234567890"><?php echo htmlspecialchars($ml_telegram_chat_ids_text); ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Um chat_id por linha. Se vazio, usa só o Telegram global em Configurações. Destinos vinculados ao ID de admin em Configurações (automações / dispatches).</p>
                    </div>
                </div>
                </div>

                </div>

            </form>

        </main>
        <script src="js/loja-autosave.js"></script>
        <script>
        function mlToggleAfiliadosPainel(painel) {
            var csrf = document.getElementById('ml-instrucoes');
            var ext = document.getElementById('ml-ext-instrucoes');
            if (!csrf || !ext) return;
            if (painel === 'csrf') {
                if (csrf.classList.contains('hidden')) {
                    ext.classList.add('hidden');
                    csrf.classList.remove('hidden');
                } else {
                    csrf.classList.add('hidden');
                }
            } else if (painel === 'ext') {
                if (ext.classList.contains('hidden')) {
                    csrf.classList.add('hidden');
                    ext.classList.remove('hidden');
                } else {
                    ext.classList.add('hidden');
                }
            }
        }
        </script>
        <script>
        (function () {
            var btn = document.getElementById('mlBtnEnviarAgora');
            var txt = document.getElementById('mlEnviarAgoraTexto');
            var spi = document.getElementById('mlEnviarAgoraSpinner');
            var box = document.getElementById('mlEnviarAgoraResultado');
            if (!btn || !box) return;
            var FETCH_MS = 360000;
            function escapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }
            btn.addEventListener('click', function () {
                var token = (document.body && document.body.getAttribute('data-admin-autosave-token')) || '';
                btn.disabled = true;
                if (txt) txt.textContent = 'Enviando (pode levar vários minutos)…';
                if (spi) spi.classList.remove('hidden');
                box.classList.add('hidden');
                box.innerHTML = '';
                var ac = new AbortController();
                var to = setTimeout(function () {
                    ac.abort();
                }, FETCH_MS);
                fetch('api/ml-enviar-agora.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: ac.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Autosave-Token': token
                    },
                    body: JSON.stringify({ token: token })
                })
                    .then(function (r) {
                        return r.text().then(function (text) {
                            var data = null;
                            try {
                                data = text ? JSON.parse(text) : null;
                            } catch (e) {
                                data = null;
                            }
                            return { ok: r.ok, status: r.status, data: data, text: text };
                        });
                    })
                    .then(function (pack) {
                        clearTimeout(to);
                        btn.disabled = false;
                        if (txt) txt.textContent = 'Enviar agora';
                        if (spi) spi.classList.add('hidden');
                        box.classList.remove('hidden');
                        var d = pack.data;
                        if (!d || typeof d !== 'object') {
                            box.className = 'mb-4 rounded-lg border p-4 text-sm bg-red-50 text-red-800 border-red-200';
                            box.innerHTML =
                                '<p class="font-semibold">Erro</p><p class="mt-1">Resposta inválida (HTTP ' +
                                pack.status +
                                ').</p><pre class="mt-2 text-xs overflow-x-auto max-h-48 whitespace-pre-wrap">' +
                                escapeHtml((pack.text || '').slice(0, 8000)) +
                                '</pre>';
                            return;
                        }
                        var ok = !!d.success;
                        box.className =
                            'mb-4 rounded-lg border p-4 text-sm ' +
                            (ok ? 'bg-emerald-50 text-emerald-900 border-emerald-200' : 'bg-red-50 text-red-800 border-red-200');
                        var msg = d.message != null ? String(d.message) : ok ? 'Concluído.' : 'Falha.';
                        box.innerHTML =
                            '<p class="font-semibold">' +
                            (ok ? 'Sucesso' : 'Erro') +
                            '</p><p class="mt-1">' +
                            escapeHtml(msg) +
                            '</p>';
                        if (Array.isArray(d.errors) && d.errors.length > 0) {
                            box.innerHTML += '<ul class="mt-2 list-disc list-inside text-sm">';
                            d.errors.forEach(function (errItem) {
                                box.innerHTML += '<li>' + escapeHtml(String(errItem)) + '</li>';
                            });
                            box.innerHTML += '</ul>';
                        }
                        if (d.details != null && typeof d.details === 'object' && Object.keys(d.details).length > 0) {
                            box.innerHTML +=
                                '<pre class="mt-2 text-xs overflow-x-auto max-h-64 opacity-90 whitespace-pre-wrap">' +
                                escapeHtml(JSON.stringify(d.details, null, 2)) +
                                '</pre>';
                        }
                    })
                    .catch(function (e) {
                        clearTimeout(to);
                        btn.disabled = false;
                        if (txt) txt.textContent = 'Enviar agora';
                        if (spi) spi.classList.add('hidden');
                        box.classList.remove('hidden');
                        box.className = 'mb-4 rounded-lg border p-4 text-sm bg-red-50 text-red-800 border-red-200';
                        var em =
                            e && e.name === 'AbortError'
                                ? 'Tempo esgotado (~6 min). O servidor pode ainda estar processando; confira o site/grupos ou tente de novo.'
                                : e && e.message
                                  ? e.message
                                  : 'Falha na requisição.';
                        box.innerHTML =
                            '<p class="font-semibold">Erro</p><p class="mt-1">' + escapeHtml(em) + '</p>';
                    });
            });
        })();
        </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
