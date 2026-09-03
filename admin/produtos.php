<?php
// Processar TUDO antes de qualquer output
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$message = '';
$messageType = '';

// Processar formulário POST (ANTES do header)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $nome = trim($_POST['nome'] ?? '');
    $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
    
    // Processar preços com parsePrecoBr se disponível
    $preco = null;
    if (!empty($_POST['preco'])) {
        if (function_exists('parsePrecoBr')) {
            $preco = parsePrecoBr($_POST['preco']);
        }
        if ($preco === null) {
            $preco = floatval(str_replace([','], ['.'], $_POST['preco']));
        }
    }
    $preco_original = null;
    if (!empty($_POST['preco_original'])) {
        if (function_exists('parsePrecoBr')) {
            $preco_original = parsePrecoBr($_POST['preco_original']);
        }
        if ($preco_original === null) {
            $preco_original = floatval(str_replace([','], ['.'], $_POST['preco_original']));
        }
    }
    $parcelas = isset($_POST['parcelas']) && $_POST['parcelas'] !== '' ? max(1, (int)$_POST['parcelas']) : null;
    $preco_parcela = null;
    if (!empty($_POST['preco_parcela'])) {
        if (function_exists('parsePrecoBr')) {
            $preco_parcela = parsePrecoBr($_POST['preco_parcela']);
        }
        if ($preco_parcela === null) {
            $preco_parcela = floatval(str_replace([','], ['.'], $_POST['preco_parcela']));
        }
    }
    $link_compra = trim($_POST['link_compra'] ?? '');
    $destaque = isset($_POST['destaque']) ? 1 : 0;
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    // Calcular desconto e sanear preco_original (evita ex.: 50.431 em vez de 504)
    $desconto = 0;
    if ($preco_original > 0 && $preco > 0 && $preco_original > $preco) {
        $desconto = calcularDesconto($preco_original, $preco);
    }
    if (function_exists('sanearPrecoOriginal') && $preco > 0 && $preco_original > 0) {
        list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, $desconto);
    }
    
    // Garantir que preco é o total, não o valor da parcela
    if (function_exists('corrigirPrecoTotalParcelas') && $parcelas && $preco_parcela) {
        $preco = corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela);
    }
    
    // Upload de imagem
    $imagem = null;
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $imagem = uploadImagem($_FILES['imagem'], 'uploads/produtos/');
    }
    
    if (empty($nome)) {
        $message = 'O nome do produto é obrigatório!';
        $messageType = 'error';
    } else {
        try {
            if ($id) {
                // Editar
                if ($imagem) {
                    // Deletar imagem antiga se houver
                    $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
                    $stmt->execute([$id]);
                    $produtoAntigo = $stmt->fetch();
                    if ($produtoAntigo && !empty($produtoAntigo['imagem'])) {
                        deleteImagem($produtoAntigo['imagem']);
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE produtos 
                        SET nome = ?, categoria_id = ?, imagem = ?, preco = ?, preco_original = ?, 
                            desconto = ?, parcelas = ?, preco_parcela = ?, link_compra = ?, destaque = ?, ativo = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$nome, $categoria_id, $imagem, $preco, $preco_original, $desconto, $parcelas, $preco_parcela, $link_compra, $destaque, $ativo, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE produtos 
                        SET nome = ?, categoria_id = ?, preco = ?, preco_original = ?, 
                            desconto = ?, parcelas = ?, preco_parcela = ?, link_compra = ?, destaque = ?, ativo = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$nome, $categoria_id, $preco, $preco_original, $desconto, $parcelas, $preco_parcela, $link_compra, $destaque, $ativo, $id]);
                }
                $q = ['edit' => $id, 'msg' => 'success'];
                if (!empty($_POST['filtro_busca'])) $q['busca'] = $_POST['filtro_busca'];
                if (!empty($_POST['filtro_categoria_id'])) $q['categoria_id'] = (int)$_POST['filtro_categoria_id'];
                if (!empty($_POST['filtro_data_de'])) $q['data_de'] = $_POST['filtro_data_de'];
                if (!empty($_POST['filtro_data_ate'])) $q['data_ate'] = $_POST['filtro_data_ate'];
                header('Location: produtos.php?' . http_build_query($q));
                exit;
            } else {
                // Criar
                if (!$imagem) {
                    $message = 'A imagem do produto é obrigatória!';
                    $messageType = 'error';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO produtos (nome, categoria_id, imagem, preco, preco_original, desconto, parcelas, preco_parcela, link_compra, destaque, ativo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nome, $categoria_id, $imagem, $preco, $preco_original, $desconto, $parcelas, $preco_parcela, $link_compra, $destaque, $ativo]);
                    $novoId = $pdo->lastInsertId();
                    $q = ['edit' => $novoId, 'msg' => 'success'];
                    if (!empty($_POST['filtro_busca'])) $q['busca'] = $_POST['filtro_busca'];
                    if (!empty($_POST['filtro_categoria_id'])) $q['categoria_id'] = (int)$_POST['filtro_categoria_id'];
                    if (!empty($_POST['filtro_data_de'])) $q['data_de'] = $_POST['filtro_data_de'];
                    if (!empty($_POST['filtro_data_ate'])) $q['data_ate'] = $_POST['filtro_data_ate'];
                    header('Location: produtos.php?' . http_build_query($q));
                    exit;
                }
            }
        } catch (PDOException $e) {
            $message = 'Erro ao salvar produto: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
            error_log("Erro ao salvar produto: " . $e->getMessage());
        } catch (Exception $e) {
            $message = 'Erro inesperado: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
            error_log("Erro ao salvar produto: " . $e->getMessage());
        }
    }
}

