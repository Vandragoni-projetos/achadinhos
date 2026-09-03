<?php
$pageTitle = 'Dispatches';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

startSession();
if (!isset($_SESSION['csrf_dispatches'])) {
    $_SESSION['csrf_dispatches'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_dispatches'];

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$pdo = getDB();
$message = '';
$messageType = '';

$tablesOk = false;
$schemaOk = false;
try {
    $pdo->query('SELECT 1 FROM dispatches LIMIT 1');
    $tablesOk = true;
    $pdo->query('SELECT metadata FROM dispatches LIMIT 1');
    $schemaOk = true;
} catch (PDOException $e) {
    if (!$tablesOk) {
        $message = 'Execute a migration: migrations/add_dispatches.sql';
        $messageType = 'error';
    } else {
        $message = 'Atualize o schema: migrations/alter_dispatches_metadata_unique.sql';
        $messageType = 'error';
    }
}

if ($tablesOk && $schemaOk && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatch_toggle'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = 'Token de segurança inválido. Atualize a página e tente de novo.';
        $messageType = 'error';
    } else {
        $tid = (int)($_POST['dispatch_id'] ?? 0);
        if ($tid > 0) {
            try {
                $st = $pdo->prepare('SELECT id, ativo FROM dispatches WHERE id = ? AND user_id = ?');
                $st->execute([$tid, $adminId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $novo = (int)$row['ativo'] ? 0 : 1;
                    $pdo->prepare('UPDATE dispatches SET ativo = ? WHERE id = ? AND user_id = ?')->execute([$novo, $tid, $adminId]);
                    header('Location: dispatches.php');
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar: ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    }
}

if ($tablesOk && $schemaOk && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatch_create'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = 'Token de segurança inválido. Atualize a página e tente de novo.';
        $messageType = 'error';
    } else {
        $canal = $_POST['canal'] ?? '';
        if (!in_array($canal, ['whatsapp', 'telegram'], true)) {
            $message = 'Canal inválido.';
            $messageType = 'error';
        } else {
            $conta_id = trim((string)($_POST['conta_id'] ?? ''));
            $grupo_id = trim((string)($_POST['grupo_id'] ?? ''));
            $prioridade = isset($_POST['prioridade']) && $_POST['prioridade'] !== '' ? (int)$_POST['prioridade'] : 1;
            $prioridade = max(0, min(9999, $prioridade));
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            $metadataRaw = trim((string)($_POST['metadata'] ?? ''));
            $metadataSql = null;
            if ($metadataRaw !== '') {
                json_decode($metadataRaw);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $message = 'Metadata deve ser JSON válido.';
                    $messageType = 'error';
                } else {
                    $metadataSql = $metadataRaw;
                }
            }
            if ($messageType !== 'error' && ($conta_id === '' || $grupo_id === '')) {
                $message = 'Conta ID e Grupo ID são obrigatórios.';
                $messageType = 'error';
            }
            if ($messageType !== 'error') {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO dispatches (user_id, canal, conta_id, grupo_id, ativo, prioridade, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$adminId, $canal, $conta_id, $grupo_id, $ativo, $prioridade, $metadataSql]);
                    header('Location: dispatches.php?saved=1');
                    exit;
                } catch (PDOException $e) {
                    $sqlState = $e->errorInfo[0] ?? '';
                    if ($sqlState === '23000' || stripos($e->getMessage(), 'Duplicate') !== false) {
                        $message = 'Já existe um dispatch para este canal, conta e grupo.';
                    } else {
                        $message = 'Erro ao salvar: ' . htmlspecialchars($e->getMessage());
                    }
                    $messageType = 'error';
                }
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Dispatch criado com sucesso.';
    $messageType = 'success';
}

$dispatches = [];
if ($tablesOk && $schemaOk) {
    try {
        $st = $pdo->prepare('SELECT * FROM dispatches WHERE user_id = ? ORDER BY canal, prioridade ASC, id ASC');
        $st->execute([$adminId]);
        $dispatches = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $dispatches = [];
    }
}

require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h1 class="text-2xl font-bold text-gray-800">Dispatches</h1>
                <a href="configuracoes.php?tab=telegram"
                   class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-orange-500">Voltar</a>
            </div>
            <p class="text-sm text-gray-600 mb-6">Estrutura de destinos por canal (não altera envio atual das automações).</p>

            <?php if ($message): ?>
            <div class="mb-4 p-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <?php if ($tablesOk && $schemaOk): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800">Listagem</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50/80">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Canal</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Conta</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Grupo</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase w-20">Prio.</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase w-16">Meta</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase w-24">Ativo</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase w-32">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (empty($dispatches)): ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-400">Nenhum dispatch. Use o formulário ao lado.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($dispatches as $d): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-3 py-2 text-gray-600"><?php echo (int)$d['id']; ?></td>
                                    <td class="px-3 py-2"><?php echo htmlspecialchars($d['canal']); ?></td>
                                    <td class="px-3 py-2 font-mono text-xs truncate max-w-[100px]" title="<?php echo htmlspecialchars($d['conta_id']); ?>"><?php echo htmlspecialchars($d['conta_id']); ?></td>
                                    <td class="px-3 py-2 font-mono text-xs truncate max-w-[140px]" title="<?php echo htmlspecialchars($d['grupo_id']); ?>"><?php echo htmlspecialchars($d['grupo_id']); ?></td>
                                    <td class="px-3 py-2 text-center"><?php echo (int)$d['prioridade']; ?></td>
                                    <td class="px-3 py-2 text-center text-gray-500"><?php echo !empty($d['metadata']) ? 'Sim' : '—'; ?></td>
                                    <td class="px-3 py-2 text-center">
                                        <?php if ((int)$d['ativo']): ?>
                                        <span class="text-[10px] font-medium px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">Sim</span>
                                        <?php else: ?>
                                        <span class="text-[10px] font-medium px-2 py-0.5 rounded bg-gray-100 text-gray-500">Não</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="post" action="dispatches.php" class="inline">
                                            <input type="hidden" name="dispatch_toggle" value="1">
                                            <input type="hidden" name="dispatch_id" value="<?php echo (int)$d['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <button type="submit" class="text-orange-600 hover:text-orange-700 text-xs font-medium bg-transparent border-0 cursor-pointer p-0"><?php echo (int)$d['ativo'] ? 'Desativar' : 'Ativar'; ?></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">Novo dispatch</h2>
                    <form method="post" action="dispatches.php" class="space-y-4">
                        <input type="hidden" name="dispatch_create" value="1">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Canal</label>
                            <select name="canal" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="telegram">Telegram</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Conta ID</label>
                            <input type="text" name="conta_id" required maxlength="64" placeholder="ex: 1 (Evolution)"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Grupo ID</label>
                            <input type="text" name="grupo_id" required maxlength="255" placeholder="JID ou chat ID"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Prioridade</label>
                            <input type="number" name="prioridade" value="1" min="0" max="9999"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Metadata (JSON opcional)</label>
                            <textarea name="metadata" rows="3" placeholder='{"chave":"valor"}'
                                      class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500"></textarea>
                        </div>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" name="ativo" value="1" checked class="rounded text-orange-500">
                            Ativo
                        </label>
                        <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold py-2.5 rounded-lg">Cadastrar</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
