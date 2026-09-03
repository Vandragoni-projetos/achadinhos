<?php
/**
 * Teste manual de envio (WhatsApp / Telegram) com captura de logs do dispatch.
 * Não altera automações nem cron.
 */
$pageTitle = 'Teste de envio';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/automacao-ml.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$runResult = null;
$captureLogs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_teste_envio'])) {
    $GLOBALS['dispatch_capture_logs'] = [];

    $mensagem = 'Teste de envio ' . date('Y-m-d H:i:s');
    $dispatches = dispatch_habilitado() ? get_active_dispatches($adminId) : [
        'whatsapp' => [],
        'telegram' => [],
    ];

    $resumo = [];
    $errosWa = [];
    $errosTg = [];
    $waEnviados = 0;
    $evoFallback = null;

    $useWaDispatch = function_exists('dispatch_whatsapp_tem_destinos') && dispatch_whatsapp_tem_destinos($dispatches['whatsapp']);
    $useTgDispatch = function_exists('dispatch_telegram_tem_destinos') && dispatch_telegram_tem_destinos($dispatches['telegram']);

    if ($useWaDispatch) {
        dispatch_executar_whatsapp_destinos(
            $dispatches['whatsapp'],
            'teste_envio',
            1,
            '',
            static function ($idx, $total) use ($mensagem) {
                return $mensagem;
            },
            $errosWa,
            $waEnviados,
            $evoFallback,
            null,
            true
        );
        $resumo[] = 'WhatsApp (dispatch): ' . $waEnviados . ' envio(s) bem-sucedido(s).';
    } else {
        $gruposLegacy = [];
        try {
            $pdo = getDB();
            $st = $pdo->query("
                SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, e.url_base, e.instancia, e.api_key,
                       COALESCE(e.provedor, 'evolution') AS provedor, e.uazapi_admin_token
                FROM grupos_whatsapp g
                INNER JOIN evolution_contas e ON g.evolution_conta_id = e.id
                WHERE g.ativo = 1 AND e.ativo = 1
                ORDER BY g.nome
            ");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $gruposLegacy[] = $row;
            }
        } catch (Exception $e) {
            $errosWa[] = 'WhatsApp (legado): ' . $e->getMessage();
        }
        if (empty($gruposLegacy)) {
            $resumo[] = 'WhatsApp (legado): nenhum grupo ativo com conta Evolution ativa vinculada.';
        } else {
            $n = count($gruposLegacy);
            foreach ($gruposLegacy as $i => $row) {
                $err = '';
                $evo = [
                    'url_base' => rtrim((string) $row['url_base'], '/'),
                    'instancia' => (string) $row['instancia'],
                    'api_key' => (string) $row['api_key'],
                    'provedor' => $row['provedor'] ?? 'evolution',
                    'uazapi_admin_token' => (string) ($row['uazapi_admin_token'] ?? ''),
                ];
                $ok = enviarWhatsAppMensagem($evo, (string) $row['grupo_id'], $mensagem, '', $err);
                if ($ok) {
                    $waEnviados++;
                    if ($evoFallback === null) {
                        $evoFallback = $evo;
                    }
                } else {
                    $errosWa[] = 'Grupo ' . ($row['nome'] ?? $row['grupo_id']) . ': ' . $err;
                }
                if ($i < $n - 1) {
                    sleep(1);
                }
            }
            $resumo[] = 'WhatsApp (legado): ' . $waEnviados . ' de ' . $n . ' envio(s).';
        }
    }

    if ($useTgDispatch) {
        dispatch_executar_telegram_destinos($dispatches['telegram'], $mensagem, null, $errosTg);
        $resumo[] = 'Telegram (dispatch): processado (ver erros se houver).';
    } elseif (getConfig('telegram_ativo', '0') === '1' && function_exists('enviarTelegram')) {
        $errTg = '';
        if (enviarTelegram($mensagem, null, $errTg)) {
            $resumo[] = 'Telegram (config): enviado.';
        } else {
            $errosTg[] = $errTg ?: 'Falha desconhecida';
            $resumo[] = 'Telegram (config): falhou.';
        }
    } else {
        $resumo[] = 'Telegram: desativado ou sem destinos em dispatch.';
    }

    $captureLogs = $GLOBALS['dispatch_capture_logs'];
    unset($GLOBALS['dispatch_capture_logs']);

    $temErro = !empty($errosWa) || !empty($errosTg);

    $runResult = [
        'mensagem' => $mensagem,
        'resumo' => $resumo,
        'erros_wa' => $errosWa,
        'erros_tg' => $errosTg,
        'modo_wa' => $useWaDispatch ? 'dispatch' : 'legado',
        'modo_tg' => $useTgDispatch ? 'dispatch' : (getConfig('telegram_ativo', '0') === '1' ? 'config' : 'off'),
        'wa_enviados' => $waEnviados,
        'tem_erro' => $temErro,
    ];
}