// Excluir vários produtos (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_selecionados']) && !empty($_POST['ids'])) {
    $ids = array_map('intval', (array)$_POST['ids']);
    $ids = array_filter($ids);
    $excluidos = 0;
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        $produto = $stmt->fetch();
        if ($produto) {
            if (!empty($produto['imagem'])) {
                deleteImagem($produto['imagem']);
            }
            $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);
            $excluidos++;
        }
    }
    $q = [];
    if (!empty($_POST['filtro_busca'])) $q['busca'] = $_POST['filtro_busca'];
    if (!empty($_POST['filtro_categoria_id'])) $q['categoria_id'] = (int)$_POST['filtro_categoria_id'];
    if (!empty($_POST['filtro_data_de'])) $q['data_de'] = $_POST['filtro_data_de'];
    if (!empty($_POST['filtro_data_ate'])) $q['data_ate'] = $_POST['filtro_data_ate'];
    $q['msg'] = $excluidos > 0 ? 'deleted_many' : 'deleted';
    if ($excluidos > 0) $q['n'] = $excluidos;
    header('Location: produtos.php?' . http_build_query($q));
    exit;
}

// Deletar produto (um único)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
    $stmt->execute([$id]);
    $produto = $stmt->fetch();
    
    if ($produto) {
        if (!empty($produto['imagem'])) {
            deleteImagem($produto['imagem']);
        }
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        $query = isset($_GET['busca']) || isset($_GET['categoria_id']) || isset($_GET['data_de']) || isset($_GET['data_ate']) ? '&' . http_build_query(array_intersect_key($_GET, array_flip(['busca', 'categoria_id', 'data_de', 'data_ate']))) : '';
        header('Location: produtos.php?msg=deleted' . $query);
        exit;
    }
}

// Mensagem de sucesso via GET
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'success') {
        $message = 'Produto salvo com sucesso!';
        $messageType = 'success';
    } elseif ($_GET['msg'] === 'deleted') {
        $message = 'Produto deletado com sucesso!';
        $messageType = 'success';
    } elseif (strpos($_GET['msg'], 'deleted_many') === 0) {
        $n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
        $message = $n > 0 ? $n . ' produto(s) excluído(s) com sucesso!' : 'Produtos excluídos.';
        $messageType = 'success';
    }
}

// Agora sim, incluir o header (depois de todo processamento)
$pageTitle = 'Produtos';
require_once __DIR__ . '/includes/header.php';

