<?php
/**
 * Corrige produtos com preços errados:
 * - Preço original muito alto (ex.: 74738 em vez de 747,38)
 * - Preço total = valor da parcela (ex.: notebook R$ 2,48 em vez de R$ 2.478)
 * - Parcelas incompletas (só um dos dois campos preenchidos)
 *
 * Uso:
 *   CLI:  php cron/corrigir-precos.php
 *   Web:  /cron/corrigir-precos.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$pdo = getDB();

echo "=== Corrigindo preços de produtos ===\n\n";

// Buscar todos os produtos
$produtos = $pdo->query("SELECT id, nome, preco, preco_original, desconto, parcelas, preco_parcela FROM produtos")->fetchAll(PDO::FETCH_ASSOC);

$corrigidos = 0;
$parcelasLimpas = 0;
$precoTotalCorrigidos = 0;

foreach ($produtos as $p) {
    $mudou = false;
    
    $preco = (float)$p['preco'];
    $preco_original = $p['preco_original'] ? (float)$p['preco_original'] : null;
    $desconto = (int)$p['desconto'];
    $parcelas = $p['parcelas'] ? (int)$p['parcelas'] : null;
    $preco_parcela = $p['preco_parcela'] ? (float)$p['preco_parcela'] : null;
    
    // 0. Corrigir preço total quando está como valor da parcela (ex.: R$ 77,26 em vez de R$ 927,12)
    if ($parcelas && $preco_parcela > 0 && $preco <= $preco_parcela) {
        $preco = round($preco_parcela * $parcelas, 2);
        $mudou = true;
        $precoTotalCorrigidos++;
        echo "ID {$p['id']}: Preco total corrigido (era valor da parcela) -> R$ " . number_format($preco, 2, ',', '.') . "\n";
    }
    
    // 1. Corrigir preco_original muito alto (ex.: 74738 vs 74.70)
    if ($preco > 0 && $preco_original !== null && $preco_original > 10 * $preco) {
        // Tentar corrigir dividindo por 100
        $poCorrigido = $preco_original / 100;
        if ($poCorrigido > $preco && $poCorrigido < 10 * $preco) {
            $preco_original = $poCorrigido;
            $desconto = calcularDesconto($preco_original, $preco);
            $mudou = true;
            echo "ID {$p['id']}: Preco original corrigido de {$p['preco_original']} para {$preco_original}\n";
        } else {
            // Não conseguiu corrigir, remover
            $preco_original = null;
            $desconto = 0;
            $mudou = true;
            echo "ID {$p['id']}: Preco original removido (era {$p['preco_original']}, muito alto)\n";
        }
    }
    
    // 2. Verificar se desconto > 95% (provavelmente erro)
    if ($desconto > 95 && $preco_original !== null) {
        $preco_original = null;
        $desconto = 0;
        $mudou = true;
        echo "ID {$p['id']}: Desconto era > 95%, removendo preco_original\n";
    }
    
    // 3. Limpar parcelas incompletas
    if (($parcelas !== null && $preco_parcela === null) || ($parcelas === null && $preco_parcela !== null)) {
        $parcelas = null;
        $preco_parcela = null;
        $parcelasLimpas++;
        $mudou = true;
        echo "ID {$p['id']}: Parcelas incompletas limpas\n";
    }
    
    // Atualizar se mudou
    if ($mudou) {
        $stmt = $pdo->prepare("
            UPDATE produtos 
            SET preco = ?, preco_original = ?, desconto = ?, parcelas = ?, preco_parcela = ?
            WHERE id = ?
        ");
        $stmt->execute([$preco, $preco_original, $desconto, $parcelas, $preco_parcela, $p['id']]);
        $corrigidos++;
    }
}

echo "\n=== Resultado ===\n";
echo "Produtos analisados: " . count($produtos) . "\n";
echo "Produtos corrigidos: {$corrigidos}\n";
echo "Preços totais corrigidos (parcela→total): {$precoTotalCorrigidos}\n";
echo "Parcelas limpas: {$parcelasLimpas}\n";
