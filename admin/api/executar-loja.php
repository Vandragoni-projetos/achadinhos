<?php
declare(strict_types=1);

/**
 * Execução unificada por loja: validação leve → automação (forçada) → limpeza/cron loja (se individual) → sync cron-job.org (se aplicável).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../core/cron/CronJobService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

startSession();
$expected = $_SESSION['admin_autosave_token'] ?? '';
$hdr = $_SERVER['HTTP_X_AUTOSAVE_TOKEN'] ?? '';

$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $hdr !== '' ? trim((string) $hdr) : trim((string) ($body['token'] ?? ''));
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token inválido.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$loja = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['loja'] ?? '')));
$lojasPermitidas = ['ml', 'shopee', 'magalu', 'amazon', 'shein', 'aliexpress', 'ml_cupons'];
if (!in_array($loja, $lojasPermitidas, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Loja inválida.', 'phases' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$phases = [];

/**
 * @return array{ok: bool, erros: list<string>, avisos: list<string>}
 */
function executarLojaValidarAmbiente(string $loja): array {
    $erros = [];
    $avisos = [];
    $openai = trim((string) getConfig('openai_api_key', ''));
    if ($openai === '') {
        $erros[] = 'Configure a API OpenAI em Configurações → IA (chave global).';
    }
    $cfgCron = dadosCronLoja($loja);
    $iv = CronPolicy::normalizeInterval((int) ($cfgCron['intervalo_minutos'] ?? 5));
    if ($iv < 1) {
        $avisos[] = 'Intervalo de cron da loja fora do esperado; usando padrão ao sincronizar.';
    }
    if (!empty($cfgCron['cron_individual_ativo']) && trim((string) ($cfgCron['token'] ?? '')) === '') {
        $avisos[] = 'Cron individual ativo sem token seguro: defina um token na aba de execução/cron desta loja para URL pública.';
    }

    return ['ok' => $erros === [], 'erros' => $erros, 'avisos' => $avisos];
}

$val = executarLojaValidarAmbiente($loja);
$phases['validacao'] = [
    'ok' => $val['ok'],
    'erros' => $val['erros'],
    'avisos' => $val['avisos'],
];
if (!$val['ok']) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $val['erros']),
        'phases' => $phases,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

achadinhosPainelApiPrepararJobLongo();

$resultadoAuto = [
    'success' => false,
    'message' => 'Automação não executada.',
    'details' => [],
    'errors' => [],
];

try {
    switch ($loja) {
        case 'ml':
            require_once __DIR__ . '/../../config/automacao-ml.php';
            $resultadoAuto = runAutomacaoML(true);
            break;

        case 'shopee':
            require_once __DIR__ . '/../../config/automacao-shopee.php';
            $resultadoAuto = runAutomacaoShopee(true);
            break;

        case 'magalu':
            require_once __DIR__ . '/../../config/automacao-magalu.php';
            $resultadoAuto = runAutomacaoMagalu(true);
            if (!empty($resultadoAuto['success']) && getConfig('magalu_loja_automacao_ativa', '0') === '1') {
                require_once __DIR__ . '/../../config/automacao-magalu-loja.php';
                $rLoja = runAutomacaoMagaluLoja(true);
                if (empty($rLoja['success'])) {
                    $resultadoAuto['message'] .= ' | Loja Magazine: ' . (string) ($rLoja['message'] ?? 'falha');
                    $resultadoAuto['success'] = false;
                }
            }
            break;

        case 'amazon':
            require_once __DIR__ . '/../../config/automacao-amazon.php';
            $resultadoAuto = runAutomacaoAmazon(true);
            break;

        case 'shein':
            require_once __DIR__ . '/../../config/automacao-shein.php';
            $resultadoAuto = runAutomacaoShein(true);
            break;

        case 'aliexpress':
            require_once __DIR__ . '/../../config/automacao-aliexpress.php';
            $resultadoAuto = runAutomacaoAliExpress(true);
            break;

        case 'ml_cupons':
            require_once __DIR__ . '/../../config/automacao-cupons-ml.php';
            $resultadoAuto = runAutomacaoCuponsML(true);
            break;

        default:
            break;
    }
} catch (Throwable $e) {
    $resultadoAuto = [
        'success' => false,
        'message' => 'Exceção na automação: ' . $e->getMessage(),
        'details' => [],
        'errors' => [$e->getMessage()],
    ];
}

