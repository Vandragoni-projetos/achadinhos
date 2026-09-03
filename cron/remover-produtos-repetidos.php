<?php
/**
 * Remove produtos repetidos (mesmo nome ou nome muito similar).
 * Mantém o produto mais RECENTE e remove os mais antigos.
 * Também remove as imagens dos produtos deletados.
 *
 * Uso:
 *   CLI:  php cron/remover-produtos-repetidos.php
 *   Web:  /cron/remover-produtos-repetidos.php
 *
 * Parâmetros opcionais (via GET):
 *   ?preview=1    - Apenas mostra o que seria removido, sem remover
 *   ?keep=oldest  - Mantém o mais antigo em vez do mais recente
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$preview = isset($_GET['preview']) && $_GET['preview'] == '1';
$keepOldest = isset($_GET['keep']) && $_GET['keep'] === 'oldest';

$pdo = getDB();

echo "<pre>";
echo "=== Removendo produtos repetidos ===\n";
echo "Modo: " . ($preview ? "PREVIEW (não remove nada)" : "EXECUÇÃO") . "\n";
echo "Manter: " . ($keepOldest ? "mais ANTIGO" : "mais RECENTE") . "\n\n";

// Buscar todos os produtos ordenados por data
$orderBy = $keepOldest ? "ASC" : "DESC";
$produtos = $pdo->query("
    SELECT id, nome, imagem, created_at 
    FROM produtos 
    ORDER BY created_at {$orderBy}
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por nome normalizado (primeiros 50 chars, lowercase, sem acentos)
$grupos = [];
foreach ($produtos as $p) {
    // Normalizar nome para comparação
    $nomeNorm = mb_strtolower(mb_substr(trim($p['nome']), 0, 50));
    $nomeNorm = preg_replace('/\s+/', ' ', $nomeNorm);
    
    if (!isset($grupos[$nomeNorm])) {
        $grupos[$nomeNorm] = [];
    }
    $grupos[$nomeNorm][] = $p;
}

$removidos = 0;
$imagensRemovidas = 0;
$idsRemovidos = [];

foreach ($grupos as $nomeNorm => $produtosGrupo) {
    if (count($produtosGrupo) <= 1) {
        continue; // Não é repetido
    }
    
    // O primeiro é o que vamos manter (já ordenado por data)
    $manter = array_shift($produtosGrupo);
    
    echo "----------------------------------------\n";
    echo "GRUPO: \"" . mb_substr($manter['nome'], 0, 60) . "...\"\n";
    echo "  MANTER: ID #{$manter['id']} (criado em {$manter['created_at']})\n";
    
    foreach ($produtosGrupo as $duplicado) {
        echo "  REMOVER: ID #{$duplicado['id']} (criado em {$duplicado['created_at']})\n";
        
        if (!$preview) {
            // Remover imagem
            if (!empty($duplicado['imagem'])) {
                $imgPath = __DIR__ . '/../' . $duplicado['imagem'];
                if (file_exists($imgPath)) {
                    if (@unlink($imgPath)) {
                        $imagensRemovidas++;
                    }
                }
            }
            
            // Remover produto
            $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
            $stmt->execute([$duplicado['id']]);
            
            $idsRemovidos[] = $duplicado['id'];
        }
        
        $removidos++;
    }
}

echo "\n=== Resultado ===\n";
echo "Total de produtos analisados: " . count($produtos) . "\n";
echo "Grupos com repetição: " . count(array_filter($grupos, fn($g) => count($g) > 1)) . "\n";

if ($preview) {
    echo "Produtos que SERIAM removidos: {$removidos}\n";
    echo "\n*** Execute sem ?preview=1 para remover de fato ***\n";
} else {
    echo "Produtos removidos: {$removidos}\n";
    echo "Imagens removidas: {$imagensRemovidas}\n";
    
    if (!empty($idsRemovidos)) {
        echo "IDs removidos: " . implode(', ', $idsRemovidos) . "\n";
    }
}

echo "</pre>";
