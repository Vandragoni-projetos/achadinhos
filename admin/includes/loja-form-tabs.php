<?php
/**
 * Abas de configuração das páginas de loja (ML, Shopee, etc.).
 * Incluir dentro de: <div class="loja-tabs-root space-y-6"> logo após abrir a div.
 * Antes do include: $lojaNomeAbaPrincipal = 'Mercado Livre'; (rótulo da primeira aba; padrão: Geral).
 * Opcional: $lojaTabsTightMobile = true (só ML / ML Cupons) — no mobile, abas numa linha com espaçamento mínimo, sem mudar fonte.
 * Opcional: $lojaNomeAbaIa = 'IA' (rótulo da aba de prompt; padrão: Prompt).
 * Opcional: $lojaOcultarAbaWhatsapp = true — esconde a aba WhatsApp (ex.: ML com grupos só em Grupos).
 * Opcional: $lojaOcultarAbaHorarios = true — esconde a aba Horários (cron já nos grupos).
 * Painéis: #tab-geral (visível), #tab-ia, #tab-whatsapp, #tab-telegram, #tab-execucao (rótulo Horários; demais com .hidden).
 *
 * Dentro de #tab-execucao (após o cartão "Comportamento" da loja), incluir obrigatoriamente:
 *   require __DIR__ . '/includes/loja-tab-execucao-cron-section.php';
 * (com $lojaCronChave já definido). Esse include acrescenta o formulário de cron e o bloco único «Executar agora».
 */
$__lojaTabPrincipal = isset($lojaNomeAbaPrincipal) && trim((string) $lojaNomeAbaPrincipal) !== ''
    ? trim((string) $lojaNomeAbaPrincipal)
    : 'Geral';
$__lojaTabsTightMobile = !empty($lojaTabsTightMobile);
$__lojaNomeAbaIa = isset($lojaNomeAbaIa) && trim((string) $lojaNomeAbaIa) !== ''
    ? trim((string) $lojaNomeAbaIa)
    : 'Prompt';
$__lojaOcultarWhatsapp = !empty($lojaOcultarAbaWhatsapp);
$__lojaOcultarHorarios = !empty($lojaOcultarAbaHorarios);
$__validTabsKeys = ['geral', 'ia', 'whatsapp', 'telegram', 'execucao'];
if ($__lojaOcultarWhatsapp) {
    $__validTabsKeys = array_values(array_diff($__validTabsKeys, ['whatsapp']));
}
if ($__lojaOcultarHorarios) {
    $__validTabsKeys = array_values(array_diff($__validTabsKeys, ['execucao']));
}
$__validTabsJson = json_encode(array_fill_keys($__validTabsKeys, 1), JSON_UNESCAPED_UNICODE);
$__validTabsJsonAttr = htmlspecialchars($__validTabsJson, ENT_QUOTES, 'UTF-8');
$__lojaTabsNavClass = '-mb-px flex flex-wrap gap-x-8';
$__lojaTabsBtnPad = 'px-1';
if ($__lojaTabsTightMobile) {
    $__lojaTabsNavClass .= ' max-sm:w-full max-sm:flex-nowrap max-sm:justify-between max-sm:gap-x-2';
    $__lojaTabsBtnPad = 'px-1 max-sm:px-0.5';
}
?>
<div class="loja-tabs-nav mb-6 border-b border-gray-200">
    <nav class="<?php echo htmlspecialchars($__lojaTabsNavClass); ?>" role="tablist" aria-label="Seções da configuração">
        <button type="button" role="tab" class="loja-tab-btn loja-tab-btn--active whitespace-nowrap border-b-2 border-orange-500 py-4 <?php echo htmlspecialchars($__lojaTabsBtnPad); ?> text-sm font-medium text-orange-600 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2" data-tab="geral" aria-selected="true">
            <?php echo htmlspecialchars($__lojaTabPrincipal); ?>
        </button>
        <button type="button" role="tab" class="loja-tab-btn whitespace-nowrap border-b-2 border-transparent py-4 <?php echo htmlspecialchars($__lojaTabsBtnPad); ?> text-sm font-medium text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2" data-tab="ia" aria-selected="false">
            <?php echo htmlspecialchars($__lojaNomeAbaIa); ?>
        </button>
        <?php if (!$__lojaOcultarWhatsapp): ?>
        <button type="button" role="tab" class="loja-tab-btn whitespace-nowrap border-b-2 border-transparent py-4 <?php echo htmlspecialchars($__lojaTabsBtnPad); ?> text-sm font-medium text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2" data-tab="whatsapp" aria-selected="false">
            WhatsApp
        </button>
        <?php endif; ?>
        <button type="button" role="tab" class="loja-tab-btn whitespace-nowrap border-b-2 border-transparent py-4 <?php echo htmlspecialchars($__lojaTabsBtnPad); ?> text-sm font-medium text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2" data-tab="telegram" aria-selected="false">
            Telegram
        </button>
        <?php if (!$__lojaOcultarHorarios): ?>
        <button type="button" role="tab" class="loja-tab-btn whitespace-nowrap border-b-2 border-transparent py-4 <?php echo htmlspecialchars($__lojaTabsBtnPad); ?> text-sm font-medium text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2" data-tab="execucao" aria-selected="false">
            Horários
        </button>
        <?php endif; ?>
    </nav>
