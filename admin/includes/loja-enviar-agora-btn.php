<?php
/**
 * Coluna direita: botão "Enviar agora" + feedback autosave (como ML).
 * Defina antes: $lojaEnviarAgora = ['api' => 'api/shopee-enviar-agora.php', 'prefix' => 'shopee'];
 */
if (!isset($lojaEnviarAgora) || !is_array($lojaEnviarAgora)) {
    return;
}
$prefix = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($lojaEnviarAgora['prefix'] ?? '')));
if ($prefix === '') {
    return;
}
?>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>BtnEnviarAgora"
                            class="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>EnviarAgoraTexto">Enviar agora</span>
                        <span id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>EnviarAgoraSpinner" class="hidden h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true"></span>
                    </button>
                    <span id="lojaAutosaveFeedback" class="text-sm font-medium text-gray-500 hidden shrink-0" aria-live="polite"></span>
                </div>
