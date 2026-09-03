<?php
/**
 * Bloco obrigatório da aba Crons (#tab-execucao): intro (Ativar crons) + formulário de cron por loja.
 *
 * Antes do require: definir $lojaCronChave ('ml', 'shopee', 'magalu', 'amazon', 'shein',
 * 'aliexpress', 'ml_cupons'). Opcional: $lojaCronIntroNotaExtra, $lojaCronNomeExibicao.
 */
if (!isset($lojaCronChave) || $lojaCronChave === '') {
    return;
}
require __DIR__ . '/loja-crons-tab-intro.php';
$lojaCronEmbutidoNoCard = false;
require __DIR__ . '/loja-execucao-cron-form.php';

$lojaExecutarChave = $lojaCronChave;
require __DIR__ . '/loja-execucao-executar-bloco.php';
