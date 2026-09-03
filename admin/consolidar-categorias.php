<?php
/**
 * Consolida categorias em nichos fixos (12 categorias: inclui Moda como pai e Moda infantil).
 * - Cria as categorias se não existirem
 * - Reatribui todos os produtos por similaridade do nome/slug antigo
 * - Remove categorias duplicadas/antigas
 * - Garante hierarquia Moda → masculina / feminina / infantil (parent_id)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$NICHOS = [
    'beleza-cuidados-saude'       => 'Beleza, Cuidados Pessoais, Saúde e Bem-estar',
    'tecnologia-eletronicos'      => 'Tecnologia, Eletrônicos, Games, Telefones, Jogos',
    'brinquedos-bebes-criancas'   => 'Brinquedos, Jogos, Bebês e Crianças',
    'casa-cozinha-decoracao'      => 'Casa, Cozinha, Decoração, Móveis, Construção Civil',
    'estilo-vida-hobbies'         => 'Estilo de Vida, Hobbies, Livros, Esportes e Fitness, Pets, Agro',
    'moda'                        => 'Moda',
    'moda-masculina'              => 'Moda Masculina, Calçados, Esportes, Acessórios',
    'moda-feminina'               => 'Moda Feminina, Calçados, Esportes, Acessórios',
    'moda-infantil'               => 'Moda Infantil, Calçados, Esportes, Acessórios',
    'automotivo-ferramentas'      => 'Automotivo, Acessórios Veículos, Supermercados, Ferramentas',
    'tudo-em-um'                  => 'Tudo em um',
    'produtos-intimos'            => 'Produtos Íntimos +18',
];

// Mapeamento: termos (sem acento) -> slug do nicho (para reatribuir produtos)
$termoParaNicho = [
    // Vestuário infantil (antes de termos genéricos "infantil"/"criancas")
    'roupa infantil' => 'moda-infantil', 'moda infantil' => 'moda-infantil', 'vestido infantil' => 'moda-infantil',
    'conjunto infantil' => 'moda-infantil', 'camiseta infantil' => 'moda-infantil', 'calcado infantil' => 'moda-infantil',
    'calçado infantil' => 'moda-infantil',
    'beleza' => 'beleza-cuidados-saude', 'cuidados' => 'beleza-cuidados-saude', 'saude' => 'beleza-cuidados-saude', 'bem-estar' => 'beleza-cuidados-saude', 'suplementos' => 'beleza-cuidados-saude', 'nutricionais' => 'beleza-cuidados-saude', 'alimentares' => 'beleza-cuidados-saude', 'fitness' => 'beleza-cuidados-saude',
    'tecnologia' => 'tecnologia-eletronicos', 'eletronico' => 'tecnologia-eletronicos', 'celular' => 'tecnologia-eletronicos', 'smartphone' => 'tecnologia-eletronicos', 'notebook' => 'tecnologia-eletronicos', 'informatica' => 'tecnologia-eletronicos', 'game' => 'tecnologia-eletronicos', 'games' => 'tecnologia-eletronicos', 'jogo' => 'tecnologia-eletronicos', 'tv' => 'tecnologia-eletronicos', 'monitor' => 'tecnologia-eletronicos',
    'brinquedos' => 'brinquedos-bebes-criancas', 'bebes' => 'brinquedos-bebes-criancas', 'criancas' => 'brinquedos-bebes-criancas', 'piscinas' => 'brinquedos-bebes-criancas',
    'casa' => 'casa-cozinha-decoracao', 'cozinha' => 'casa-cozinha-decoracao', 'decoracao' => 'casa-cozinha-decoracao', 'moveis' => 'casa-cozinha-decoracao', 'construcao' => 'casa-cozinha-decoracao', 'jardim' => 'casa-cozinha-decoracao', 'eletrodomesticos' => 'casa-cozinha-decoracao', 'iluminacao' => 'casa-cozinha-decoracao', 'limpeza' => 'casa-cozinha-decoracao', 'lavanderia' => 'casa-cozinha-decoracao',
    'estilo' => 'estilo-vida-hobbies', 'hobbies' => 'estilo-vida-hobbies', 'livros' => 'estilo-vida-hobbies', 'esportes' => 'estilo-vida-hobbies', 'lazer' => 'estilo-vida-hobbies', 'pet' => 'estilo-vida-hobbies', 'agro' => 'estilo-vida-hobbies', 'papelaria' => 'estilo-vida-hobbies', 'escolar' => 'estilo-vida-hobbies',
    'masculina' => 'moda-masculina', 'masculino' => 'moda-masculina',
    'feminina' => 'moda-feminina', 'feminino' => 'moda-feminina', 'intima' => 'produtos-intimos', 'intimos' => 'produtos-intimos', 'lingerie' => 'produtos-intimos',
    'moda' => 'moda-feminina',
    'calcados' => 'moda-feminina', 'acessorios' => 'moda-feminina', 'esportivos' => 'moda-masculina',
    'automotivo' => 'automotivo-ferramentas', 'veiculos' => 'automotivo-ferramentas', 'supermercados' => 'automotivo-ferramentas', 'ferramentas' => 'automotivo-ferramentas', 'eletricas' => 'automotivo-ferramentas',
];

$message = '';
$messageType = '';
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    $pdo = getDB();
    
    // 1) Garantir que as categorias (nichos) existem
    $idsPorSlug = [];
    $ordem = 0;
    foreach ($NICHOS as $slug => $nome) {
        $ordem++;
        $st = $pdo->prepare("SELECT id FROM categorias WHERE slug = ?");
        $st->execute([$slug]);
        $row = $st->fetch();
        if ($row) {
            $idsPorSlug[$slug] = (int)$row['id'];
            $pdo->prepare("UPDATE categorias SET nome = ?, ordem = ?, ativo = 1 WHERE id = ?")->execute([$nome, $ordem, $idsPorSlug[$slug]]);
        } else {
            $pdo->prepare("INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, 1)")->execute([$nome, $slug, $ordem]);
            $idsPorSlug[$slug] = (int)$pdo->lastInsertId();
        }
    }
    if (function_exists('achadinhosGarantirHierarquiaPadrao')) {
        achadinhosGarantirHierarquiaPadrao($pdo);
    } elseif (function_exists('achadinhosGarantirHierarquiaModa')) {
        achadinhosGarantirHierarquiaModa($pdo);
    }
    $details[] = count($NICHOS) . ' nichos garantidos e hierarquia padrão (Moda, Beleza, etc.) aplicada.';

    // 2) Mapear cada categoria antiga para um nicho (por slug e nome)
    $categoriasAntigas = $pdo->query("SELECT id, nome, slug FROM categorias")->fetchAll(PDO::FETCH_ASSOC);
    $categoriaAntigaParaNicho = [];
    $slugTudoEmUm = 'tudo-em-um';
    $idTudoEmUm = $idsPorSlug[$slugTudoEmUm];

    foreach ($categoriasAntigas as $cat) {
        $slug = $cat['slug'];
        if (isset($idsPorSlug[$slug])) {
            $categoriaAntigaParaNicho[(int)$cat['id']] = $slug;
            continue;
        }
        $nomeNorm = mb_strtolower($cat['nome']);
        $nomeNorm = strtr($nomeNorm, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
        $slugNorm = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slugNorm = trim($slugNorm, '-');
        $encontrado = null;
        foreach ($termoParaNicho as $termo => $nichoSlug) {
            if (strpos($slugNorm, $termo) !== false || strpos($nomeNorm, $termo) !== false) {
                $encontrado = $nichoSlug;
                break;
            }
        }
        $categoriaAntigaParaNicho[(int)$cat['id']] = $encontrado ?? $slugTudoEmUm;
    }

    // 3) Atualizar produtos: categoria_id -> id do nicho
    $atualizados = 0;
    foreach ($categoriaAntigaParaNicho as $catId => $nichoSlug) {
        if (!isset($idsPorSlug[$nichoSlug])) continue;
        $novoId = $idsPorSlug[$nichoSlug];
        if ($catId === $novoId) continue;
        $st = $pdo->prepare("UPDATE produtos SET categoria_id = ? WHERE categoria_id = ?");
        $st->execute([$novoId, $catId]);
        $atualizados += $st->rowCount();
    }
    $details[] = $atualizados . ' produtos reatribuídos.';

    // 4) Deletar categorias que não são um dos nichos fixos
    $idsNichos = array_values($idsPorSlug);
    $placeholders = implode(',', array_fill(0, count($idsNichos), '?'));
    $st = $pdo->prepare("SELECT id FROM categorias WHERE id NOT IN ($placeholders)");
    $st->execute($idsNichos);
    $paraDeletar = $st->fetchAll(PDO::FETCH_COLUMN);
    $deletados = 0;
    foreach ($paraDeletar as $id) {
        $pdo->prepare("UPDATE produtos SET categoria_id = ? WHERE categoria_id = ?")->execute([$idTudoEmUm, $id]);
        $pdo->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
        $deletados++;
    }
    $details[] = $deletados . ' categorias duplicadas removidas.';

    $message = 'Consolidação concluída. ' . implode(' ', $details);
    $messageType = 'success';
}

$pdo = getDB();
$totalCategorias = $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn();
$pageTitle = 'Consolidar Categorias';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Consolidar categorias (nichos fixos)</h1>
            <p class="text-gray-600 mb-8">Reduz todas as categorias aos nichos fixos (inclui Moda, Moda infantil e subcategorias de moda) e reatribui os produtos. Categorias duplicadas serão removidas.</p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Categorias (nichos)</h2>
                <ul class="list-disc list-inside space-y-1 text-gray-700">
                    <li>Beleza, Cuidados Pessoais, Saúde e Bem-estar</li>
                    <li>Tecnologia, Eletrônicos, Games, Telefones, Jogos</li>
                    <li>Brinquedos, Jogos, Bebês e Crianças</li>
                    <li>Casa, Cozinha, Decoração, Móveis, Construção Civil</li>
                    <li>Estilo de Vida, Hobbies, Livros, Esportes e Fitness, Pets, Agro</li>
                    <li>Moda (agrupamento)</li>
                    <li>Moda Masculina, Calçados, Esportes, Acessórios</li>
                    <li>Moda Feminina, Calçados, Esportes, Acessórios</li>
                    <li>Moda Infantil, Calçados, Esportes, Acessórios</li>
                    <li>Automotivo, Acessórios Veículos, Supermercados, Ferramentas</li>
                    <li>Tudo em um</li>
                    <li>Produtos Íntimos +18</li>
                </ul>
            </div>

            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                <p class="text-yellow-800 font-medium">Categorias atuais: <strong><?php echo (int)$totalCategorias; ?></strong></p>
                <p class="text-yellow-700 text-sm mt-2">Ao executar, todos os produtos serão reatribuídos a uma das categorias acima (por similaridade). As demais categorias serão excluídas.</p>
            </div>

            <form method="POST">
                <button type="submit" name="executar" value="1" 
                        onclick="return confirm('Tem certeza? Isso vai reatribuir todos os produtos e remover categorias duplicadas.');"
                        class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                    Executar Consolidação
                </button>
            </form>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
