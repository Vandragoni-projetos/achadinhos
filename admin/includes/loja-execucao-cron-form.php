<?php
/**
 * Bloco "Configurar horários" (aba Horários das lojas): mesmo padrão visual que Configurações → Horários.
 */
if (!isset($lojaCronChave) || $lojaCronChave === '') {
    return;
}
if (!function_exists('dadosCronLoja')) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/functions.php';
}

$__cronEmbutido = !empty($lojaCronEmbutidoNoCard);

$__mapTitulo = [
    'ml' => 'Mercado Livre',
    'shopee' => 'Shopee',
    'magalu' => 'Magalu',
    'aliexpress' => 'AliExpress',
    'amazon' => 'Amazon',
    'shein' => 'Shein',
    'ml_cupons' => 'ML Cupons',
];
$__cronTitulo = isset($lojaCronNomeExibicao) ? trim((string) $lojaCronNomeExibicao) : '';
if ($__cronTitulo === '' && isset($lojaNomeAbaPrincipal)) {
    $__cronTitulo = trim((string) $lojaNomeAbaPrincipal);
}
if ($__cronTitulo === '') {
    $__cronTitulo = $__mapTitulo[$lojaCronChave] ?? $lojaCronChave;
}

$__cron = dadosCronLoja($lojaCronChave);
$__pfx = preg_replace('/[^a-z0-9_]/', '_', $lojaCronChave);
$__ind = !empty($__cron['cron_individual_ativo']);
$__ivSel = CronPolicy::normalizeInterval((int) $__cron['intervalo_minutos']);

$__gIv = CronPolicy::normalizeInterval((int) getConfig('cron_intervalo_minutos', '5'));
$__gH1 = max(0, min(23, (int) getConfig('cron_hora_inicio', '8')));
$__gH2 = max(0, min(23, (int) getConfig('cron_hora_fim', '22')));
$__gDias = max(1, min(365, (int) getConfig('produtos_dias_expiracao', '30')));
$__gTok = trim((string) getConfig('cron_token', ''));

$__dispTok = $__ind ? $__cron['token'] : '';
$__dispIv = $__ind ? $__ivSel : $__gIv;
$__dispH1 = $__ind ? (int) $__cron['hora_inicio'] : $__gH1;
$__dispH2 = $__ind ? (int) $__cron['hora_fim'] : $__gH2;
$__dispDias = $__ind ? (int) $__cron['dias_remocao'] : $__gDias;

$__snap = [
    'global' => [
        'token' => '',
        'cronToken' => $__gTok,
        'iv' => $__gIv,
        'h1' => $__gH1,
        'h2' => $__gH2,
        'dias' => $__gDias,
    ],
    'individual' => [
        'token' => $__cron['token'],
        'iv' => $__ivSel,
        'h1' => (int) $__cron['hora_inicio'],
        'h2' => (int) $__cron['hora_fim'],
        'dias' => (int) $__cron['dias_remocao'],
    ],
];
$__jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $__jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$__snapEncoded = json_encode($__snap, $__jsonFlags);
if ($__snapEncoded === false) {
    $__snapEncoded = '{"global":{"token":"","cronToken":"","iv":5,"h1":8,"h2":22,"dias":30},"individual":{"token":"","iv":5,"h1":0,"h2":23,"dias":30}}';
}
$__snapJson = htmlspecialchars($__snapEncoded, ENT_QUOTES, 'UTF-8');