require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Teste de envio</h1>
            <p class="text-sm text-gray-600 mb-6">
                Usa o admin logado (ID <?php echo (int) $adminId; ?>) em <code class="bg-gray-100 px-1 rounded text-xs">get_active_dispatches</code>.
                Legado WhatsApp: grupos ativos com Evolution ativa vinculada.
            </p>

            <form method="post" action="teste-envio.php" class="mb-8">
                <input type="hidden" name="run_teste_envio" value="1">
                <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-semibold py-2.5 px-5 rounded-lg text-sm">
                    Executar teste de envio
                </button>
            </form>

            <?php if ($runResult !== null): ?>
            <div class="space-y-6">
                <?php
                $resumoTxt = implode(' ', $runResult['resumo']);
                $waOk = (int) ($runResult['wa_enviados'] ?? 0) > 0;
                $tgMencionaOk = strpos($resumoTxt, 'enviado') !== false;
                $tgDispatchOk = ($runResult['modo_tg'] === 'dispatch' && empty($runResult['erros_tg']));
                $destaqueOk = $waOk || $tgMencionaOk || $tgDispatchOk;
                ?>
                <div class="rounded-xl border p-5 <?php echo !$destaqueOk || $runResult['tem_erro'] ? 'bg-amber-50 border-amber-200' : 'bg-white border-gray-100 shadow-sm'; ?>">
                    <h2 class="text-lg font-semibold text-gray-800 mb-3">Resultado</h2>
                    <p class="text-sm mb-2 <?php echo $destaqueOk && !$runResult['tem_erro'] ? 'text-emerald-700 font-medium' : ($runResult['tem_erro'] ? 'text-red-700 font-medium' : 'text-amber-800'); ?>">
                        <?php
                        if ($runResult['tem_erro']) {
                            echo 'Concluído com erro(s) — ver lista abaixo.';
                        } elseif ($destaqueOk) {
                            echo 'Envio(s) processado(s) com sucesso (parcial ou total).';
                        } else {
                            echo 'Nenhum envio bem-sucedido detectado; verifique dispatches, grupos, Evolution e Telegram.';
                        }
                        ?>
                    </p>
                    <p class="text-sm text-gray-600 mb-2"><span class="font-medium">Mensagem:</span> <?php echo htmlspecialchars($runResult['mensagem']); ?></p>
                    <p class="text-sm text-gray-600 mb-2"><span class="font-medium">Modo WhatsApp:</span> <?php echo htmlspecialchars($runResult['modo_wa']); ?></p>
                    <p class="text-sm text-gray-600 mb-3"><span class="font-medium">Modo Telegram:</span> <?php echo htmlspecialchars($runResult['modo_tg']); ?></p>
                    <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                        <?php foreach ($runResult['resumo'] as $linha): ?>
                        <li><?php echo htmlspecialchars($linha); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if (!empty($runResult['erros_wa']) || !empty($runResult['erros_tg'])): ?>
                <div class="bg-red-50 border border-red-100 rounded-xl p-5">
                    <h2 class="text-lg font-semibold text-red-800 mb-2">Erros</h2>
                    <?php if (!empty($runResult['erros_wa'])): ?>
                    <p class="text-xs font-semibold text-red-700 uppercase mb-1">WhatsApp</p>
                    <ul class="text-sm text-red-800 list-disc list-inside mb-3">
                        <?php foreach ($runResult['erros_wa'] as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (!empty($runResult['erros_tg'])): ?>
                    <p class="text-xs font-semibold text-red-700 uppercase mb-1">Telegram</p>
                    <ul class="text-sm text-red-800 list-disc list-inside">
                        <?php foreach ($runResult['erros_tg'] as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div>
                    <h2 class="text-sm font-semibold text-gray-700 mb-2">Logs do dispatch (esta requisição)</h2>
                    <pre class="bg-slate-900 text-slate-100 rounded-xl p-4 text-xs font-mono overflow-x-auto whitespace-pre-wrap break-words"><?php
                        echo htmlspecialchars(empty($captureLogs) ? '(sem linhas — fluxo sem dispatch_log ou sem destinos dispatch)' : implode("\n", $captureLogs));
                    ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