// Editar produto
$editProduct = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
    $stmt->execute([$id]);
    $editProduct = $stmt->fetch();
}

// Filtros (GET)
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro_categoria = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : 0;
$data_de = isset($_GET['data_de']) ? trim($_GET['data_de']) : '';
$data_ate = isset($_GET['data_ate']) ? trim($_GET['data_ate']) : '';

$where = [];
$params = [];
if ($busca !== '') {
    $where[] = "(p.nome LIKE ? OR p.link_compra LIKE ?)";
    $termo = '%' . $busca . '%';
    $params[] = $termo;
    $params[] = $termo;
}
if ($filtro_categoria > 0) {
    $where[] = "p.categoria_id = ?";
    $params[] = $filtro_categoria;
}
if ($data_de !== '') {
    $where[] = "DATE(p.created_at) >= ?";
    $params[] = $data_de;
}
if ($data_ate !== '') {
    $where[] = "DATE(p.created_at) <= ?";
    $params[] = $data_ate;
}

$sql = "
    SELECT p.*, c.nome as categoria_nome 
    FROM produtos p 
    LEFT JOIN categorias c ON p.categoria_id = c.id 
";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produtos = $stmt->fetchAll();

// Mesclar duplicatas e listar categorias sem nomes repetidos no filtro/formulário
if (function_exists('achadinhosCategoriasPrecisamMescla') && achadinhosCategoriasPrecisamMescla($pdo)) {
    achadinhosMesclarCategoriasDuplicadas($pdo);
}
$categorias = function_exists('achadinhosListarCategoriasUnicasSelect')
    ? achadinhosListarCategoriasUnicasSelect($pdo)
    : $pdo->query("SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY ordem")->fetchAll();
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Produtos</h1>
                <button onclick="showForm()" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-4 rounded transition-colors">
                    + Novo Produto
                </button>
            </div>
            
            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Formulário (oculto por padrão, exceto quando editando) -->
            <div id="formContainer" class="bg-white rounded-lg shadow p-6 mb-8 <?php echo $editProduct ? '' : 'hidden'; ?>">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <?php echo $editProduct ? 'Editar Produto' : 'Novo Produto'; ?>
                </h2>
                
                <form method="POST" enctype="multipart/form-data">
                    <?php if ($editProduct): ?>
                    <input type="hidden" name="id" value="<?php echo $editProduct['id']; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="filtro_busca" value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="filtro_categoria_id" value="<?php echo $filtro_categoria; ?>">
                    <input type="hidden" name="filtro_data_de" value="<?php echo htmlspecialchars($data_de); ?>">
                    <input type="hidden" name="filtro_data_ate" value="<?php echo htmlspecialchars($data_ate); ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">Nome do Produto *</label>
                            <input type="text" id="nome" name="nome" required 
                                   value="<?php echo htmlspecialchars($editProduct['nome'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="categoria_id" class="block text-sm font-medium text-gray-700 mb-2">Categoria</label>
                            <select id="categoria_id" name="categoria_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="">Selecione uma categoria</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                        <?php echo ($editProduct && $editProduct['categoria_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['nome']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="imagem" class="block text-sm font-medium text-gray-700 mb-2">
                                Imagem <?php echo $editProduct ? '' : '*'; ?>
                            </label>
                            <input type="file" id="imagem" name="imagem" accept="image/*" 
                                   <?php echo $editProduct ? '' : 'required'; ?>
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <?php if ($editProduct && !empty($editProduct['imagem'])): ?>
                            <p class="mt-2 text-sm text-gray-500">Imagem atual:</p>
                            <img src="../<?php echo htmlspecialchars($editProduct['imagem']); ?>" alt="" class="mt-2 w-32 h-32 object-cover rounded">
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <label for="link_compra" class="block text-sm font-medium text-gray-700 mb-2">Link de Compra</label>
                            <input type="url" id="link_compra" name="link_compra"
                                   value="<?php echo htmlspecialchars($editProduct['link_compra'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="preco" class="block text-sm font-medium text-gray-700 mb-2">Preço (total à vista)</label>
                            <input type="text" id="preco" name="preco" 
                                   value="<?php echo $editProduct ? number_format($editProduct['preco'] ?? 0, 2, ',', '.') : ''; ?>"
                                   placeholder="480,15"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Sempre o valor total. Ex.: R$ 480,15 (nunca use o valor da parcela aqui).</p>
                        </div>
                        
                        <div>
                            <label for="preco_original" class="block text-sm font-medium text-gray-700 mb-2">Preço Original</label>
                            <input type="text" id="preco_original" name="preco_original"
                                   value="<?php echo $editProduct ? number_format($editProduct['preco_original'] ?? 0, 2, ',', '.') : ''; ?>"
                                   placeholder="0,00"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="parcelas" class="block text-sm font-medium text-gray-700 mb-2">Parcelas (opcional)</label>
                            <input type="number" id="parcelas" name="parcelas" min="1" max="99" placeholder="12"
                                   value="<?php echo $editProduct && !empty($editProduct['parcelas']) ? (int)$editProduct['parcelas'] : ''; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Ex.: 12 (para "em 12x de R$ 46,43")</p>
                        </div>
                        
                        <div>
                            <label for="preco_parcela" class="block text-sm font-medium text-gray-700 mb-2">Preço da parcela (opcional)</label>
                            <input type="text" id="preco_parcela" name="preco_parcela" placeholder="46,43"
                                   value="<?php echo $editProduct && !empty($editProduct['preco_parcela']) ? number_format((float)$editProduct['preco_parcela'], 2, ',', '.') : ''; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Preço = total à vista. Use isto para exibir "parcela de R$ X,XX em Nx".</p>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="destaque" value="1" 
                                       <?php echo ($editProduct && $editProduct['destaque']) ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="ml-2 text-sm text-gray-700">Destaque</span>
                            </label>
                            
                            <label class="flex items-center">
                                <input type="checkbox" name="ativo" value="1" 
                                       <?php echo ($editProduct && $editProduct['ativo']) ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="ml-2 text-sm text-gray-700">Ativo</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex gap-4">
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                            Salvar
                        </button>
                        <button type="button" onclick="hideForm()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-6 rounded transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Filtros -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">Filtrar produtos</h2>
                <form method="GET" action="produtos.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                    <div>
                        <label for="busca" class="block text-sm font-medium text-gray-700 mb-1">Pesquisar (nome ou link)</label>
                        <input type="text" id="busca" name="busca" value="<?php echo htmlspecialchars($busca); ?>"
                               placeholder="Nome do produto..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label for="categoria_id" class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
                        <select id="categoria_id" name="categoria_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $filtro_categoria === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="data_de" class="block text-sm font-medium text-gray-700 mb-1">Data de (cadastro)</label>
                        <input type="date" id="data_de" name="data_de" value="<?php echo htmlspecialchars($data_de); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label for="data_ate" class="block text-sm font-medium text-gray-700 mb-1">Data até</label>
                        <input type="date" id="data_ate" name="data_ate" value="<?php echo htmlspecialchars($data_ate); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-4 rounded transition-colors">Filtrar</button>
                        <a href="produtos.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition-colors inline-flex items-center">Limpar</a>
                    </div>
                </form>
                <?php if ($busca !== '' || $filtro_categoria > 0 || $data_de !== '' || $data_ate !== ''): ?>
                <p class="mt-2 text-sm text-gray-500">Exibindo <?php echo count($produtos); ?> produto(s) com os filtros aplicados.</p>
                <?php endif; ?>
            </div>

            <!-- Lista de produtos -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form method="POST" action="produtos.php" id="formExcluirVarios">
                    <input type="hidden" name="filtro_busca" value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="filtro_categoria_id" value="<?php echo $filtro_categoria; ?>">
                    <input type="hidden" name="filtro_data_de" value="<?php echo htmlspecialchars($data_de); ?>">
                    <input type="hidden" name="filtro_data_ate" value="<?php echo htmlspecialchars($data_ate); ?>">
                    
                    <div class="px-6 py-3 bg-gray-50 border-b flex items-center justify-between flex-wrap gap-2">
                        <span class="text-sm text-gray-600" id="contadorSelecionados">0 selecionado(s)</span>
                        <button type="submit" name="excluir_selecionados" value="1" id="btnExcluirSelecionados" disabled
                                onclick="return confirm('Tem certeza que deseja excluir os produtos selecionados?');"
                                class="bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded transition-colors text-sm">
                            Excluir selecionados
                        </button>
                    </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-orange-500 focus:ring-orange-500" title="Selecionar todos">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Imagem</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nome</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoria</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($produtos)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-4 text-center text-gray-500">Nenhum produto encontrado.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($produtos as $produto): ?>
                            <tr>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int)$produto['id']; ?>" class="cb-produto rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#<?php echo $produto['id']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($produto['imagem'])): ?>
                                    <img src="../<?php echo htmlspecialchars($produto['imagem']); ?>" alt="" class="w-16 h-16 object-cover rounded">
                                    <?php else: ?>
                                    <div class="w-16 h-16 bg-gray-200 rounded flex items-center justify-center text-gray-400 text-xs">Sem imagem</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($produto['nome']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($produto['categoria_nome'] ?? 'Sem categoria'); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php if ($produto['preco']): ?>
                                    <span>R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?></span>
                                    <?php if ($produto['preco_original'] && $produto['preco_original'] > $produto['preco']): ?>
                                    <span class="text-gray-500 line-through ml-2">R$ <?php echo number_format($produto['preco_original'], 2, ',', '.'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($produto['parcelas']) && !empty($produto['preco_parcela'])): ?>
                                    <div class="text-xs text-gray-600 mt-0.5">ou parcela de R$ <?php echo number_format((float)$produto['preco_parcela'], 2, ',', '.'); ?> em <?php echo (int)$produto['parcelas']; ?>x</div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400">Não informado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($produto['destaque']): ?>
                                    <span class="px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800">Destaque</span>
                                    <?php endif; ?>
                                    <?php if ($produto['ativo']): ?>
                                    <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">Ativo</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-800">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php
                                    $linkEditar = '?edit=' . $produto['id'];
                                    if ($busca !== '') $linkEditar .= '&busca=' . urlencode($busca);
                                    if ($filtro_categoria > 0) $linkEditar .= '&categoria_id=' . $filtro_categoria;
                                    if ($data_de !== '') $linkEditar .= '&data_de=' . urlencode($data_de);
                                    if ($data_ate !== '') $linkEditar .= '&data_ate=' . urlencode($data_ate);
                                    $linkDelete = '?delete=' . $produto['id'];
                                    if ($busca !== '') $linkDelete .= '&busca=' . urlencode($busca);
                                    if ($filtro_categoria > 0) $linkDelete .= '&categoria_id=' . $filtro_categoria;
                                    if ($data_de !== '') $linkDelete .= '&data_de=' . urlencode($data_de);
                                    if ($data_ate !== '') $linkDelete .= '&data_ate=' . urlencode($data_ate);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($linkEditar); ?>" class="text-orange-500 hover:text-orange-700 mr-3">Editar</a>
                                    <a href="<?php echo htmlspecialchars($linkDelete); ?>" 
                                       onclick="return confirm('Tem certeza que deseja deletar este produto?')"
                                       class="text-red-500 hover:text-red-700">Deletar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </form>
            </div>
        </main>
        
        <script>
        function showForm() {
            document.getElementById('formContainer').classList.remove('hidden');
            document.getElementById('formContainer').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideForm() {
            document.getElementById('formContainer').classList.add('hidden');
            window.location.href = 'produtos.php';
        }

        (function() {
            var selectAll = document.getElementById('selectAll');
            var checkboxes = document.querySelectorAll('.cb-produto');
            var btnExcluir = document.getElementById('btnExcluirSelecionados');
            var contador = document.getElementById('contadorSelecionados');

            function updateCount() {
                var n = document.querySelectorAll('.cb-produto:checked').length;
                contador.textContent = n + ' selecionado(s)';
                btnExcluir.disabled = n === 0;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                    updateCount();
                });
            }
            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', updateCount);
            });
            updateCount();
        })();
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
