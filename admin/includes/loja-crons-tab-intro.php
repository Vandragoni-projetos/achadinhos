<?php
/**
 * Ativação de crons: escolha horário global vs exclusivo (aba Horários das lojas).
 * Antes do include: definir $lojaCronChave.
 * Opcional: $lojaCronNomeExibicao, $lojaNomeAbaPrincipal, $lojaCronIntroNotaExtra (HTML).
 */
if (!isset($lojaCronChave) || $lojaCronChave === '') {
    return;
}
if (!function_exists('dadosCronLoja')) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/functions.php';
}

$__mapTitulo = [
    'ml' => 'Mercado Livre',
    'shopee' => 'Shopee',
    'magalu' => 'Magalu',
    'aliexpress' => 'AliExpress',
    'amazon' => 'Amazon',
    'shein' => 'Shein',
    'ml_cupons' => 'ML Cupons',
];
$__nomeLojaCron = isset($lojaCronNomeExibicao) ? trim((string) $lojaCronNomeExibicao) : '';
if ($__nomeLojaCron === '' && isset($lojaNomeAbaPrincipal)) {
    $__nomeLojaCron = trim((string) $lojaNomeAbaPrincipal);
}
if ($__nomeLojaCron === '') {
    $__nomeLojaCron = $__mapTitulo[$lojaCronChave] ?? $lojaCronChave;
}

$__lcPfx = preg_replace('/[^a-z0-9_]/', '_', $lojaCronChave);
$__cronModo = dadosCronLoja($lojaCronChave);
$__cronIndivAtivo = !empty($__cronModo['cron_individual_ativo']);
$__radioName = 'cron_modo_loja_' . $__lcPfx;
?>
                <input type="hidden" name="cron_painel_presente" value="1">
                <input type="hidden" name="cron_individual_ativo" id="cron_individual_ativo_<?php echo htmlspecialchars($__lcPfx); ?>" value="<?php echo $__cronIndivAtivo ? '1' : '0'; ?>">
                <div class="bg-white rounded-lg shadow p-6 border border-gray-100 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">Ativar horários automáticos</h3>
                    <p class="text-sm text-gray-600 mb-4">Esta loja usa</p>

                    <div class="space-y-3 mb-4">
                        <label id="cron_card_global_<?php echo htmlspecialchars($__lcPfx); ?>"
                               for="cron_modo_global_<?php echo htmlspecialchars($__lcPfx); ?>"
                               class="flex cursor-pointer items-start gap-3 rounded-lg border-2 p-4 transition-colors <?php echo $__cronIndivAtivo ? 'border-gray-200 bg-white hover:bg-gray-50/80' : 'border-orange-400 bg-orange-50/70 ring-2 ring-orange-200/80'; ?>">
                            <input type="radio"
                                   name="<?php echo htmlspecialchars($__radioName); ?>"
                                   id="cron_modo_global_<?php echo htmlspecialchars($__lcPfx); ?>"
                                   value="0"
                                   class="cron-modo-radio mt-0.5 h-4 w-4 shrink-0 border-gray-300 text-orange-600 focus:ring-orange-500"
                                   <?php echo $__cronIndivAtivo ? '' : 'checked'; ?>
                                   aria-describedby="cron_modo_global_help_<?php echo htmlspecialchars($__lcPfx); ?>">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-gray-900">Horário global do site</span>
                                <span id="cron_modo_global_help_<?php echo htmlspecialchars($__lcPfx); ?>" class="mt-1 block text-sm text-gray-600">
                                    Usa o job global definido em <a href="configuracoes.php?tab=crons" class="font-medium text-orange-600 hover:underline">Configurações → Crons</a>
                                    (<code class="rounded bg-gray-100 px-1 text-xs">rodar-tudo.php</code>). Os horários de envio por grupo continuam em <strong>Grupos</strong>.
                                </span>
                            </span>
                        </label>

                        <label id="cron_card_indiv_<?php echo htmlspecialchars($__lcPfx); ?>"
                               for="cron_modo_indiv_<?php echo htmlspecialchars($__lcPfx); ?>"
                               class="flex cursor-pointer items-start gap-3 rounded-lg border-2 p-4 transition-colors <?php echo $__cronIndivAtivo ? 'border-orange-400 bg-orange-50/70 ring-2 ring-orange-200/80' : 'border-gray-200 bg-white hover:bg-gray-50/80'; ?>">
                            <input type="radio"
                                   name="<?php echo htmlspecialchars($__radioName); ?>"
                                   id="cron_modo_indiv_<?php echo htmlspecialchars($__lcPfx); ?>"
                                   value="1"
                                   class="cron-modo-radio mt-0.5 h-4 w-4 shrink-0 border-gray-300 text-orange-600 focus:ring-orange-500"
                                   <?php echo $__cronIndivAtivo ? 'checked' : ''; ?>
                                   aria-describedby="cron_modo_indiv_help_<?php echo htmlspecialchars($__lcPfx); ?>">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-gray-900">Horário exclusivo — <?php echo htmlspecialchars($__nomeLojaCron); ?></span>
                                <span id="cron_modo_indiv_help_<?php echo htmlspecialchars($__lcPfx); ?>" class="mt-1 block text-sm text-gray-600">
                                    Esta loja usa o seu próprio agendamento e URL <code class="rounded bg-gray-100 px-1 text-xs">rodar-loja.php</code> na cron-job.org.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-xs text-gray-700">
                        Escolha se esta loja segue o <strong class="text-orange-700">cron global</strong> em
                        <a href="configuracoes.php?tab=crons" class="font-medium text-orange-600 hover:underline">Configurações → Crons</a>
                        ou se usa <strong class="text-orange-700">agendamento exclusivo</strong> para esta loja.
                    </div>

                    <?php if (isset($lojaCronIntroNotaExtra) && $lojaCronIntroNotaExtra !== ''): ?>
                    <p class="mt-4 text-xs text-gray-600 cron-extra-nota-<?php echo htmlspecialchars($__lcPfx); ?> <?php echo $__cronIndivAtivo ? '' : 'hidden'; ?>"><?php echo $lojaCronIntroNotaExtra; ?></p>
                    <?php endif; ?>
                </div>
