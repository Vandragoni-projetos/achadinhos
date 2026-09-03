<?php
/**
 * Executar migrations pendentes
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    $pdo = getDB();
    $migrationFile = __DIR__ . '/../migrations/add_whatsapp_grupo_categorias.sql';
    
    if (is_file($migrationFile)) {
        try {
            // Verificar se campo já existe
            $check = $pdo->query("SHOW COLUMNS FROM categorias LIKE 'whatsapp_grupo'")->fetch();
            if ($check) {
                $message = 'Migration já foi executada anteriormente. Campo whatsapp_grupo já existe.';
                $messageType = 'info';
            } else {
                // Executar migration
                $sql = file_get_contents($migrationFile);
                $pdo->exec($sql);
                $message = 'Migration executada com sucesso! Campo whatsapp_grupo adicionado à tabela categorias.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Erro ao executar migration: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
        }
    } else {
        $message = 'Arquivo de migration não encontrado.';
        $messageType = 'error';
    }
}

$pageTitle = 'Executar Migration';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-8">Executar Migration</h1>
            
            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php 
                echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 
                    ($messageType === 'error' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'); 
            ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Adicionar campo whatsapp_grupo</h2>
                <p class="text-gray-600 mb-4">
                    Esta migration adiciona o campo <code>whatsapp_grupo</code> na tabela <code>categorias</code>.
                    Isso permite definir um grupo específico do WhatsApp para cada categoria.
                </p>
                
                <?php
                $pdo = getDB();
                try {
                    $check = $pdo->query("SHOW COLUMNS FROM categorias LIKE 'whatsapp_grupo'")->fetch();
                    $jaExiste = (bool)$check;
                } catch (Exception $e) {
                    $jaExiste = false;
                }
                ?>
                
                <?php if ($jaExiste): ?>
                <div class="p-4 bg-green-50 border border-green-200 rounded mb-4">
                    <p class="text-green-800">✓ Campo <code>whatsapp_grupo</code> já existe na tabela categorias.</p>
                </div>
                <?php else: ?>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded mb-4">
                    <p class="text-yellow-800">⚠ Campo <code>whatsapp_grupo</code> ainda não existe. Execute a migration para adicioná-lo.</p>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <button type="submit" name="executar" value="1" 
                            class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Executar Migration
                    </button>
                </form>
            </div>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
