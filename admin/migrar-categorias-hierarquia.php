<?php
/**
 * Migração segura de hierarquia (parent_id): sem DELETE automático.
 *
 * 1) Garante categorias base e hierarquia padrão.
 * 2) Exibe possíveis duplicatas por slug normalizado (apenas leitura).
 * 3) Aplica um mapa manual de parent_id (filho_id => pai_id ou filho_slug => pai_slug).
 *
 * Edite os arrays $CORRECOES_PARENT_POR_ID e/ou $CORRECOES_PARENT_POR_SLUG abaixo
 * após inspecionar a tabela e os grupos duplicados.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

/**
 * Ex.: [ 15 => 3 ] define parent_id = 3 para a categoria id 15.
 * Use ids reais do seu banco (consulte a listagem abaixo).
 */
$CORRECOES_PARENT_POR_ID = [
    // 99 => 10,
];

/**
 * Ex.: [ 'moda-masculina-antiga' => 'moda' ] — resolve ids por slug no momento da execução.
 */
$CORRECOES_PARENT_POR_SLUG = [
    // 'suplementos-nutricionais' => 'beleza-cuidados-saude',
];

$message = '';
$messageType = '';
$duplicatasSlug = [];
$todas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    $pdo = getDB();
    try {
        if (function_exists('garantirHierarquiaCategoriasBase')) {
            garantirHierarquiaCategoriasBase();
        }
        $atualizados = 0;
        $stUp = $pdo->prepare('UPDATE categorias SET parent_id = ? WHERE id = ?');

        foreach ($CORRECOES_PARENT_POR_ID as $filhoId => $paiId) {
            $filhoId = (int) $filhoId;
            $paiId = (int) $paiId;
            if ($filhoId <= 0) {
                continue;
            }
            $parentValor = $paiId > 0 ? $paiId : null;
            $stUp->execute([$parentValor, $filhoId]);
            $atualizados += $stUp->rowCount();
        }

        $stIdPorSlug = $pdo->prepare('SELECT id FROM categorias WHERE LOWER(TRIM(slug)) = ? LIMIT 1');
        foreach ($CORRECOES_PARENT_POR_SLUG as $slugFilho => $slugPai) {
            $sf = strtolower(trim((string) $slugFilho));
            $sp = strtolower(trim((string) $slugPai));
            if ($sf === '' || $sp === '') {
                continue;
            }
            $stIdPorSlug->execute([$sf]);
            $rowF = $stIdPorSlug->fetch(PDO::FETCH_ASSOC);
            $stIdPorSlug->execute([$sp]);
            $rowP = $stIdPorSlug->fetch(PDO::FETCH_ASSOC);
            if (!$rowF || !$rowP) {
                continue;
            }
            $fid = (int) $rowF['id'];
            $pid = (int) $rowP['id'];
            if ($fid === $pid) {
                continue;
            }
            $stUp->execute([$pid, $fid]);
            $atualizados += $stUp->rowCount();
        }

        $message = 'Atualização concluída. Linhas afetadas (UPDATE): ' . $atualizados . '. Nenhum registo foi apagado.';
        $messageType = 'success';
    } catch (Throwable $e) {
        $message = 'Erro: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$pdo = getDB();
try {
    $todas = $pdo->query('SELECT id, nome, slug, parent_id, ordem, ativo FROM categorias ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $dupRows = $pdo->query(
        'SELECT LOWER(TRIM(slug)) AS s, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
         FROM categorias
         GROUP BY LOWER(TRIM(slug))
         HAVING n > 1'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dupRows as $dr) {
        if ((int) ($dr['n'] ?? 0) > 1) {
            $duplicatasSlug[] = $dr;
        }
    }
} catch (Throwable $e) {
    $message = 'Erro ao ler categorias: ' . $e->getMessage();
    $messageType = 'error';
}

$pageTitle = 'Migrar hierarquia de categorias';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Migrar hierarquia de categorias</h1>
            <p class="text-gray-600 mb-6">
                Script seguro: apenas <code class="text-sm">UPDATE categorias SET parent_id</code> conforme mapas no ficheiro PHP.
                Não remove linhas. Edite <code class="text-sm">$CORRECOES_PARENT_POR_ID</code> e
                <code class="text-sm">$CORRECOES_PARENT_POR_SLUG</code> em
                <code class="text-sm">admin/migrar-categorias-hierarquia.php</code> e volte aqui para executar.
            </p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h2 class="text-lg font-semibold text-gray-800 mb-3">Possíveis duplicatas (mesmo slug normalizado)</h2>
                <?php if (empty($duplicatasSlug)): ?>
                <p class="text-gray-600">Nenhum grupo com slug duplicado encontrado.</p>
                <?php else: ?>
                <ul class="list-disc list-inside text-gray-700 text-sm space-y-1">
                    <?php foreach ($duplicatasSlug as $d): ?>
                    <li>slug <strong><?php echo htmlspecialchars((string) ($d['s'] ?? '')); ?></strong> — ocorrências: <?php echo (int) ($d['n'] ?? 0); ?> — ids: <?php echo htmlspecialchars((string) ($d['ids'] ?? '')); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-8 overflow-x-auto">
                <h2 class="text-lg font-semibold text-gray-800 mb-3">Todas as categorias (referência para o mapa)</h2>
                <table class="min-w-full text-sm border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left p-2 border-b">id</th>
                            <th class="text-left p-2 border-b">nome</th>
                            <th class="text-left p-2 border-b">slug</th>
                            <th class="text-left p-2 border-b">parent_id</th>
                            <th class="text-left p-2 border-b">ativo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todas as $c): ?>
                        <tr class="border-b border-gray-100">
                            <td class="p-2"><?php echo (int) $c['id']; ?></td>
                            <td class="p-2"><?php echo htmlspecialchars((string) ($c['nome'] ?? '')); ?></td>
                            <td class="p-2 font-mono text-xs"><?php echo htmlspecialchars((string) ($c['slug'] ?? '')); ?></td>
                            <td class="p-2"><?php echo $c['parent_id'] !== null && $c['parent_id'] !== '' ? (int) $c['parent_id'] : '—'; ?></td>
                            <td class="p-2"><?php echo !empty($c['ativo']) ? '1' : '0'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" class="bg-orange-50 border border-orange-200 rounded-lg p-6">
                <p class="text-gray-700 mb-4">Ao submeter, corre-se <code class="text-sm">garantirHierarquiaCategoriasBase()</code> e aplicam-se as correções definidas no PHP.</p>
                <button type="submit" name="executar" value="1" class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700">
                    Aplicar mapa de parent_id
                </button>
            </form>
        </main>
<?php
require_once __DIR__ . '/includes/footer.php';
