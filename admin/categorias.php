<?php
$pageTitle = 'Categorias';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
if (function_exists('achadinhosCategoriasPrecisamMescla') && achadinhosCategoriasPrecisamMescla($pdo)) {
    achadinhosMesclarCategoriasDuplicadas($pdo);
}
if (function_exists('garantirHierarquiaCategoriasBase')) {
    garantirHierarquiaCategoriasBase();
} elseif (function_exists('achadinhosGarantirHierarquiaModa')) {
    achadinhosGarantirHierarquiaModa($pdo);
}
$message = '';
$messageType = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $nome = trim($_POST['nome'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $whatsapp_grupo = trim($_POST['whatsapp_grupo'] ?? '');
    $nomeAnterior = trim($_POST['nome_anterior'] ?? '');
    
    if (empty($slug)) {
        $slug = function_exists('achadinhosSlugifyTexto')
            ? achadinhosSlugifyTexto($nome)
            : strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
        $slug = trim($slug, '-');
    }
    
    if (empty($nome)) {
        $message = 'O nome da categoria é obrigatório!';
        $messageType = 'error';
    } else {
        try {
            $testCol = $pdo->query("SHOW COLUMNS FROM categorias LIKE 'whatsapp_grupo'")->fetch();
            $hasWhatsAppGrupo = (bool)$testCol;
        } catch (Exception $e) {
            $hasWhatsAppGrupo = false;
        }
        
        $excludeId = $id ? (int)$id : 0;
        $dupSlugSt = $pdo->prepare("SELECT id FROM categorias WHERE LOWER(TRIM(slug)) = LOWER(TRIM(?)) AND id != ? LIMIT 1");
        $dupSlugSt->execute([$slug, $excludeId]);
        $chaveN = function_exists('achadinhosChaveNomeCategoria') ? achadinhosChaveNomeCategoria($nome) : strtolower(trim($nome));
        $dupNome = false;
        $stAll = $pdo->prepare("SELECT nome FROM categorias WHERE id != ?");
        $stAll->execute([$excludeId]);
        while ($row = $stAll->fetch(PDO::FETCH_ASSOC)) {
            $kn = function_exists('achadinhosChaveNomeCategoria') ? achadinhosChaveNomeCategoria($row['nome']) : strtolower(trim($row['nome']));
            if ($kn === $chaveN) {
                $dupNome = true;
                break;
            }
        }
        
        if ($dupSlugSt->fetch()) {
            $message = 'Já existe uma categoria com este slug.';
            $messageType = 'error';
        } elseif ($dupNome) {
            $message = 'Já existe uma categoria com o mesmo nome.';
            $messageType = 'error';
        } else {
            $skipSave = false;
            if (!$id && function_exists('achadinhosReutilizarCategoriaExistente')) {
                $reutilId = achadinhosReutilizarCategoriaExistente($pdo, $nome, $slug);
                if ($reutilId !== null) {
                    $message = 'Já existe categoria equivalente (slug ou nome). Edite o registro existente (ID ' . (int) $reutilId . ') em vez de criar outro.';
                    $messageType = 'error';
                    $skipSave = true;
                }
            }
            if (!$skipSave) {
                if ($id) {
                    if ($hasWhatsAppGrupo) {
                        $stmt = $pdo->prepare("UPDATE categorias SET nome = ?, slug = ?, ordem = ?, ativo = ?, whatsapp_grupo = ? WHERE id = ?");
                        $stmt->execute([$nome, $slug, $ordem, $ativo, $whatsapp_grupo ?: null, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE categorias SET nome = ?, slug = ?, ordem = ?, ativo = ? WHERE id = ?");
                        $stmt->execute([$nome, $slug, $ordem, $ativo, $id]);
                    }
                    $message = 'Categoria atualizada com sucesso!';
                    if ($nomeAnterior !== '' && function_exists('achadinhosSubstituirTopbarPorMudancaNome')
                        && function_exists('achadinhosChaveNomeCategoria')
                        && achadinhosChaveNomeCategoria($nomeAnterior) !== achadinhosChaveNomeCategoria($nome)) {
                        achadinhosSubstituirTopbarPorMudancaNome($nomeAnterior, $nome);
                    }
                } else {
                    if ($hasWhatsAppGrupo) {
                        $stmt = $pdo->prepare("INSERT INTO categorias (nome, slug, ordem, ativo, whatsapp_grupo) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$nome, $slug, $ordem, $ativo, $whatsapp_grupo ?: null]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$nome, $slug, $ordem, $ativo]);
                    }
                    $message = 'Categoria cadastrada com sucesso!';
                }
                $messageType = 'success';
            }
        }
    }
}

// Deletar categoria
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Verificar se há produtos usando esta categoria
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE categoria_id = ?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $message = 'Não é possível deletar esta categoria pois existem produtos associados a ela!';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Categoria deletada com sucesso!';
        $messageType = 'success';
    }
}

// Editar categoria
$editCategoria = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id = ?");
    $stmt->execute([$id]);
    $editCategoria = $stmt->fetch();
}

// Listar categorias (árvore: pai Moda → subcategorias quando parent_id existir)
$categorias = $pdo->query("SELECT c.*, COUNT(p.id) as total_produtos FROM categorias c LEFT JOIN produtos p ON c.id = p.categoria_id GROUP BY c.id ORDER BY c.ordem, c.nome")->fetchAll();
if (function_exists('achadinhosOrdenarCategoriasArvore')) {
    $categorias = achadinhosOrdenarCategoriasArvore($categorias);
}
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex justify-between items-center mb-6 flex-wrap gap-4">
                <h1 class="text-2xl font-bold text-gray-800">Categorias</h1>
                <button onclick="showForm()" class="inline-flex items-center gap-2 px-4 py-2.5 bg-orange-500 hover:bg-orange-600 text-white font-medium rounded-lg transition-colors shadow-sm">
                    <span class="text-lg leading-none">+</span> Nova Categoria
                </button>
            </div>
            
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Formulário -->
            <div id="formContainer" class="bg-white rounded-xl border border-gray-200 p-6 mb-8 shadow-sm <?php echo $editCategoria ? '' : 'hidden'; ?>">
                <h2 class="text-lg font-semibold text-gray-800 mb-6">
                    <?php echo $editCategoria ? 'Editar Categoria' : 'Nova Categoria'; ?>
                </h2>
                
                <form method="POST">
                    <?php if ($editCategoria): ?>
                    <input type="hidden" name="id" value="<?php echo $editCategoria['id']; ?>">
                    <input type="hidden" name="nome_anterior" value="<?php echo htmlspecialchars($editCategoria['nome'] ?? ''); ?>">
                    <input type="hidden" name="slug" value="<?php echo htmlspecialchars($editCategoria['slug'] ?? ''); ?>">
                    <input type="hidden" name="ordem" value="<?php echo $editCategoria['ordem'] ?? 0; ?>">
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nome" class="block text-sm font-medium text-gray-700 mb-1.5">Nome *</label>
                            <input type="text" id="nome" name="nome" required
                                   value="<?php echo htmlspecialchars($editCategoria['nome'] ?? ''); ?>"
                                   placeholder="Ex: Eletrônicos"
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        </div>
                        
                        <div>
                            <label for="whatsapp_grupo" class="block text-sm font-medium text-gray-700 mb-1.5">Grupo WhatsApp (opcional)</label>
                            <input type="text" id="whatsapp_grupo" name="whatsapp_grupo"
                                   value="<?php echo htmlspecialchars($editCategoria['whatsapp_grupo'] ?? ''); ?>"
                                   placeholder="ID do grupo (ex: 5511999999999@g.us)"
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                            <p class="mt-1 text-xs text-gray-400">Se vazio, usa os grupos padrão da automação.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 mt-6">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="ativo" value="1"
                                   <?php echo ($editCategoria && $editCategoria['ativo']) ? 'checked' : ''; ?>
                                   class="w-4 h-4 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span class="text-sm text-gray-700">Ativo</span>
                        </label>
                        <div class="flex gap-3 ml-auto">
                            <button type="button" onclick="hideForm()" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm font-medium">
                                Cancelar
                            </button>
                            <button type="submit" class="px-5 py-2.5 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition-colors">
                                Salvar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Lista de categorias -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                <?php if (empty($categorias)): ?>
                <div class="p-12 text-center">
                    <p class="text-gray-500 text-sm">Nenhuma categoria cadastrada.</p>
                    <button onclick="showForm()" class="mt-4 text-orange-500 hover:text-orange-600 font-medium text-sm">Cadastrar primeira categoria</button>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500">Nome</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500">Produtos</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500">Grupo WhatsApp</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500">Status</th>
                                <th class="px-6 py-4 text-right text-xs font-medium text-gray-500">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($categorias as $categoria): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4">
                                    <?php
                                    $depth = (int)($categoria['_tree_depth'] ?? 0);
                                    $indent = $depth > 0 ? str_repeat('· ', $depth) : '';
                                    ?>
                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($indent . $categoria['nome']); ?></span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo $categoria['total_produtos']; ?></td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($categoria['whatsapp_grupo'])): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 text-xs rounded-md bg-sky-50 text-sky-700" title="<?php echo htmlspecialchars($categoria['whatsapp_grupo']); ?>">
                                            <?php echo htmlspecialchars(mb_substr($categoria['whatsapp_grupo'], 0, 18)) . (mb_strlen($categoria['whatsapp_grupo']) > 18 ? '…' : ''); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">Padrão</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($categoria['ativo']): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs rounded-md bg-emerald-50 text-emerald-700">Ativo</span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs rounded-md bg-gray-100 text-gray-600">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="?edit=<?php echo $categoria['id']; ?>" class="text-orange-500 hover:text-orange-600 text-sm font-medium mr-4">Editar</a>
                                    <a href="?delete=<?php echo $categoria['id']; ?>"
                                       onclick="return confirm('Deletar esta categoria?')"
                                       class="text-red-500 hover:text-red-600 text-sm font-medium">Excluir</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
        
        <script>
        function showForm() {
            document.getElementById('formContainer').classList.remove('hidden');
            document.getElementById('formContainer').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideForm() {
            document.getElementById('formContainer').classList.add('hidden');
            window.location.href = 'categorias.php';
        }
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
