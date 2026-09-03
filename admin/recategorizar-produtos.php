<?php
/**
 * Recategoriza produtos existentes baseado no nome do produto.
 * Usa a função obterOuCriarCategoriaParaProduto melhorada para categorizar corretamente.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/automacao-ml.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';
$details = [];
$produtosRecategorizados = [];
$produtosSemMudanca = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    $pdo = getDB();
    
    // Buscar todos os produtos ativos
    $produtos = $pdo->query("SELECT id, nome, categoria_id FROM produtos WHERE ativo = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($produtos);
    $recategorizados = 0;
    $semMudanca = 0;
    $erros = 0;
    
    foreach ($produtos as $produto) {
        $produtoId = (int)$produto['id'];
        $nomeProduto = $produto['nome'];
        $categoriaAtual = (int)$produto['categoria_id'];
        
        // Obter categoria correta baseada no nome do produto
        $err = '';
        $novaCategoriaId = obterOuCriarCategoriaParaProduto($nomeProduto, $err, 'ml');
        
        if ($novaCategoriaId === null) {
            $erros++;
            continue;
        }
        
        // Se a categoria mudou, atualizar
        if ($categoriaAtual !== $novaCategoriaId) {
            try {
                $st = $pdo->prepare("UPDATE produtos SET categoria_id = ? WHERE id = ?");
                $st->execute([$novaCategoriaId, $produtoId]);
                
                // Buscar nomes das categorias para exibição
                $stCatAntiga = $pdo->prepare("SELECT nome FROM categorias WHERE id = ?");
                $stCatAntiga->execute([$categoriaAtual]);
                $catAntiga = $stCatAntiga->fetchColumn() ?: 'Sem categoria';
                
                $stCatNova = $pdo->prepare("SELECT nome FROM categorias WHERE id = ?");
                $stCatNova->execute([$novaCategoriaId]);
                $catNova = $stCatNova->fetchColumn() ?: 'Sem categoria';
                
                $produtosRecategorizados[] = [
                    'id' => $produtoId,
                    'nome' => mb_substr($nomeProduto, 0, 60) . (mb_strlen($nomeProduto) > 60 ? '...' : ''),
                    'categoria_antiga' => $catAntiga,
                    'categoria_nova' => $catNova
                ];
                
                $recategorizados++;
            } catch (Exception $e) {
                $erros++;
                error_log("Erro ao recategorizar produto ID {$produtoId}: " . $e->getMessage());
            }
        } else {
            $semMudanca++;
        }
    }
    
    $details[] = "Total de produtos processados: {$total}";
    $details[] = "Produtos recategorizados: {$recategorizados}";
    $details[] = "Produtos sem mudança: {$semMudanca}";
    if ($erros > 0) {
        $details[] = "Erros: {$erros}";
    }
    
    $message = 'Recategorização concluída. ' . implode(' | ', $details);
    $messageType = 'success';
}

$pdo = getDB();
$totalProdutos = $pdo->query("SELECT COUNT(*) FROM produtos WHERE ativo = 1")->fetchColumn();
$pageTitle = 'Recategorizar Produtos';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Recategorizar Produtos</h1>
            <p class="text-gray-600 mb-8">Recategoriza todos os produtos ativos baseado no nome do produto usando a lógica melhorada de categorização.</p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Informações</h2>
                <ul class="list-disc list-inside space-y-2 text-gray-700">
                    <li>Total de produtos ativos: <strong><?php echo (int)$totalProdutos; ?></strong></li>
                    <li>Esta ação irá analisar o nome de cada produto e atribuir a categoria mais adequada.</li>
                    <li>Apenas produtos com categoria incorreta serão atualizados.</li>
                    <li>A lógica de categorização foi melhorada para evitar erros como:
                        <ul class="list-disc list-inside ml-6 mt-2 space-y-1 text-sm">
                            <li>Cuecas sendo categorizadas como Tecnologia</li>
                            <li>Monitores sendo categorizados como Casa</li>
                            <li>Ar condicionado sendo categorizado como Beleza</li>
                        </ul>
                    </li>
                </ul>
            </div>

            <?php if (!empty($produtosRecategorizados)): ?>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Produtos Recategorizados (últimos 50)</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nome</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoria Antiga</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoria Nova</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach (array_slice($produtosRecategorizados, 0, 50) as $prod): ?>
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($prod['id']); ?></td>
                                <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($prod['nome']); ?></td>
                                <td class="px-4 py-2 text-sm text-red-600"><?php echo htmlspecialchars($prod['categoria_antiga']); ?></td>
                                <td class="px-4 py-2 text-sm text-green-600 font-medium"><?php echo htmlspecialchars($prod['categoria_nova']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST">
                <button type="submit" name="executar" value="1" 
                        onclick="return confirm('Tem certeza? Isso vai recategorizar todos os produtos ativos baseado no nome do produto.');"
                        class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                    Executar Recategorização
                </button>
            </form>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
