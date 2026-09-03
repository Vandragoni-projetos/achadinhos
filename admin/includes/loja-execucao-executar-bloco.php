<?php
/**
 * Botão "Executar" da loja (aba Horários): autosave → API sincronizar-cron-loja.php (cron-job.org).
 * Antes do require: definir $lojaExecutarChave (ex.: ml, shopee, ml_cupons).
 */
if (!isset($lojaExecutarChave) || $lojaExecutarChave === '') {
    return;
}
$__exLoja = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $lojaExecutarChave));
?>
<div class="loja-executar-agora-wrap" data-loja="<?php echo htmlspecialchars($__exLoja, ENT_QUOTES, 'UTF-8'); ?>">
<div class="bg-white rounded-lg shadow p-6 border border-orange-100">
    <h2 class="text-xl font-bold text-gray-800 mb-2">Executar</h2>
    <p class="text-sm text-gray-600 mb-4">
        Grava alterações pendentes e <strong>cria ou atualiza o job na cron-job.org</strong> via API (sem correr a automação de ofertas aqui).
        Com <strong>horário global</strong>, sincroniza o job <strong>cron-global</strong> (<code class="rounded bg-gray-100 px-1 text-xs">rodar-tudo.php</code>).
        Com <strong>horário exclusivo</strong> e token, sincroniza o job desta loja (<code class="rounded bg-gray-100 px-1 text-xs">rodar-loja.php</code>).
        Use <strong>Salvar</strong> acima só para gravar horários na página. Exige chave API ativa em Configurações → Crons.
    </p>
    <div class="flex flex-wrap items-center gap-3">
        <button type="button"
                id="btnLojaExecutarFluxo"
                class="loja-btn-executar-fluxo bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed"
                data-loja="<?php echo htmlspecialchars($__exLoja, ENT_QUOTES, 'UTF-8'); ?>">
            <span id="btnLojaExecutarFluxoTexto" class="loja-btn-executar-texto">Executar</span>
            <span id="btnLojaExecutarFluxoSpinner" class="loja-btn-executar-spinner hidden inline-flex" aria-hidden="true">
                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </span>
        </button>
    </div>
    <div id="lojaExecutarFluxoResultado" class="loja-executar-fluxo-resultado mt-4 hidden rounded-lg p-4 text-sm" role="status"></div>
</div>
</div>
