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

$action = strtolower(trim((string) ($body['action'] ?? '')));
$jobId = preg_replace('/\D/', '', (string) ($body['job_id'] ?? ''));

if ($action === 'get') {
    if ($jobId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'job_id obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = cronJobObterDetalhesJob($jobId);
    if (empty($r['success']) || !is_array($r['job'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => (string) ($r['message'] ?? 'Falha ao obter job')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $jf = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jf |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['success' => true, 'job' => $r['job']], $jf);
    exit;
}

if ($action === 'patch') {
    if ($jobId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'job_id obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $delta = [];

    if (array_key_exists('title', $body)) {
        $delta['title'] = trim((string) $body['title']);
    }
    if (array_key_exists('url', $body)) {
        $u = trim((string) $body['url']);
        if ($u === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'URL não pode ficar vazia.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $delta['url'] = $u;
    }
    if (array_key_exists('enabled', $body)) {
        $delta['enabled'] = !empty($body['enabled']);
    }
    if (!empty($body['update_schedule'])) {
        $iv = CronPolicy::normalizeInterval((int) ($body['intervalo_minutos'] ?? 5));
        $h1 = max(0, min(23, (int) ($body['hora_inicio'] ?? 8)));
        $h2 = max(0, min(23, (int) ($body['hora_fim'] ?? 22)));
        $delta['schedule'] = cronJobOrgSchedulePayload($iv, $h1, $h2);
    }

    if ($delta === []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Informe ao menos um campo para atualizar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r = cronJobAtualizarNaOrg($jobId, $delta);
    if (empty($r['success'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => (string) ($r['message'] ?? 'Falha ao atualizar job')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Job atualizado na cron-job.org.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    if ($jobId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'job_id obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $jidNorm = preg_replace('/\D/', '', $jobId);
    if (function_exists('cronJobOrgIntegrationDebugLog')) {
        cronJobOrgIntegrationDebugLog('manage_delete_request', [
            'hypothesisId' => 'MANAGE_DEL',
            'job_id_len' => strlen($jidNorm),
        ]);
    }
    $r = cronJobDelete($jobId);
    if (empty($r['success'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => (string) ($r['message'] ?? 'Falha ao excluir job')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $g = preg_replace('/\D/', '', trim((string) getConfig('cron_global_job_id', '')));
    if ($g !== '' && $g === $jidNorm) {
        setConfig('cron_global_job_id', '');
    }
    $lojas = ['ml', 'shopee', 'magalu', 'amazon', 'shein', 'aliexpress', 'ml_cupons'];
    foreach ($lojas as $loja) {
        $cfg = dadosCronLoja($loja);
        $cj = preg_replace('/\D/', '', trim((string) ($cfg['cron_job_id'] ?? '')));
        if ($cj !== '' && $cj === $jidNorm) {
            salvarCronExternoLoja($loja, array_merge($cfg, ['cron_job_id' => '']));
        }
    }

    $gruposAfetados = 0;
    try {
        $pdo = getDB();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM grupos_whatsapp WHERE TRIM(COALESCE(cron_job_org_job_id, \'\')) = ?');
        $cnt->execute([$jidNorm]);
        $gruposAfetados = (int) $cnt->fetchColumn();
        $gw = $pdo->prepare('UPDATE grupos_whatsapp SET cron_job_org_job_id = NULL WHERE TRIM(COALESCE(cron_job_org_job_id, \'\')) = ?');
        $gw->execute([$jidNorm]);
    } catch (Throwable $e) {
        // Job já remoto; BD opcional
    }
    if (function_exists('cronJobOrgIntegrationDebugLog')) {
        cronJobOrgIntegrationDebugLog('manage_delete_done', [
            'hypothesisId' => 'MANAGE_DEL',
            'job_id_len' => strlen($jidNorm),
            'grupos_whatsapp_rows_cleared' => $gruposAfetados,
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Job removido na cron-job.org e referências locais atualizadas.' . ($gruposAfetados > 0 ? ' (' . $gruposAfetados . ' regra(s) em Grupos perderam o ID vinculado.)' : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida. Use "get", "patch" ou "delete".'], JSON_UNESCAPED_UNICODE);
