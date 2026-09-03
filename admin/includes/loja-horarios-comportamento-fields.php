<?php
/**
 * Três campos padronizados (aba Horários → Comportamento): produtos/execução, delay, janela anti-repetição.
 *
 * Antes do require: $lojaHorariosPrefix (ex.: 'ml', 'shopee', 'ml_cupons'), valores em
 * $lojaHorariosProdutos, $lojaHorariosDelay, $lojaHorariosDias (string numérica, dias 0–365).
 */
if (!isset($lojaHorariosPrefix) || $lojaHorariosPrefix === '') {
    return;
}
$pfx = preg_replace('/[^a-z0-9_]/', '', (string) $lojaHorariosPrefix);
$vProd = isset($lojaHorariosProdutos) ? (string) $lojaHorariosProdutos : '1';
$vDelay = isset($lojaHorariosDelay) ? (string) $lojaHorariosDelay : '10';
$vDias = isset($lojaHorariosDias) ? (string) $lojaHorariosDias : '1';
$fidProd = $pfx . '_produtos_por_execucao';
$fidDelay = $pfx . '_delay_entre_envios';
$fidDias = $pfx . '_dias_evitar_repetir';
?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-1 items-end">
                        <div class="min-w-0">
                            <label for="<?php echo htmlspecialchars($fidProd); ?>" class="block text-xs font-medium text-gray-600 mb-1">Produtos por execução</label>
                            <input type="number" id="<?php echo htmlspecialchars($fidProd); ?>" name="<?php echo htmlspecialchars($fidProd); ?>" min="1" max="10" step="1" inputmode="numeric"
                                   value="<?php echo htmlspecialchars($vProd); ?>"
                                   class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="min-w-0">
                            <label for="<?php echo htmlspecialchars($fidDelay); ?>" class="block text-xs font-medium text-gray-600 mb-1">Delay entre envios (s)</label>
                            <input type="number" id="<?php echo htmlspecialchars($fidDelay); ?>" name="<?php echo htmlspecialchars($fidDelay); ?>" min="1" max="120" step="1" inputmode="numeric"
                                   value="<?php echo htmlspecialchars($vDelay); ?>"
                                   class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="min-w-0 lg:col-span-1">
                            <label for="<?php echo htmlspecialchars($fidDias); ?>" class="block text-xs font-medium text-gray-600 mb-1">Não reenviar o mesmo produto antes de (dias)</label>
                            <input type="number" id="<?php echo htmlspecialchars($fidDias); ?>" name="<?php echo htmlspecialchars($fidDias); ?>" min="0" max="365" step="1" inputmode="numeric"
                                   value="<?php echo htmlspecialchars($vDias); ?>"
                                   class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"
                                   title="0 = nunca repetir (considera todo o histórico); 1 = pode repetir no dia seguinte">
                        </div>
                    </div>
                    <?php if ($pfx === 'ml'): ?>
                    <p class="text-xs text-gray-500 mb-2">Mercado Livre (ofertas): o mesmo link de produto não é enviado de novo após já constar em <code class="text-[11px] bg-gray-100 px-1 rounded">produtos_ja_publicados</code> (checagem permanente, URL normalizada). O campo “dias” acima não altera esse comportamento nesta automação.</p>
                    <?php else: ?>
                    <p class="text-xs text-gray-500 mb-2">Só considera “já enviado” os envios desse período. Assim a lista não esgota: use 1 dia para repetir no dia seguinte, 30 para não repetir no mês, ou 0 para nunca repetir o mesmo produto (histórico completo).</p>
                    <?php endif; ?>
