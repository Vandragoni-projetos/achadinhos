<?php
/**
 * Verificação seca do fluxo grupo → conta WA (Evolution vs Uazapi) sem enviar mensagens.
 * Uso: php tools/simular-fluxo-grupo-dry.php [id_grupo]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/config/functions.php';
require_once $root . '/core/db/SchemaHelper.php';

if (function_exists('garantirColunaGruposWhatsappAutomacaoLoja')) {
    garantirColunaGruposWhatsappAutomacaoLoja();
}

$pdo = getDB();
$gid = isset($argv[1]) ? (int) $argv[1] : 0;
if ($gid <= 0) {
    $st = $pdo->query('SELECT id, COALESCE(automacao_loja,\'ml\') AS loja FROM grupos_whatsapp WHERE ativo = 1 ORDER BY id ASC LIMIT 1');
    $one = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$one) {
        fwrite(STDERR, "Nenhum grupo ativo. Crie um grupo ou passe: php tools/simular-fluxo-grupo-dry.php ID\n");
        exit(1);
    }
    $gid = (int) $one['id'];
    echo "Usando primeiro grupo ativo id={$gid} loja=" . ($one['loja'] ?? '') . "\n";
}

$sqlJoin = 'SELECT g.id, g.nome, g.grupo_id, g.ativo, COALESCE(g.automacao_loja,\'ml\') AS automacao_loja, g.evolution_conta_id,
    e.url_base, e.instancia, e.api_key, COALESCE(e.provedor,\'evolution\') AS provedor, e.uazapi_admin_token, COALESCE(e.api_propria,0) AS api_propria
    FROM grupos_whatsapp g
    LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
    WHERE g.id = ? LIMIT 1';
try {
    $st = $pdo->prepare($sqlJoin);
    $st->execute([$gid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $st = $pdo->prepare('SELECT g.id, g.nome, g.grupo_id, g.ativo, COALESCE(g.automacao_loja,\'ml\') AS automacao_loja, g.evolution_conta_id,
        e.url_base, e.instancia, e.api_key, COALESCE(e.provedor,\'evolution\') AS provedor, e.uazapi_admin_token
        FROM grupos_whatsapp g
        LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
        WHERE g.id = ? LIMIT 1');
    $st->execute([$gid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$row) {
    fwrite(STDERR, "Grupo {$gid} não encontrado.\n");
    exit(1);
}

$loja = function_exists('gruposNormalizarAutomacaoLoja')
    ? (gruposNormalizarAutomacaoLoja((string) ($row['automacao_loja'] ?? 'ml')) ?? 'INVALID')
    : (string) ($row['automacao_loja'] ?? 'ml');

$evo = [
    'url_base' => rtrim((string) ($row['url_base'] ?? ''), '/'),
    'instancia' => (string) ($row['instancia'] ?? ''),
    'api_key' => (string) ($row['api_key'] ?? ''),
    'provedor' => (string) ($row['provedor'] ?? 'evolution'),
    'uazapi_admin_token' => (string) ($row['uazapi_admin_token'] ?? ''),
    'api_propria' => (int) ($row['api_propria'] ?? 0),
];

echo "Grupo #{$gid} \"{$row['nome']}\" | loja={$loja} | ativo=" . ((int) ($row['ativo'] ?? 0)) . "\n";
echo "Conta evolution_conta_id=" . (int) ($row['evolution_conta_id'] ?? 0) . " | provedor={$evo['provedor']}\n";
echo "URL base: " . ($evo['url_base'] !== '' ? '(preenchida)' : '(vazia)') . " | API key/token: " . ($evo['api_key'] !== '' ? '(preenchido)' : '(vazio)') . "\n";
if ($evo['provedor'] === 'uazapi') {
    echo "Uazapi: instância Evolution-style pode estar vazia; envio usa token em api_key.\n";
}

$map = [
    'ml' => 'runAutomacaoML',
    'shopee' => 'runAutomacaoShopee',
    'magalu' => 'runAutomacaoMagalu',
    'amazon' => 'runAutomacaoAmazon',
    'shein' => 'runAutomacaoShein',
    'aliexpress' => 'runAutomacaoAliExpress',
    'ml_cupons' => 'runAutomacaoCuponsML',
];
$fn = $map[$loja] ?? null;
if ($fn === null) {
    echo "Loja não mapeada para simulação de função.\n";
    exit(0);
}

echo "Função esperada ao correr rodar-grupo: {$fn}(\$forcar=true, \$grupoId={$gid})\n";
echo "Sem chamada real (dry): configure cron/token e use GET cron/rodar-grupo.php?grupo={$gid}&token=... para execução completa.\n";
