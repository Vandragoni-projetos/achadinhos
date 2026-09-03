<?php
/**
 * Status Evolution da conta vinculada à loja.
 * Antes do include: $lojaEvolutionContaId (int), $lojaEvolutionPainelPrefix (string, ex.: ml).
 * Opcional: $lojaEvolutionLojaKey (string, ex.: ml) — data-loja-key para atualizar o bloco após autosave.
 */
if (!isset($lojaEvolutionPainelPrefix)) {
    $lojaEvolutionPainelPrefix = 'loja';
}
$lojaEvolutionLojaKey = isset($lojaEvolutionLojaKey) ? preg_replace('/[^a-z0-9_]/', '', (string) $lojaEvolutionLojaKey) : '';
$lojaEvolutionContaId = isset($lojaEvolutionContaId) ? (int) $lojaEvolutionContaId : 0;
$evoLinha = null;
if ($lojaEvolutionContaId > 0) {
    try {
        $pdoEvo = getDB();
        $st = $pdoEvo->prepare('SELECT * FROM evolution_contas WHERE id = ? AND ativo = 1 LIMIT 1');
        $st->execute([$lojaEvolutionContaId]);
        $evoLinha = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $evoLinha = null;
    }
}
$evoEstado = $evoLinha
    ? whatsAppObterEstadoConta($evoLinha)
    : ['ok' => false, 'connected' => false, 'state' => '', 'instancia' => ''];
$prefix = preg_replace('/[^a-z0-9_]/', '_', $lojaEvolutionPainelPrefix);
$dataLojaKey = $lojaEvolutionLojaKey !== '' ? htmlspecialchars($lojaEvolutionLojaKey, ENT_QUOTES, 'UTF-8') : '';
?>
<div id="lojaEvolutionMount_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>"
     class="loja-evolution-status-mount"
     <?php if ($dataLojaKey !== ''): ?>data-loja-key="<?php echo $dataLojaKey; ?>" <?php endif; ?>
     data-loja-evolution-prefix="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="bg-white rounded-lg border border-gray-100 shadow-sm p-4 mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">WhatsApp</h2>
                    <p class="text-xs text-gray-500 mt-1 mb-3 leading-snug">Somente leitura nesta loja. Contas em <strong class="text-gray-600">Configurações → WhatsApp</strong> (Evolution ou Uazapi).</p>
                    <?php if (!$evoLinha): ?>
                    <p class="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-2.5 py-2">Nenhuma conta WhatsApp selecionada ou conta inativa.</p>
                    <?php else: ?>
                    <div class="rounded-md border border-gray-100 bg-slate-50/70 px-3 py-2.5">
                        <dl class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-4 text-sm items-start">
                            <div class="min-w-0 md:col-span-3">
                                <dt class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Conta</dt>
                                <dd class="mt-0.5 font-semibold text-gray-900 truncate" title="<?php echo htmlspecialchars($evoLinha['nome'] ?? ''); ?>"><?php echo htmlspecialchars($evoLinha['nome'] ?? ''); ?></dd>
                            </div>
                            <div class="min-w-0 md:col-span-3">
                                <dt class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Instância</dt>
                                <dd class="mt-0.5 font-mono text-xs text-gray-800 truncate" title="<?php echo htmlspecialchars($evoLinha['instancia'] ?? ''); ?>"><?php echo htmlspecialchars($evoLinha['instancia'] ?? ''); ?></dd>
                            </div>
                            <div class="min-w-0 md:col-span-6">
                                <dt class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Status</dt>
                                <dd class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-2">
                                <?php if ($evoEstado['ok'] && $evoEstado['connected']): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700"><span class="shrink-0 w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Conectado</span>
                                <?php elseif ($evoEstado['ok']): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-red-700"><span class="shrink-0 w-1.5 h-1.5 rounded-full bg-red-500"></span>Desconectado<?php echo $evoEstado['state'] !== '' ? ' (' . htmlspecialchars($evoEstado['state']) . ')' : ''; ?></span>
                                <?php else: ?>
                                <span class="inline-block text-xs text-amber-900 bg-amber-100/80 border border-amber-200/80 rounded px-2 py-1 leading-snug">Não foi possível consultar a API (verifique URL / instância).</span>
                                <?php endif; ?>
                                    <button type="button" id="btnEvoTeste_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="shrink-0 px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white text-xs font-medium rounded-md transition-colors">
                                        Testar conexão
                                    </button>
                                    <span id="evoTesteMsg_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>" class="text-xs text-gray-600 min-w-0"></span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div id="evoTesteModal_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
                        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-2">Testar WhatsApp</h3>
                            <p class="text-sm text-gray-600 mb-4">Informe o número com DDD (será enviada uma mensagem de teste pela conta WhatsApp desta loja).</p>
                            <input type="hidden" id="evoTesteContaId_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo (int) $lojaEvolutionContaId; ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Número (WhatsApp)</label>
                            <input type="tel" id="evoTesteNumero_<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg mb-4" placeholder="5511999998888">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="evo-teste-fechar px-4 py-2 text-gray-700 bg-gray-100 rounded-lg text-sm" data-prefix="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>">Cancelar</button>
                                <button type="button" class="evo-teste-enviar px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg text-sm font-medium transition-colors" data-prefix="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>">Enviar teste</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
</div>
