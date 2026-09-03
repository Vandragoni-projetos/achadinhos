<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../core/cron/CronJobService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

startSession();
$expected = $_SESSION['admin_autosave_token'] ?? '';
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';

$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$tipo = trim((string) ($body['tipo'] ?? ''));
if ($tipo === 'global') {
    try {
        // 1) Execução real do cron global (forçado)
        $prevGet = $_GET ?? [];
        $prevAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $_GET['forcar'] = '1';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        if (!defined('ACHADINHOS_CRON_RODAR_TUDO_INTERNAL')) {
            define('ACHADINHOS_CRON_RODAR_TUDO_INTERNAL', true);
        }
        ob_start();
        require __DIR__ . '/../../cron/rodar-tudo.php';
        $execOut = trim((string) ob_get_clean());
        $_GET = $prevGet;
        $_SERVER['HTTP_ACCEPT'] = $prevAccept;

        // 2) Não sincroniza job global na API: agendamentos são por grupo (rodar-grupo.php).
        $apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
        $syncStatus = 'ignorada';
        $jobId = '';
        $syncMsg = 'Sem API key na configuração.';
        if ($apiKey !== '') {
            $syncStatus = 'ok';
            $syncMsg = 'Execução global concluída. Integração API: jobs por grupo (sem sincronizar rodar-tudo na cron-job.org).';
            setConfig('cron_global_sync_last_error', '');
        } else {
            $syncMsg = 'Sem API key; apenas a execução local foi feita.';
        }

        echo achadinhosCronJsonEncode([
            'success' => true,
            'execucao' => 'ok',
            'sincronizacao' => $syncStatus,
            'sincronizacao_msg' => $syncMsg,
            'job_id' => $jobId,
            'resultados' => $execOut !== '' ? $execOut : null,
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo achadinhosCronJsonEncode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($tipo === 'loja') {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['loja'] ?? '')));
    if ($loja === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Loja inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $cfg = dadosCronLoja($loja);
        if (empty($cfg['token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Token da loja não configurado para execução interna.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 1) Execução real do cron da loja (forçado)
        $prevGet = $_GET ?? [];
        $_GET['loja'] = $loja;
        $_GET['token'] = (string) $cfg['token'];
        $_GET['forcar'] = '1';
        ob_start();
        require __DIR__ . '/../../cron/rodar-loja.php';
        $execOut = trim((string) ob_get_clean());
        $_GET = $prevGet;

        // 2) Sincronização cron-job.org (se API key existir)
        $apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
        $syncStatus = 'ignorada';
        $jobId = '';
        $syncMsg = 'Sem API key; sincronização não executada.';
        if ($apiKey !== '') {
            $cfg = dadosCronLoja($loja);
            $sync = cronJobSincronizarLoja($loja, $cfg);
            if (empty($sync['success'])) {
                http_response_code(400);
                echo achadinhosCronJsonEncode([
                    'success' => false,
                    'execucao' => 'ok',
                    'sincronizacao' => 'erro',
                    'error' => (string) ($sync['message'] ?? 'Falha ao sincronizar.'),
                ]);
                exit;
            }
            $jobId = (string) ($sync['job_id'] ?? '');
            if ($jobId !== '' && $jobId !== (string) ($cfg['cron_job_id'] ?? '')) {
                salvarCronExternoLoja($loja, array_merge($cfg, ['cron_job_id' => $jobId]));
            }
            $syncStatus = 'ok';
            $syncMsg = (string) ($sync['message'] ?? 'Sincronização concluída.');
        }

        echo achadinhosCronJsonEncode([
            'success' => true,
            'execucao' => 'ok',
            'sincronizacao' => $syncStatus,
            'sincronizacao_msg' => $syncMsg,
            'job_id' => $jobId,
            'resultados' => $execOut !== '' ? $execOut : null,
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo achadinhosCronJsonEncode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Tipo inválido. Use "global" ou "loja".'], JSON_UNESCAPED_UNICODE);