?>
                <div id="cron_loja_editor_wrap_<?php echo htmlspecialchars($__pfx); ?>" class="">
                <div class="space-y-4 <?php echo $__cronEmbutido ? '' : 'mb-6'; ?>">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Horário da loja</h3>
                        <p id="cron_loja_lock_hint_<?php echo htmlspecialchars($__pfx); ?>" class="mt-1 text-sm text-amber-800 <?php echo $__ind ? 'hidden' : ''; ?>">Modo <strong>horário global</strong>: valores abaixo espelham a configuração do site (somente leitura). Ative «Horário exclusivo» acima para editar.</p>
                        <p id="cron_loja_unlock_hint_<?php echo htmlspecialchars($__pfx); ?>" class="mt-1 text-sm text-gray-600 <?php echo $__ind ? '' : 'hidden'; ?>">Estes valores aplicam-se apenas a esta loja quando o cron individual está ativo.</p>
                    </div>

                    <div id="cron_loja_detalhe_<?php echo htmlspecialchars($__pfx); ?>"
                         class="<?php echo $__cronEmbutido ? '' : 'bg-white rounded-lg shadow p-6 border border-gray-100'; ?>"
                         data-loja="<?php echo htmlspecialchars($lojaCronChave); ?>"
                         data-cron-interval-max="<?php echo (int) CronPolicy::intervalMaxMinutes(); ?>"
                         data-cron-snapshot="<?php echo $__snapJson; ?>">

                        <h2 id="cron_form_h2_<?php echo htmlspecialchars($__pfx); ?>" class="text-xl font-bold text-gray-800 mb-4">Horário — <?php echo htmlspecialchars($__cronTitulo); ?></h2>

                        <p class="text-sm text-gray-600 mb-6">Defina em quais horários esta loja deve executar automaticamente as postagens e atualizações.</p>

                        <input type="hidden" id="cron_token_<?php echo htmlspecialchars($__pfx); ?>" name="cron_token" autocomplete="off"
                               value="<?php echo htmlspecialchars($__dispTok); ?>"
                               class="cron-live-field">

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                            <div>
                                <label for="cron_intervalo_<?php echo htmlspecialchars($__pfx); ?>" class="block text-sm font-medium text-gray-700 mb-2">Intervalo (minutos)</label>
                                <input type="number" id="cron_intervalo_<?php echo htmlspecialchars($__pfx); ?>" name="cron_intervalo_minutos" min="1" max="<?php echo (int) CronPolicy::intervalMaxMinutes(); ?>"
                                       value="<?php echo (int) $__dispIv; ?>"
                                       class="cron-live-field w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500">
                                <p class="mt-1 text-xs text-gray-500">1–59 min Ou 60, 120, 180, 240, 360, 480 ou <?php echo (int) CronPolicy::intervalMaxMinutes(); ?></p>
                            </div>
                            <div>
                                <label for="cron_inicio_<?php echo htmlspecialchars($__pfx); ?>" class="block text-sm font-medium text-gray-700 mb-2">Hora início (0–23)</label>
                                <input type="number" id="cron_inicio_<?php echo htmlspecialchars($__pfx); ?>" name="cron_hora_inicio" min="0" max="23"
                                       value="<?php echo (int) $__dispH1; ?>"
                                       class="cron-live-field w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div>
                                <label for="cron_fim_<?php echo htmlspecialchars($__pfx); ?>" class="block text-sm font-medium text-gray-700 mb-2">Hora fim (0–23)</label>
                                <input type="number" id="cron_fim_<?php echo htmlspecialchars($__pfx); ?>" name="cron_hora_fim" min="0" max="23"
                                       value="<?php echo (int) $__dispH2; ?>"
                                       class="cron-live-field w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500">
                                <p class="mt-1 text-xs text-gray-500">Janela de execução (ex: 8–22)</p>
                            </div>
                            <div>
                                <label for="cron_remocao_<?php echo htmlspecialchars($__pfx); ?>" class="block text-sm font-medium text-gray-700 mb-2">Dias para remover produtos</label>
                                <input type="number" id="cron_remocao_<?php echo htmlspecialchars($__pfx); ?>" name="cron_dias_remocao" min="1" max="365"
                                       value="<?php echo (int) $__dispDias; ?>"
                                       class="cron-live-field w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500">
                                <p class="mt-1 text-xs text-gray-500">Remover produtos criados há mais de X dias</p>
                            </div>
                        </div>

                        <input type="hidden" name="cron_job_id" id="cron_job_id_<?php echo htmlspecialchars($__pfx); ?>" value="<?php echo htmlspecialchars($__cron['cron_job_id']); ?>">

                        <p id="cron_job_row_<?php echo htmlspecialchars($__pfx); ?>" class="mt-3 text-xs text-gray-600 <?php echo ($__cron['cron_job_id'] !== '' && $__ind) ? '' : 'hidden'; ?>">
                            Job sincronizado na cron-job.org: <code class="bg-gray-100 px-1 rounded"><?php echo htmlspecialchars($__cron['cron_job_id']); ?></code>
                        </p>

                        <p class="mt-4 text-xs text-gray-500">Executado automaticamente conforme os horários definidos: automação de <strong><?php echo htmlspecialchars($__cronTitulo); ?></strong> e limpeza de produtos antigos (dias acima).</p>

                        <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                            <button type="button"
                                    class="loja-btn-salvar-horarios-cron shrink-0 rounded-lg border-2 border-orange-500 bg-white px-5 py-2 text-sm font-bold text-orange-600 shadow-sm transition-colors hover:bg-orange-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500"
                                    data-loja="<?php echo htmlspecialchars($lojaCronChave, ENT_QUOTES, 'UTF-8'); ?>">
                                Salvar
                            </button>
                            <span class="loja-salvar-horarios-feedback hidden text-sm font-medium" aria-live="polite"></span>
                        </div>
                    </div>
                </div>
                </div>
                <script>
                (function () {
                    var pfx = <?php echo json_encode($__pfx); ?>;
                    var wrap = document.getElementById('cron_loja_editor_wrap_' + pfx);
                    var root = document.getElementById('cron_loja_detalhe_' + pfx);
                    if (!root || !wrap) return;
                    var snapRaw = root.getAttribute('data-cron-snapshot');
                    var snap = {};
                    try { snap = snapRaw ? JSON.parse(snapRaw) : {}; } catch (e) { snap = {}; }
                    var modoGlobal = document.getElementById('cron_modo_global_' + pfx);
                    var modoIndiv = document.getElementById('cron_modo_indiv_' + pfx);
                    var modoCb = document.getElementById('cron_modo_' + pfx);
                    var modoHidden = document.getElementById('cron_individual_ativo_' + pfx);
                    var cardGlobal = document.getElementById('cron_card_global_' + pfx);
                    var cardIndiv = document.getElementById('cron_card_indiv_' + pfx);
                    var lockHint = document.getElementById('cron_loja_lock_hint_' + pfx);
                    var unlockHint = document.getElementById('cron_loja_unlock_hint_' + pfx);
                    var jobRow = document.getElementById('cron_job_row_' + pfx);
                    var jobIdText = jobRow ? jobRow.querySelector('code') : null;
                    var jobHidden = document.getElementById('cron_job_id_' + pfx);
                    var extraNota = document.querySelector('.cron-extra-nota-' + pfx);

                    function setFields(br) {
                        var b = snap[br] || {};
                        var tok = root.querySelector('[name="cron_token"]');
                        var iv = root.querySelector('[name="cron_intervalo_minutos"]');
                        var h1 = root.querySelector('[name="cron_hora_inicio"]');
                        var h2 = root.querySelector('[name="cron_hora_fim"]');
                        var di = root.querySelector('[name="cron_dias_remocao"]');
                        if (tok) tok.value = b.token != null ? String(b.token) : '';
                        if (iv) iv.value = String(b.iv != null ? b.iv : 5);
                        if (h1) h1.value = String(b.h1 != null ? b.h1 : 8);
                        if (h2) h2.value = String(b.h2 != null ? b.h2 : 22);
                        if (di) di.value = String(b.dias != null ? b.dias : 30);
                    }

                    function isModoIndividual() {
                        if (modoIndiv && modoGlobal) return !!modoIndiv.checked;
                        if (modoCb) return !!modoCb.checked;
                        if (modoHidden) return modoHidden.value === '1';
                        return false;
                    }

                    function syncHiddenFromModoUi() {
                        if (modoHidden && modoIndiv && modoGlobal) {
                            modoHidden.value = modoIndiv.checked ? '1' : '0';
                        } else if (modoHidden && modoCb) {
                            modoHidden.value = modoCb.checked ? '1' : '0';
                        }
                    }

                    function updateCronModoCards(individualOn) {
                        var selG = 'border-orange-400 bg-orange-50/70 ring-2 ring-orange-200/80';
                        var idleG = 'border-gray-200 bg-white hover:bg-gray-50/80';
                        if (cardGlobal) {
                            cardGlobal.className = 'flex cursor-pointer items-start gap-3 rounded-lg border-2 p-4 transition-colors ' + (individualOn ? idleG : selG);
                        }
                        if (cardIndiv) {
                            cardIndiv.className = 'flex cursor-pointer items-start gap-3 rounded-lg border-2 p-4 transition-colors ' + (individualOn ? selG : idleG);
                        }
                    }

                    function getJobIdVisivel() {
                        if (!jobHidden || !isModoIndividual()) return '';
                        return String(jobHidden.value || '').trim();
                    }

                    function atualizarLinhaJobId() {
                        if (!jobRow) return;
                        var jid = getJobIdVisivel();
                        jobRow.classList.toggle('hidden', !jid);
                        if (jobIdText && jid) jobIdText.textContent = jid;
                    }

                    function setModo(individualOn) {
                        if (modoIndiv && modoGlobal) {
                            modoIndiv.checked = !!individualOn;
                            modoGlobal.checked = !individualOn;
                        } else if (modoCb) {
                            modoCb.checked = !!individualOn;
                        }
                        syncHiddenFromModoUi();
                        updateCronModoCards(!!individualOn);
                        if (extraNota) extraNota.classList.toggle('hidden', !individualOn);
                        if (lockHint) lockHint.classList.toggle('hidden', !!individualOn);
                        if (unlockHint) unlockHint.classList.toggle('hidden', !individualOn);

                        setFields(individualOn ? 'individual' : 'global');

                        root.querySelectorAll('.cron-live-field').forEach(function (el) {
                            el.disabled = !individualOn;
                            el.classList.toggle('bg-gray-100', !individualOn);
                            el.classList.toggle('text-gray-600', !individualOn);
                            el.classList.toggle('cursor-not-allowed', !individualOn);
                        });
                        if (jobHidden) jobHidden.disabled = !individualOn;

                        atualizarLinhaJobId();
                    }

                    function bindModoChange() {
                        setModo(isModoIndividual());
                    }
                    if (modoIndiv && modoGlobal) {
                        modoIndiv.addEventListener('change', bindModoChange);
                        modoGlobal.addEventListener('change', bindModoChange);
                    } else if (modoCb) {
                        modoCb.addEventListener('change', function () {
                            syncHiddenFromModoUi();
                            setModo(!!modoCb.checked);
                        });
                    }

                    window.lojaCronForcarModoGlobal = window.lojaCronForcarModoGlobal || {};
                    window.lojaCronForcarModoGlobal[<?php echo json_encode($lojaCronChave); ?>] = function () {
                        setModo(false);
                    };

                    window.lojaCronRegistrarAtualizacaoJobId = window.lojaCronRegistrarAtualizacaoJobId || {};
                    window.lojaCronRegistrarAtualizacaoJobId[<?php echo json_encode($lojaCronChave); ?>] = function (jobId) {
                        if (!jobHidden || !isModoIndividual()) return;
                        jobId = String(jobId || '').trim();
                        if (!jobId) return;
                        jobHidden.value = jobId;
                        jobHidden.disabled = false;
                        atualizarLinhaJobId();
                    };

                    var initialIndiv = modoHidden
                        ? modoHidden.value === '1'
                        : (modoIndiv && modoIndiv.checked) || (modoCb && modoCb.checked);
                    setModo(!!initialIndiv);
                })();
                </script>