</div>
<?php
if (!defined('LOJA_FORM_TABS_JS')) {
    define('LOJA_FORM_TABS_JS', true);
    ?>
<script>
(function () {
    var activeTab = 'loja-tab-btn--active';
    var inactiveCls = ['border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300'];
    var activeCls = ['border-orange-500', 'text-orange-600'];

    function setBtnState(btn, on) {
        btn.classList.toggle(activeTab, on);
        activeCls.forEach(function (c) { btn.classList.toggle(c, on); });
        inactiveCls.forEach(function (c) { btn.classList.toggle(c, !on); });
    }

    function lojaTabsReferrerPathname() {
        try {
            if (!document.referrer) {
                return '';
            }
            return new URL(document.referrer).pathname;
        } catch (e) {
            return '';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.loja-tabs-root').forEach(function (root) {
            var meta = root.querySelector('[data-loja-valid-tabs]');
            var validTabs = { geral: 1, ia: 1, whatsapp: 1, telegram: 1, execucao: 1 };
            if (meta) {
                try {
                    validTabs = JSON.parse(meta.getAttribute('data-loja-valid-tabs') || '{}');
                } catch (e0) {}
            }
            var btns = root.querySelectorAll('.loja-tab-btn');
            var panels = [];
            for (var ci = 0; ci < root.children.length; ci++) {
                var cel = root.children[ci];
                if (cel.nodeType === 1 && cel.classList && cel.classList.contains('tab-content')) {
                    panels.push(cel);
                }
            }
            function show(tab) {
                btns.forEach(function (b) {
                    var on = b.getAttribute('data-tab') === tab;
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                    setBtnState(b, on);
                });
                panels.forEach(function (p) {
                    p.classList.toggle('hidden', p.id !== 'tab-' + tab);
                });
            }
            var path = location.pathname;
            var refPath = lojaTabsReferrerPathname();
            var sameLojaPage = refPath !== '' && refPath === path;
            var storageKey = 'lojaTab:' + path;
            var saved = '';
            try {
                saved = sessionStorage.getItem(storageKey) || '';
            } catch (e) {
                saved = '';
            }
            var firstTab = (btns[0] && btns[0].getAttribute('data-tab')) || 'geral';
            if (saved && !validTabs[saved]) {
                saved = '';
            }
            var initial = firstTab;
            if (sameLojaPage && saved && validTabs[saved]) {
                initial = saved;
            }
            btns.forEach(function (b) {
                b.addEventListener('click', function () {
                    var t = b.getAttribute('data-tab') || 'geral';
                    show(t);
                    try {
                        sessionStorage.setItem(storageKey, t);
                    } catch (e2) {}
                });
            });
            show(initial);
            try {
                sessionStorage.setItem(storageKey, initial);
            } catch (e3) {}
        });
    });
})();
</script>
    <?php
}