if (!is_array($resultadoAuto)) {
    $resultadoAuto = ['success' => false, 'message' => 'Resposta inválida da automação.', 'details' => [], 'errors' => []];
}

$phases['automacao'] = [
    'ok' => !empty($resultadoAuto['success']),
    'message' => (string) ($resultadoAuto['message'] ?? ''),
];

if (empty($resultadoAuto['success'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => (string) ($resultadoAuto['message'] ?? 'Falha na automação.'),
        'phases' => $phases,
        'details' => $resultadoAuto['details'] ?? [],
        'errors' => $resultadoAuto['errors'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = dadosCronLoja($loja);
$phases['cron_loja'] = ['ok' => true, 'skipped' => 'cron_global', 'message' => 'Loja usa cron global; limpeza por loja não aplicada neste fluxo.'];

if ((int) ($cfg['cron_individual_ativo'] ?? 0) === 1 && trim((string) ($cfg['token'] ?? '')) !== '') {
    try {
        $dias = max(1, min(365, (int) ($cfg['dias_remocao'] ?? 30)));
        $lim = cronExecutarLimpezaProdutosAntigos($dias);
        $tMs = 0;
        registrarExecucaoCron([
            'tipo' => 'loja',
            'loja' => $loja,
            'status' => !empty($lim['success']) ? 'sucesso' : 'erro',
            'mensagem' => 'Pós-automação (manual): limpeza produtos. ' . (string) ($lim['message'] ?? ''),
            'tempo_execucao' => $tMs,
        ]);
        $phases['cron_loja'] = [
            'ok' => !empty($lim['success']),
            'skipped' => false,
            'message' => (string) ($lim['message'] ?? ''),
        ];
    } catch (Throwable $e) {
        $phases['cron_loja'] = [
            'ok' => false,
            'skipped' => false,
            'message' => $e->getMessage(),
        ];
    }
}

$apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
$cfgSync = dadosCronLoja($loja);
$indivAtivo = (int) ($cfgSync['cron_individual_ativo'] ?? 0) === 1;
$tokLoja = trim((string) ($cfgSync['token'] ?? ''));
$cronsSetupRel = 'configuracoes.php?tab=crons';

if ($apiKey === '') {
    $phases['sync_org'] = [
        'ok' => false,
        'skipped' => true,
        'message' => 'A integração com a cron-job.org não está ativa: não há chave API guardada. Ative a API em Configurações → Crons para criar ou atualizar jobs automaticamente.',
        'crons_setup_url' => $cronsSetupRel,
    ];
} elseif (!$indivAtivo) {
    $phases['sync_org'] = [
        'ok' => true,
        'skipped' => true,
        'message' => 'Horário compartilhado: o agendamento na cron-job.org é feito por grupo (página Grupos — cron/rodar-grupo.php). Não há job global rodar-tudo.',
        'escopo' => 'grupos',
    ];
} elseif ($tokLoja === '') {
    $phases['sync_org'] = [
        'ok' => false,
        'skipped' => true,
        'message' => 'Horário exclusivo ativo: defina o token do cron desta loja (aba de execução/cron) para criar o job na cron-job.org (rodar-loja.php).',
        'crons_setup_url' => $cronsSetupRel,
        'escopo' => 'loja',
    ];
} else {
    $sync = cronJobSincronizarLoja($loja, $cfgSync);
    if (!empty($sync['success'])) {
        $jid = (string) ($sync['job_id'] ?? '');
        if ($jid !== '') {
            salvarCronExternoLoja($loja, array_merge($cfgSync, ['cron_job_id' => $jid]));
        }
        $phases['sync_org'] = [
            'ok' => true,
            'skipped' => !empty($sync['skipped']),
            'message' => (string) ($sync['message'] ?? 'Sincronizado.'),
            'job_id' => $jid,
            'escopo' => 'loja',
        ];
    } else {
        $phases['sync_org'] = [
            'ok' => false,
            'skipped' => false,
            'message' => (string) ($sync['message'] ?? 'Falha ao sincronizar job da loja na cron-job.org.'),
            'crons_setup_url' => $cronsSetupRel,
            'escopo' => 'loja',
        ];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Fluxo concluído.',
    'phases' => $phases,
    'automacao' => $resultadoAuto,
], JSON_UNESCAPED_UNICODE);
