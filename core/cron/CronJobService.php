<?php

/**
 * Integração https://api.cron-job.org — agendamento alinhado à janela H1–H2 e intervalo em minutos.
 *
 * Job enviado pela API (e modelo para criar à mão na cron-job.org):
 * - URL do job na cron-job.org: …/rodar-tudo.php?token=… (global) ou …/rodar-loja.php?loja=…&token=… (loja), para o token chegar ao PHP mesmo quando cabeçalhos custom não são repassados.
 * - Credenciais: URL com ?token= (sempre cron_token oficial) e/ou cabeçalho X-Cron-Token na API cron-job.org;
 *   em rodar-loja.php também X-Cron-Loja (= chave, ex. ml). Legado: ?token= e ?loja= na URL.
 * - Schedule timezone: America/Sao_Paulo.
 * - Intervalo &lt; 60 min: API envia minutes explícitos (0, N, 2N, …) × hours da janela → crontab no formato * /N H1-H2 * * * (cada N min na janela).
 *   Evita minutes [-1] (“cada minuto”), que na UI virava * na coluna dos minutos.
 * - Intervalos ≥ 60 min: mapeia para execuções no :00 em horas da janela (ex. 0 8,9,10 * * * quando K=1 h).
 * - Produção: antes do sync valida-se host público, DNS e HTTP; em desenvolvimento (.test / localhost ou APP_ENV=development) o sync com a API não é bloqueado por essas regras (execução na cron-job.org continua a exigir URL público).
 * - Títulos canónicos: "cron-global", "cron-loja-{chave}" e "achadinhos-grupo-{id} — {nome}" (job por grupo → cron/rodar-grupo.php).
 * - Health: /cron/health.php?token=(cron_health_token ou cron_token)
 */

require_once __DIR__ . '/CronPolicy.php';

if (!defined('APP_ENV')) {
    $___cfg = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($___cfg)) {
        require_once $___cfg;
    }
}

/**
 * Ambiente de desenvolvimento para regras de cron/cron-job.org: não bloqueia sync nem validações estritas.
 * DEV se APP_ENV=development OU o host do pedido atual corresponde a .test / .local / localhost / IP privado.
 */
function isCronDevEnvironment(?string $hostOverride = null): bool {
    if (defined('APP_ENV') && APP_ENV === 'development') {
        return true;
    }
    $host = $hostOverride;
    if ($host === null || trim((string) $host) === '') {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'], 2)[0]);
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        } else {
            return false;
        }
    }
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return false;
    }
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host, 2)[0];
    }
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -5) === '.test' || substr($host, -8) === '.invalid') {
        return true;
    }
    if ($host === '0.0.0.0' || $host === '::1') {
        return true;
    }
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
        return true;
    }

    return false;
}

/**
 * @return list<string>
 */
function cronPublicSiteUrlFallbackKeys(): array {
    return [
        'ml_site_url',
        'ml_cupons_site_url',
        'shopee_site_url',
        'magalu_site_url',
        'amazon_site_url',
        'shein_site_url',
        'aliexpress_site_url',
    ];
}

/**
 * @return array{base:string,source:string,source_label_pt:string}
 */
function resolveCronPublicBaseUrlSeguro(): array {
    $u = trim((string) getConfig('cron_public_base_url', ''));
    if ($u !== '') {
        return [
            'base' => rtrim($u, '/'),
            'source' => 'cron_public_base_url',
            'source_label_pt' => 'Configuração manual (cron_public_base_url)',
        ];
    }
    $site = trim((string) getConfig('site_url', ''));
    if ($site !== '' && preg_match('#^https?://#i', $site)) {
        return [
            'base' => rtrim($site, '/'),
            'source' => 'site_url',
            'source_label_pt' => 'Configuração site_url',
        ];
    }
    foreach (cronPublicSiteUrlFallbackKeys() as $k) {
        $u = trim((string) getConfig($k, ''));
        if ($u !== '' && preg_match('#^https?://#i', $u)) {
            return [
                'base' => rtrim($u, '/'),
                'source' => $k,
                'source_label_pt' => 'URL de loja (' . $k . ')',
            ];
        }
    }
    if (isCronDevEnvironment()) {
        $fromReq = cronPublicBaseUrlFromRequest();
        if ($fromReq !== '') {
            return [
                'base' => rtrim($fromReq, '/'),
                'source' => 'request_dev_only',
                'source_label_pt' => 'Pedido HTTP atual (só em modo desenvolvimento: .test / localhost ou APP_ENV=development)',
            ];
        }
    }

    return [
        'base' => '',
        'source' => 'none',
        'source_label_pt' => 'Sem base pública definida',
    ];
}

function cronJobOrgIpIsNonPublicRoutable(string $ip): bool {
    $ip = trim($ip);
    if ($ip === '' || $ip === '0.0.0.0') {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (preg_match('/^(127\.|0\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
            return true;
        }

        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $l = strtolower($ip);
        if ($l === '::1' || substr($l, 0, 5) === 'fe80:' || substr($l, 0, 2) === 'fc' || substr($l, 0, 2) === 'fd') {
            return true;
        }
    }

    return false;
}

/**
 * @return array{ok:bool,ip:string,detail_pt:string}
 */
function cronJobOrgDnsResolveCheckProducao(string $hostname): array {
    $hostname = strtolower(trim($hostname));
    if ($hostname === '') {
        return ['ok' => false, 'ip' => '', 'detail_pt' => 'Host vazio na URL do job.'];
    }
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        $ip = $hostname;
    } else {
        $ip = @gethostbyname($hostname);
        if ($ip === false || $ip === '' || $ip === $hostname) {
            return ['ok' => false, 'ip' => '', 'detail_pt' => 'Domínio não resolvível publicamente (DNS).'];
        }
    }
    if (cronJobOrgIpIsNonPublicRoutable($ip)) {
        return ['ok' => false, 'ip' => $ip, 'detail_pt' => 'O DNS aponta para um endereço não público; a cron-job.org não alcança esse destino.'];
    }

    return ['ok' => true, 'ip' => $ip, 'detail_pt' => ''];
}

/**
 * Teste de alcance HTTP (PROD). Respostas 2xx–4xx do servidor contam como alcançável; falha = erro de rede/SSL/timeout.
 *
 * @return array{ok:bool,http_code:int,detail_pt:string}
 */
function testCronEndpointReachability(string $jobUrl): array {
    $jobUrl = trim($jobUrl);
    if ($jobUrl === '') {
        return ['ok' => false, 'http_code' => 0, 'detail_pt' => 'URL vazia.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'detail_pt' => 'Extensão cURL não disponível no servidor.'];
    }
    $ch = curl_init($jobUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AchadinhosCronSyncProbe/1.0',
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = (string) curl_error($ch);
    $errno = (int) curl_errno($ch);
    curl_close($ch);
    cronJobOrgIntegrationDebugLog('cron_http_probe', [
        'hypothesisId' => 'CRON_PROBE',
        'http_code' => $code,
        'curl_errno' => $errno,
        'ok' => $errno === 0 && $code >= 200 && $code < 500,
    ]);
    if ($errno !== 0) {
        return ['ok' => false, 'http_code' => $code, 'detail_pt' => 'Ligação HTTP/SSL falhou: ' . ($cerr !== '' ? $cerr : 'erro ' . $errno)];
    }
    if ($code === 0) {
        return ['ok' => false, 'http_code' => 0, 'detail_pt' => 'Sem resposta HTTP do servidor.'];
    }
    if ($code >= 500) {
        return ['ok' => false, 'http_code' => $code, 'detail_pt' => 'O servidor respondeu com erro HTTP ' . $code . '.'];
    }

    return ['ok' => true, 'http_code' => $code, 'detail_pt' => ''];
}

/**
 * Validações antes de sincronizar com a API cron-job.org (apenas modo produção).
 *
 * @return string|null mensagem PT se bloquear; null se OK
 */
function cronJobOrgPrecheckSyncProducao(string $jobUrl): ?string {
    $jobUrl = trim($jobUrl);
    if ($jobUrl === '') {
        return 'URL do job vazia.';
    }
    $meta = cronAudit81af1fUrlMeta($jobUrl);
    if (!empty($meta['likely_non_public_dns'])) {
        return cronJobOrgNonPublicUrlSyncMessage($meta);
    }
    $host = (string) ($meta['host'] ?? '');
    $dns = cronJobOrgDnsResolveCheckProducao($host);
    if (!$dns['ok']) {
        return $dns['detail_pt'];
    }
    $http = testCronEndpointReachability($jobUrl);
    if (!$http['ok']) {
        return 'Teste de alcance ao endpoint falhou: ' . $http['detail_pt'];
    }

    return null;
}

/**
 * Bloqueio de sincronização com a API: apenas URL vazia (validação DNS/HTTP opcional em {@see cronJobOrgPrecheckSyncProducao} para diagnóstico futuro).
 */
function cronJobOrgSyncBlockMessage(string $jobUrl): ?string {
    if (trim($jobUrl) === '') {
        return 'URL do job vazia.';
    }

    return null;
}

// #region agent log
/**
 * Log NDJSON para integração cron-job.org (sem segredos: sem API key, sem token em URL).
 */
function cronJobOrgIntegrationDebugLog(string $event, array $data): void {
    $payload = array_merge([
        'sessionId' => '5774ad',
        'event' => $event,
        'timestamp' => (int) round(microtime(true) * 1000),
    ], $data);
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'debug-5774ad.log', $line, FILE_APPEND);
}
// #endregion

// #region agent log (81af1f audit — sem segredos)
/**
 * @return array{host:string,scheme:string,path:string,port:int,likely_non_public_dns:bool,dns_risk_flags:list<string>}
 */
function cronAudit81af1fUrlMeta(string $url): array {
    $p = parse_url($url);
    if (!is_array($p)) {
        return [
            'host' => '',
            'scheme' => '',
            'path' => '',
            'port' => 0,
            'likely_non_public_dns' => true,
            'dns_risk_flags' => ['parse_failed'],
        ];
    }
    $host = isset($p['host']) ? strtolower((string) $p['host']) : '';
    $scheme = isset($p['scheme']) ? strtolower((string) $p['scheme']) : '';
    $path = isset($p['path']) ? (string) $p['path'] : '';
    $port = isset($p['port']) ? (int) $p['port'] : 0;
    $flags = [];
    $bad = false;
    if ($host === '') {
        $bad = true;
        $flags[] = 'no_host';
    }
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -5) === '.test' || substr($host, -8) === '.invalid') {
        $bad = true;
        $flags[] = 'localhost_or_non_public_tld';
    }
    if ($host === '0.0.0.0') {
        $bad = true;
        $flags[] = 'unspecified_ipv4';
    }
    if ($host === '::1') {
        $bad = true;
        $flags[] = 'ipv6_loopback';
    }
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
        $bad = true;
        $flags[] = 'private_or_loopback_ip';
    }
    if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
        $bad = true;
        $flags[] = 'invalid_scheme';
    }

    return [
        'host' => $host,
        'scheme' => $scheme,
        'path' => $path,
        'port' => $port,
        'likely_non_public_dns' => $bad,
        'dns_risk_flags' => $flags,
    ];
}

/**
 * Mensagem para o admin quando a URL do job não deve ser enviada à cron-job.org.
 *
 * @param array{host:string,dns_risk_flags:list<string>} $meta Retorno de {@see cronAudit81af1fUrlMeta}
 */
function cronJobOrgNonPublicUrlSyncMessage(array $meta): string {
    $host = trim((string) ($meta['host'] ?? ''));
    $hostDisp = $host !== '' ? $host : '(host vazio)';
    $flags = isset($meta['dns_risk_flags']) && is_array($meta['dns_risk_flags']) ? $meta['dns_risk_flags'] : [];

    return 'Sincronização bloqueada: a URL do cron usaria o host «' . $hostDisp . '», que normalmente '
        . 'não resolve na Internet pública — a cron-job.org reportaria falha de DNS ao executar o job. '
        . 'Defina em Configurações → Crons o campo «URL base pública do site» com o domínio real do site (HTTPS), '
        . 'por exemplo o mesmo endereço que os visitantes usam. '
        . 'Se estiver a abrir o admin em .test ou localhost, não use esse host como base para o agendador externo. '
        . '(Indicadores: ' . ($flags !== [] ? implode(', ', array_map('strval', $flags)) : 'n/d') . ')';
}

/**
 * @return string|null null se a URL pode ser sincronizada com serviço externo; senão mensagem PT
 */
function cronJobOrgValidateJobUrlForExternalCron(string $jobUrl): ?string {
    $jobUrl = trim($jobUrl);
    if ($jobUrl === '') {
        return 'URL do job vazia.';
    }
    $meta = cronAudit81af1fUrlMeta($jobUrl);
    if (!$meta['likely_non_public_dns']) {
        return null;
    }

    return cronJobOrgNonPublicUrlSyncMessage($meta);
}

/**
 * @deprecated Use {@see cronJobOrgSyncBlockMessage} !== null
 */
function cronJobOrgNonPublicSyncShouldBlock(string $jobUrl): bool {
    return cronJobOrgSyncBlockMessage($jobUrl) !== null;
}

/**
 * Aviso em PT em modo desenvolvimento (sync sem bloqueio estrito).
 */
function cronJobOrgNonPublicSyncOverrideWarningPt(string $jobUrl): string {
    if (!isCronDevEnvironment()) {
        return '';
    }
    $meta = cronAudit81af1fUrlMeta($jobUrl);
    if (!empty($meta['likely_non_public_dns'])) {
        return ' Modo desenvolvimento: sincronização sem validação DNS/HTTP estrita no servidor; a cron-job.org só executará o job se o URL for alcançável na Internet.';
    }

    return '';
}

/**
 * De onde veio a base usada por {@see cronPublicBaseUrl()} e metadados para o painel (sem segredos).
 *
 * @return array{effective_base:string,source:string,source_label_pt:string,configured_nonempty:bool,host:string,url_suspect_non_public:bool,cron_ambiente_dev:bool,painel_status:string,job_url_global:string,sync_block_message:?string,painel_status_label_pt:string}
 */
function cronPublicBaseUrlResolution(): array {
    $cfgRaw = trim((string) getConfig('cron_public_base_url', ''));
    $configured = $cfgRaw !== '' ? rtrim($cfgRaw, '/') : '';
    $resolved = resolveCronPublicBaseUrlSeguro();
    $eff = $resolved['base'];
    $source = $resolved['source'];
    $label = $resolved['source_label_pt'];
    $cronDev = isCronDevEnvironment();
    $jobUrl = '';
    if ($eff !== '') {
        $jobUrl = cronJobUrlRodarTudoComQueryToken();
        if ($jobUrl === '') {
            $jobUrl = $eff . '/cron/rodar-tudo.php';
        }
    }
    $syncBlock = cronJobOrgSyncBlockMessage($jobUrl);
    $painelStatus = $cronDev ? 'dev' : ($syncBlock !== null ? 'prod_erro' : 'prod_ok');
    $painelLabel = $cronDev
        ? 'Modo desenvolvimento (sync não bloqueado por DNS/HTTP no servidor)'
        : ($syncBlock !== null ? 'Produção: URL inválida ou não verificada — sincronização bloqueada' : 'Produção: domínio verificado para sync');
    if ($jobUrl === '') {
        return [
            'effective_base' => '',
            'source' => 'none',
            'source_label_pt' => $label,
            'configured_nonempty' => $configured !== '',
            'host' => '',
            'url_suspect_non_public' => true,
            'cron_ambiente_dev' => $cronDev,
            'painel_status' => $painelStatus,
            'job_url_global' => '',
            'sync_block_message' => $syncBlock,
            'painel_status_label_pt' => $painelLabel,
        ];
    }
    $meta = cronAudit81af1fUrlMeta($jobUrl);

    return [
        'effective_base' => $eff,
        'source' => $source,
        'source_label_pt' => $label,
        'configured_nonempty' => $configured !== '',
        'host' => (string) ($meta['host'] ?? ''),
        'url_suspect_non_public' => !empty($meta['likely_non_public_dns']),
        'cron_ambiente_dev' => $cronDev,
        'painel_status' => $painelStatus,
        'job_url_global' => $jobUrl,
        'sync_block_message' => $syncBlock,
        'painel_status_label_pt' => $painelLabel,
    ];
}

function cronAudit81af1fLog(string $hypothesisId, string $location, string $message, array $data = []): void {
    if (!function_exists('achadinhos_debug_instrumentacao_ativa') || !achadinhos_debug_instrumentacao_ativa()) {
        return;
    }
    $root = dirname(dirname(__DIR__));
    $path = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cron-audit-81af1f.ndjson';
    $line = json_encode([
        'sessionId' => '81af1f',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
// #endregion

function cronJobOrgJobUrlSemTokenAviso(string $contexto, string $jobUrl, bool $credencialForaDaQuery = false): void {
    if ($credencialForaDaQuery) {
        return;
    }
    if ($jobUrl === '' || strpos($jobUrl, 'token=') !== false) {
        return;
    }
    cronJobOrgIntegrationDebugLog('job_url_sem_token', [
        'context' => $contexto,
        'hypothesisId' => 'URL',
        'url_len' => strlen($jobUrl),
        'note' => 'URL do job sem parâmetro token= (cron pode falhar por 403 no endpoint).',
    ]);
}

function cronJobOrgPersistGlobalLastSynced(int $iv, int $h1, int $h2, string $jobUrl, string $tituloCanonico, string $fpSalt = ''): void {
    setConfig('cron_global_org_last_iv', (string) $iv);
    setConfig('cron_global_org_last_h1', (string) $h1);
    setConfig('cron_global_org_last_h2', (string) $h2);
    setConfig('cron_global_org_last_job_fp', hash('sha256', $jobUrl . "\0" . $fpSalt));
    setConfig('cron_global_org_last_title', $tituloCanonico);
    setConfig('cron_global_org_last_notify_fp', cronJobOrgNotificationFingerprint());
    $hm = cronAudit81af1fUrlMeta($jobUrl);
    setConfig('cron_global_last_synced_job_host', (string) ($hm['host'] ?? ''));
}

function cronJobOrgGlobalPatchRedundante(int $iv, int $h1, int $h2, string $jobUrl, string $tituloCanonico, string $fpSalt = ''): bool {
    $fp = trim((string) getConfig('cron_global_org_last_job_fp', ''));
    if ($fp === '' || hash('sha256', $jobUrl . "\0" . $fpSalt) !== $fp) {
        return false;
    }
    $t = trim((string) getConfig('cron_global_org_last_title', ''));
    if ($t === '' || $t !== $tituloCanonico) {
        return false;
    }
    $nfp = trim((string) getConfig('cron_global_org_last_notify_fp', ''));
    if ($nfp === '' || $nfp !== cronJobOrgNotificationFingerprint()) {
        return false;
    }

    return $iv === (int) getConfig('cron_global_org_last_iv', '0')
        && $h1 === (int) getConfig('cron_global_org_last_h1', '0')
        && $h2 === (int) getConfig('cron_global_org_last_h2', '0');
}

/**
 * Texto seguro para gravar em cron_global_sync_last_error (sem URL completa, sem chave).
 *
 * @param mixed $body
 */
function cronJobOrgSanitizarTrechoResposta($body, string $fallbackHumanizado): string {
    $t = '';
    if (is_array($body)) {
        $t = trim((string) ($body['error'] ?? $body['message'] ?? ''));
        if ($t === '') {
            $enc = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $t = $enc !== false ? substr($enc, 0, 200) : '';
        }
    } else {
        $t = trim($fallbackHumanizado);
    }
    $t = preg_replace('#https?://\S+#i', '[url]', $t) ?? $t;
    $t = trim($t);
    if (strlen($t) > 200) {
        $t = substr($t, 0, 197) . '...';
    }

    return $t;
}

/**
 * Linha única para config: "Erro cron-job.org (HTTP N): trecho".
 *
 * @param mixed $body
 */
function cronJobOrgLinhaErroSync(int $httpCode, $body, string $messageFromRequest): string {
    $human = cronJobOrgSanitizarTrechoResposta($body, $messageFromRequest);
    if ($human === '') {
        $human = preg_replace('#https?://\S+#i', '[url]', trim($messageFromRequest)) ?? trim($messageFromRequest);
        $human = preg_replace('/^HTTP\s+\d+\s*:?\s*/i', '', $human) ?? $human;
        $human = trim($human);
        if (strlen($human) > 200) {
            $human = substr($human, 0, 197) . '...';
        }
    }

    return 'Erro cron-job.org (HTTP ' . $httpCode . ')' . ($human !== '' ? ': ' . $human : '');
}

/** Mensagem curta para JSON do painel (429 / 404); vazio = usar linha de config. */
function cronJobOrgMensagemErroPainelJson(int $httpCode): string {
    if ($httpCode === 429) {
        return 'Limite da API cron-job atingido. Aguarde alguns segundos e tente novamente.';
    }
    if ($httpCode === 404) {
        return 'Job não encontrado na cron-job. Será recriado automaticamente.';
    }

    return '';
}

function cronJobOrgLojaConfigKey(string $loja, string $suffix): string {
    return 'cron_org_loja_' . $loja . '_' . $suffix;
}

function cronJobOrgPersistLojaLastSynced(string $loja, int $iv, int $h1, int $h2, string $jobUrl, string $tituloCanonico, string $fpSalt = ''): void {
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_iv'), (string) $iv);
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_h1'), (string) $h1);
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_h2'), (string) $h2);
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_job_fp'), hash('sha256', $jobUrl . "\0" . $fpSalt));
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_title'), $tituloCanonico);
    setConfig(cronJobOrgLojaConfigKey($loja, 'last_notify_fp'), cronJobOrgNotificationFingerprint());
}

function cronJobOrgLojaPatchRedundante(string $loja, int $iv, int $h1, int $h2, string $jobUrl, string $tituloCanonico, string $fpSalt = ''): bool {
    $fp = trim((string) getConfig(cronJobOrgLojaConfigKey($loja, 'last_job_fp'), ''));
    if ($fp === '' || hash('sha256', $jobUrl . "\0" . $fpSalt) !== $fp) {
        return false;
    }
    $t = trim((string) getConfig(cronJobOrgLojaConfigKey($loja, 'last_title'), ''));
    if ($t === '' || $t !== $tituloCanonico) {
        return false;
    }
    $nfp = trim((string) getConfig(cronJobOrgLojaConfigKey($loja, 'last_notify_fp'), ''));
    if ($nfp === '' || $nfp !== cronJobOrgNotificationFingerprint()) {
        return false;
    }

    return $iv === (int) getConfig(cronJobOrgLojaConfigKey($loja, 'last_iv'), '0')
        && $h1 === (int) getConfig(cronJobOrgLojaConfigKey($loja, 'last_h1'), '0')
        && $h2 === (int) getConfig(cronJobOrgLojaConfigKey($loja, 'last_h2'), '0');
}

/**
 * jobId na resposta PUT /jobs (documentação: { "jobId": 123 }; proxies podem aninhar ou devolver string JSON).
 *
 * @param mixed $body array decodificado ou string JSON
 */
function cronJobOrgExtrairJobIdDoBody($body): ?string {
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $body = $decoded;
        } else {
            return null;
        }
    }
    if (!is_array($body)) {
        return null;
    }
    $paths = [['jobId'], ['id'], ['job', 'jobId'], ['job', 'id'], ['data', 'jobId'], ['result', 'jobId']];
    foreach ($paths as $path) {
        $node = $body;
        $ok = true;
        foreach ($path as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                $ok = false;
                break;
            }
            $node = $node[$seg];
        }
        if ($ok) {
            $s = cronJobOrgNormalizarJobIdString($node);
            if ($s !== '') {
                return $s;
            }
        }
    }

    return null;
}

/**
 * Quando o PUT criou o job mas o corpo não trouxe jobId, lista a conta e encontra pelo título exato.
 * Várias tentativas com pausa (429 / propagação do job na API).
 *
 * @param int $maxRounds tentativas de GET /jobs (cada uma já pode repetir 429 internamente)
 */
function cronJobOrgResolverJobIdPorTituloExato(string $titulo, int $maxRounds = 5): ?string {
    $titulo = trim($titulo);
    if ($titulo === '') {
        return null;
    }
    for ($round = 0; $round < $maxRounds; $round++) {
        if ($round > 0) {
            usleep(2000000);
        }
        $listRes = cronJobListarJobsConta(false);
        if (!empty($listRes['success']) && is_array($listRes['jobs'])) {
            $busca = cronJobOrgBuscarJobsPorTituloExato($listRes['jobs'], $titulo);
            $pid = $busca['primeiro_id'] ?? null;
            if ($pid !== null && $pid !== '') {
                return (string) $pid;
            }
        }
    }

    return null;
}

/**
 * @param mixed $body
 */
function cronJobOrgResolverJobIdAposPutCriacao($body, string $tituloJob): ?string {
    $id = cronJobOrgExtrairJobIdDoBody($body);
    if ($id !== null && $id !== '') {
        return $id;
    }
    // Documentação cron-job.org: PUT /jobs — máx. 1 pedido/s; GET /jobs em seguida sem espera costuma devolver 429.
    usleep(1200000);

    return cronJobOrgResolverJobIdPorTituloExato($tituloJob, 5);
}

/**
 * @param mixed $body
 */
function cronJobOrgResolverJobIdAposPutLoja($body, string $loja): ?string {
    $id = cronJobOrgExtrairJobIdDoBody($body);
    if ($id !== null && $id !== '') {
        return $id;
    }
    usleep(1200000);
    for ($round = 0; $round < 5; $round++) {
        if ($round > 0) {
            usleep(2000000);
        }
        $listRes = cronJobListarJobsConta(false);
        $jobs = (!empty($listRes['success']) && is_array($listRes['jobs'])) ? $listRes['jobs'] : [];
        $b = cronJobOrgBuscarJobLojaPorTituloNovoOuLegado($jobs, $loja);
        $pid = $b['primeiro_id'] ?? null;
        if ($pid !== null && $pid !== '') {
            return (string) $pid;
        }
    }

    return null;
}

/** Título único do job global na cron-job.org (identificação por nome). */
function cronJobOrgTituloGlobal(): string {
    return 'cron-global';
}

/**
 * Título legado (apenas chave) — usado para reencontrar jobs criados antes do sufixo com nome do marketplace.
 */
function cronJobOrgTituloLojaLegado(string $loja): string {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));

    return 'cron-loja-' . $loja;
}

/**
 * Título único do job da loja na cron-job.org: chave + nome legível do marketplace.
 *
 * @param string $loja Chave interna: ml, shopee, ml_cupons, …
 */
function cronJobOrgTituloLoja(string $loja): string {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    $map = [
        'ml' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'magalu' => 'Magalu',
        'aliexpress' => 'AliExpress',
        'amazon' => 'Amazon',
        'shein' => 'Shein',
        'ml_cupons' => 'ML Cupons',
    ];
    $human = $map[$loja] ?? $loja;

    return 'cron-loja-' . $loja . ' — ' . $human;
}

/**
 * Procura job da loja pelo título atual ou pelo título legado (migração sem duplicar).
 *
 * @param list<array<string, mixed>> $jobs
 *
 * @return array{primeiro_id: ?string, todos_ids: list<string>, duplicados: bool}
 */
function cronJobOrgBuscarJobLojaPorTituloNovoOuLegado(array $jobs, string $loja): array {
    $titulo = cronJobOrgTituloLoja($loja);
    $b = cronJobOrgBuscarJobsPorTituloExato($jobs, $titulo);
    if ($b['primeiro_id'] !== null && $b['primeiro_id'] !== '') {
        return $b;
    }
    $leg = cronJobOrgTituloLojaLegado($loja);
    if ($leg !== $titulo) {
        $b2 = cronJobOrgBuscarJobsPorTituloExato($jobs, $leg);
        if ($b2['primeiro_id'] !== null && $b2['primeiro_id'] !== '') {
            return $b2;
        }
    }

    return $b;
}

/**
 * @param mixed $id
 */
function cronJobOrgNormalizarJobIdString($id): string {
    if (is_int($id)) {
        return (string) $id;
    }
    if (is_float($id)) {
        return (string) (int) $id;
    }
    if (is_string($id) && preg_match('/^\d+$/', trim($id))) {
        return (string) (int) trim($id);
    }

    return '';
}

/**
 * @param array<string, mixed> $item Elemento devolvido em GET /jobs
 */
function cronJobOrgListItemExtrairId(array $item): string {
    foreach (['jobId', 'id'] as $k) {
        if (!array_key_exists($k, $item)) {
            continue;
        }
        $s = cronJobOrgNormalizarJobIdString($item[$k]);
        if ($s !== '') {
            return $s;
        }
    }
    $nested = $item['job'] ?? null;
    if (is_array($nested)) {
        foreach (['jobId', 'id'] as $k) {
            if (!array_key_exists($k, $nested)) {
                continue;
            }
            $s = cronJobOrgNormalizarJobIdString($nested[$k]);
            if ($s !== '') {
                return $s;
            }
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $item
 */
function cronJobOrgListItemExtrairTitulo(array $item): string {
    if (isset($item['title']) && is_string($item['title'])) {
        return trim($item['title']);
    }
    $nested = $item['job'] ?? null;
    if (is_array($nested) && isset($nested['title']) && is_string($nested['title'])) {
        return trim($nested['title']);
    }

    return '';
}

/**
 * Jobs na conta com o título exatamente igual a $tituloExato.
 *
 * @param list<array<string, mixed>> $jobs
 *
 * @return array{primeiro_id: ?string, todos_ids: list<string>, duplicados: bool}
 */
function cronJobOrgBuscarJobsPorTituloExato(array $jobs, string $tituloExato): array {
    $tituloExato = trim($tituloExato);
    $ids = [];
    foreach ($jobs as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (cronJobOrgListItemExtrairTitulo($item) !== $tituloExato) {
            continue;
        }
        $id = cronJobOrgListItemExtrairId($item);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));

    return [
        'primeiro_id' => $ids[0] ?? null,
        'todos_ids' => $ids,
        'duplicados' => count($ids) > 1,
    ];
}

/**
 * Notificações padrão na API cron-job.org (JobNotificationSettings).
 * - onSuccess: corresponde à opção «notificar quando a execução é bem-sucedida depois de falhar» (e-mail após recuperação).
 * A desativação automática (~25 falhas seguidas, FAQ cron-job.org) depende de execuções com sucesso para «zerar» o contador;
 * use URL pública acessível pelos servidores da cron-job.org.
 *
 * @return array{onFailure: bool, onFailureCount: int, onSuccess: bool, onDisable: bool}
 */
function cronJobOrgNotificationPayloadPadrao(): array {
    return [
        'onFailure' => true,
        'onFailureCount' => 1,
        'onSuccess' => true,
        'onDisable' => true,
    ];
}

function cronJobOrgNotificationFingerprint(): string {
    $j = json_encode(cronJobOrgNotificationPayloadPadrao(), JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        return '';
    }

    return hash('sha256', $j);
}

/**
 * Payload completo do job para criar/atualizar na API (GET HTTP, sem guardar respostas, timezone na agenda).
 *
 * @param array<string, mixed> $schedule
 * @param ?string $cronTokenHeader Valor opcional para cabeçalho X-Cron-Token (só se a URL não trouxer ?token=; agendadores externos muitas vezes não enviam headers custom — preferir token na query em {@see cronJobUrlRodarTudoComQueryToken} / {@see cronJobUrlRodarLojaComQuery})
 * @param ?string $cronLojaChaveHeader Valor opcional para X-Cron-Loja (redundante se ?loja= já está na URL)
 *
 * @return array<string, mixed>
 */
function cronJobOrgPayloadJobCompleto(string $titulo, string $jobUrl, array $schedule, bool $enabled = true, ?string $cronTokenHeader = null, ?string $cronLojaChaveHeader = null): array {
    $job = [
        'title' => $titulo,
        'url' => $jobUrl,
        'enabled' => $enabled,
        'saveResponses' => false,
        'requestMethod' => 0,
        'schedule' => $schedule,
        'notification' => cronJobOrgNotificationPayloadPadrao(),
    ];
    $headers = [];
    $tok = $cronTokenHeader !== null ? trim($cronTokenHeader) : '';
    if ($tok !== '') {
        $headers['X-Cron-Token'] = $tok;
    }
    $lj = $cronLojaChaveHeader !== null ? trim((string) preg_replace('/[^a-z0-9_]/', '', strtolower($cronLojaChaveHeader))) : '';
    if ($lj !== '') {
        $headers['X-Cron-Loja'] = $lj;
    }
    if ($headers !== []) {
        $job['extendedData'] = ['headers' => $headers];
    }

    return $job;
}

/**
 * Garante que minutes nunca fica [-1] (na API = cada minuto → crontab "* H-H * * *" sem passo).
 *
 * @param array<string, mixed> $sched
 *
 * @return array<string, mixed>
 */
function cronJobOrgScheduleCorrigirMinutosWildcard(array $sched, int $intervaloMinutos): array {
    $iv = CronPolicy::normalizeInterval($intervaloMinutos);
    $mins = isset($sched['minutes']) && is_array($sched['minutes']) ? $sched['minutes'] : [];
    $hasWildcard = ($mins === [-1]) || in_array(-1, $mins, true);
    $emptyish = ($mins === []);
    if ($hasWildcard || $emptyish) {
        $sched['minutes'] = $iv < 60 ? cronJobOrgMinutosACada($iv) : [0];
    } else {
        $sched['minutes'] = array_values(array_filter($mins, static function ($x): bool {
            return is_int($x) && $x >= 0 && $x <= 59;
        }));
        if ($sched['minutes'] === []) {
            $sched['minutes'] = $iv < 60 ? cronJobOrgMinutosACada($iv) : [0];
        }
    }

    return $sched;
}

/**
 * Intervalos em minutos que viram “a cada K h no :00” na API (produto cartesiano).
 *
 * @return list<int>
 */
function cronJobOrgIntervalosHoraEmMinutos(): array {
    return [60, 120, 180, 240, 360, 480, CronPolicy::intervalMaxMinutes()];
}

/**
 * K horas para o passo (60→1, 120→2, …, 720→12), ou null se não for um desses valores.
 */
function cronJobOrgKHorasDoIntervalo(int $intervaloMinutos): ?int {
    $map = CronPolicy::intervaloMinutosParaKHorasMap();

    return $map[$intervaloMinutos] ?? null;
}

function cronPublicBaseUrl(): string {
    $r = resolveCronPublicBaseUrlSeguro();

    return $r['base'];
}

/**
 * URL base derivada do pedido HTTP atual (mesmo host em que o painel admin está aberto).
 * Usada após cron_public_base_url vazio; em CLI fica vazia e aí entram fallbacks *_site_url.
 */
function cronPublicBaseUrlFromRequest(): string {
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'], 2)[0]);
    }
    if ($host === '' && isset($_SERVER['HTTP_HOST'])) {
        $host = trim((string) $_SERVER['HTTP_HOST']);
    }
    if ($host === '') {
        return '';
    }
    $https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $https = true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        $https = true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }
    $proto = $https ? 'https' : 'http';

    // Inclui o caminho do site quando o admin não está na raíz (ex.: /achadinhos/admin/ → base /achadinhos)
    $basePath = '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    if ($script !== '' && $script !== '/') {
        $script = str_replace('\\', '/', $script);
        $adminDir = dirname($script);
        $siteRoot = dirname($adminDir);
        if ($siteRoot !== '' && $siteRoot !== '/' && $siteRoot !== '.') {
            $basePath = rtrim($siteRoot, '/');
        }
    }

    return $proto . '://' . $host . $basePath;
}

/**
 * @return list<int>
 */
function cronJobOrgHorasNaJanela(int $hi, int $hf): array {
    $hi = max(0, min(23, $hi));
    $hf = max(0, min(23, $hf));
    $out = [];
    if ($hi <= $hf) {
        for ($h = $hi; $h <= $hf; $h++) {
            $out[] = $h;
        }
    } else {
        for ($h = $hi; $h <= 23; $h++) {
            $out[] = $h;
        }
        for ($h = 0; $h <= $hf; $h++) {
            $out[] = $h;
        }
    }

    return $out;
}

/**
 * @return list<int>
 */
function cronJobOrgMinutosACada(int $intervaloMinutos): array {
    $n = max(1, min(59, $intervaloMinutos));
    $m = [];
    for ($i = 0; $i < 60; $i += $n) {
        $m[] = $i;
    }
    if ($m === []) {
        $m = [0];
    }

    return $m;
}

/**
 * Horas da janela (incl. noturna) percorridas de k em k horas a partir de h1, sem repetir ciclo.
 *
 * @return list<int>
 */
function cronJobOrgHorasACadaKHorasNaJanela(int $horaInicio, int $horaFim, int $kHoras): array {
    $kHoras = max(1, min(23, $kHoras));
    $allowed = array_flip(cronJobOrgHorasNaJanela($horaInicio, $horaFim));
    if ($allowed === []) {
        return [];
    }
    $h1 = max(0, min(23, $horaInicio));
    $out = [];
    $h = $h1;
    for ($guard = 0; $guard < 48; $guard++) {
        if (!isset($allowed[$h])) {
            break;
        }
        if (in_array($h, $out, true)) {
            break;
        }
        $out[] = $h;
        $h = ($h + $kHoras) % 24;
    }

    return $out;
}

/**
 * Payload JobSchedule da API cron-job.org (produto cartesiano hours × minutes).
 *
 * @return array<string, mixed>
 */
function cronJobOrgSchedulePayload(int $intervaloMinutos, int $horaInicio, int $horaFim): array {
    $iv = CronPolicy::normalizeInterval($intervaloMinutos);
    $h1 = max(0, min(23, $horaInicio));
    $h2 = max(0, min(23, $horaFim));

    if ($iv < 60) {
        $sched = [
            'timezone' => 'America/Sao_Paulo',
            'expiresAt' => 0,
            'hours' => cronJobOrgHorasNaJanela($h1, $h2),
            'mdays' => [-1],
            'minutes' => cronJobOrgMinutosACada($iv),
            'months' => [-1],
            'wdays' => [-1],
        ];

        return cronJobOrgScheduleCorrigirMinutosWildcard($sched, $iv);
    }

    $k = cronJobOrgKHorasDoIntervalo($iv);
    if ($k !== null) {
        $hours = cronJobOrgHorasACadaKHorasNaJanela($h1, $h2, $k);
        $minutes = [0];
    } else {
        $hours = cronJobOrgHorasNaJanela($h1, $h2);
        $minutes = [0];
    }

    $sched = [
        'timezone' => 'America/Sao_Paulo',
        'expiresAt' => 0,
        'hours' => $hours,
        'mdays' => [-1],
        'minutes' => $minutes,
        'months' => [-1],
        'wdays' => [-1],
    ];

    return cronJobOrgScheduleCorrigirMinutosWildcard($sched, $iv);
}

/**
 * Pré-visualização alinhada ao que a API recebe (e exemplo de crontab ilustrativo).
 *
 * @return array{expr: string, hint: string, apiNote: ?string}
 */
function cronPainelPreviewExemplo(int $intervaloMinutos, int $horaInicio, int $horaFim): array {
    $iv = CronPolicy::normalizeInterval($intervaloMinutos);
    $h1 = max(0, min(23, $horaInicio));
    $h2 = max(0, min(23, $horaFim));
    $apiNote = null;

    if ($iv < 60) {
        if ($h1 <= $h2) {
            return [
                'expr' => '*/' . $iv . ' ' . $h1 . '-' . $h2 . ' * * *',
                'hint' => 'Exemplo de expressão Cron (a cada ' . $iv . ' min, entre ' . $h1 . 'h e ' . $h2 . 'h):',
                'apiNote' => null,
            ];
        }

        return [
            'expr' => '*/' . $iv . ' * * * * (janela noturna ' . $h1 . 'h–' . $h2 . 'h: agendamento enviado à API cron-job.org)',
            'hint' => 'Exemplo de expressão Cron (a cada ' . $iv . ' min; janela noturna ' . $h1 . 'h–' . $h2 . 'h — veja linha abaixo):',
            'apiNote' => null,
        ];
    }

    $k = cronJobOrgKHorasDoIntervalo($iv);
    if ($k === null) {
        $apiNote = 'Intervalo de ' . $iv . ' min não pode ser reproduzido exatamente na API (limite horas×minutos); o job foi configurado como a cada 60 min (:00) na janela.';
    }

    if ($k !== null) {
        $hours = cronJobOrgHorasACadaKHorasNaJanela($h1, $h2, $k);
        $hint = $k === 1
            ? 'Exemplo ilustrativo (a cada hora no :00; janela ' . $h1 . 'h–' . $h2 . 'h):'
            : 'Exemplo ilustrativo (a cada ' . $k . ' h no :00; janela ' . $h1 . 'h–' . $h2 . 'h):';
    } else {
        $hours = cronJobOrgHorasNaJanela($h1, $h2);
        $hint = 'Exemplo ilustrativo (a cada 60 min no :00 na janela; o intervalo de ' . $iv . ' min não é representável de forma exata na API):';
    }

    $expr = '0 ' . implode(',', array_map('strval', $hours)) . ' * * *';

    return ['expr' => $expr, 'hint' => $hint, 'apiNote' => $apiNote];
}

/**
 * @param array<string, string> $headers Cabeçalhos HTTP para curl -H (ex.: X-Cron-Token)
 */
function cronPainelLinhaCurl(string $expr, string $cronUrl, array $headers = []): string {
    $cronUrl = trim($cronUrl);
    if ($cronUrl === '') {
        return '';
    }
    $hPart = '';
    foreach ($headers as $hk => $hv) {
        $hk = trim((string) $hk);
        $hv = trim((string) $hv);
        if ($hk === '' || $hv === '') {
            continue;
        }
        $hPart .= ' -H ' . escapeshellarg($hk . ': ' . $hv);
    }

    return $expr . ' curl -s' . $hPart . ' ' . escapeshellarg($cronUrl) . ' > /dev/null 2>&1';
}

/**
 * Linha de expressão estilo crontab para exibição (alinhada a {@see cronPainelPreviewExemplo}).
 */
function cronExpressaoLinux(int $intervaloMinutos, int $horaInicio, int $horaFim): string {
    return cronPainelPreviewExemplo($intervaloMinutos, $horaInicio, $horaFim)['expr'];
}

/**
 * Um único pedido HTTP à API (sem retry).
 *
 * @return array{success: bool, message: string, body: mixed, http_code: int}
 */
function cronJobOrgRequestOnce(string $method, string $path, ?array $jsonBody, int $attempt, int $maxAttempts): array {
    $apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
    if ($apiKey === '') {
        cronJobOrgIntegrationDebugLog('no_api_key', [
            'hypothesisId' => 'API_KEY',
            'method' => $method,
            'path' => $path,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'http_code' => 0,
        ]);

        return ['success' => false, 'message' => 'Chave API cron-job.org não configurada (Configurações → Crons).', 'body' => null, 'http_code' => 0];
    }
    $url = 'https://api.cron-job.org' . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = ($raw === false) ? (string) curl_error($ch) : '';
    curl_close($ch);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $pathClean = strpos($path, '?') !== false ? explode('?', $path, 2)[0] : $path;

    if ($code >= 200 && $code < 300) {
        cronJobOrgIntegrationDebugLog('http_ok', [
            'hypothesisId' => 'H1',
            'method' => $method,
            'path' => $pathClean,
            'http_code' => $code,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'retry_attempt' => $attempt,
            'api_message' => '',
            'curl_error' => $curlErr !== '' ? substr($curlErr, 0, 120) : '',
        ]);

        return ['success' => true, 'message' => 'OK', 'body' => $body, 'http_code' => $code];
    }
    $msg = is_array($body) ? ($body['error'] ?? $body['message'] ?? json_encode($body)) : (string) $raw;
    $fullMsg = 'HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '');
    cronJobOrgIntegrationDebugLog($code === 429 ? 'http_429' : 'http_fail', [
        'hypothesisId' => 'H1',
        'method' => $method,
        'path' => $pathClean,
        'http_code' => $code,
        'attempt' => $attempt,
        'max_attempts' => $maxAttempts,
        'retry_attempt' => $attempt,
        'api_message' => substr($fullMsg, 0, 500),
        'curl_error' => $curlErr !== '' ? substr($curlErr, 0, 200) : '',
    ]);

    return ['success' => false, 'message' => $fullMsg, 'body' => $body, 'http_code' => $code];
}

/**
 * Pedido à API cron-job.org. Com $retryOn429: até 7 tentativas só em HTTP 429,
 * com backoff crescente (evita falha após PUT bem-sucedido quando GET /jobs devolve 429).
 *
 * @return array{success: bool, message: string, body: mixed, http_code: int}
 */
function cronJobOrgRequest(string $method, string $path, ?array $jsonBody = null, bool $retryOn429 = false): array {
    $methodU = strtoupper($method);
    // Rate limit / quota: várias tentativas com espera crescente (documentação menciona 429 por quota e por ritmo).
    $maxAttempts = $retryOn429 ? 7 : 1;
    $last = null;
    /** @var list<int> microssegundos entre tentativa falha 429 e a seguinte */
    $backoff429Us = [800000, 1500000, 2500000, 4000000, 6000000, 10000000, 15000000];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $last = cronJobOrgRequestOnce($methodU, $path, $jsonBody, $attempt, $maxAttempts);
        if (!empty($last['success'])) {
            return $last;
        }
        $http = (int) ($last['http_code'] ?? 0);
        if ($retryOn429 && $attempt < $maxAttempts && $http === 429) {
            $delayUs = $backoff429Us[$attempt - 1] ?? 15000000;
            cronJobOrgIntegrationDebugLog('retry_429_backoff', [
                'hypothesisId' => 'H1',
                'method' => $methodU,
                'path' => strpos($path, '?') !== false ? explode('?', $path, 2)[0] : $path,
                'retry_attempt' => $attempt,
                'next_attempt' => $attempt + 1,
                'delay_ms' => (int) ($delayUs / 1000),
            ]);
            usleep($delayUs);
            continue;
        }
        break;
    }

    return $last ?? ['success' => false, 'message' => 'Falha na API cron-job.org.', 'body' => null, 'http_code' => 0];
}

/**
 * URL pública do endpoint global (sem query). Autenticação: ?token= ou cabeçalho X-Cron-Token.
 */
function cronJobUrlRodarTudo(): string {
    $base = cronPublicBaseUrl();
    if ($base === '') {
        return '';
    }

    return $base . '/cron/rodar-tudo.php';
}

/** URL com ?token= para testes manuais ou legado. */
function cronJobUrlRodarTudoComQueryToken(): string {
    if (!function_exists('achadinhosCronTokenHttpOficialLer')) {
        $fp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'functions.php';
        if (is_file($fp)) {
            require_once $fp;
        }
    }
    $u = cronJobUrlRodarTudo();
    if ($u === '') {
        return '';
    }
    $tok = function_exists('achadinhosCronTokenHttpOficialLer') ? achadinhosCronTokenHttpOficialLer() : trim((string) getConfig('cron_token', ''));
    if ($tok === '') {
        return $u;
    }

    return $u . '?token=' . rawurlencode($tok);
}

/**
 * Cria ou atualiza na cron-job.org o job da cron global (rodar-tudo).
 * Identificação estável pelo título canónico {@see cronJobOrgTituloGlobal()} — não duplica ao reenviar.
 *
 * @return array{success: bool, message: string, job_id: ?string, skipped?: bool, http_code?: int, error_body?: mixed, sync_partial_no_job_id?: bool}
 */
function cronJobSincronizarGlobal(): array {
    // #region agent log (81af1f audit)
    $auditT0 = microtime(true);
    // #endregion
    $iv = CronPolicy::normalizeInterval((int) getConfig('cron_intervalo_minutos', '5'));
    $h1 = max(0, min(23, (int) getConfig('cron_hora_inicio', '8')));
    $h2 = max(0, min(23, (int) getConfig('cron_hora_fim', '22')));
    $titulo = cronJobOrgTituloGlobal();
    $stored = preg_replace('/\D/', '', trim((string) getConfig('cron_global_job_id', '')));

    if (!function_exists('achadinhosCronGarantirTokenHttpOficialParaSync')) {
        $fp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'functions.php';
        if (is_file($fp)) {
            require_once $fp;
        }
    }
    $tokGenG = function_exists('achadinhosCronGarantirTokenHttpOficialParaSync')
        ? achadinhosCronGarantirTokenHttpOficialParaSync()
        : ['ok' => true, 'message' => '', 'generated' => false];
    if (empty($tokGenG['ok'])) {
        setConfig('cron_global_sync_last_error', substr((string) ($tokGenG['message'] ?? ''), 0, 500));

        return [
            'success' => false,
            'message' => (string) ($tokGenG['message'] ?? 'Não foi possível garantir cron_token para o job.'),
            'job_id' => null,
            'http_code' => 0,
            'error_body' => null,
            'cron_sync_failure_code' => 'token_http_official',
        ];
    }
    $tok = function_exists('achadinhosCronTokenHttpOficialLer') ? achadinhosCronTokenHttpOficialLer() : trim((string) getConfig('cron_token', ''));
    $jobUrl = cronJobUrlRodarTudoComQueryToken();
    if ($jobUrl === '') {
        return ['success' => false, 'message' => 'URL pública não configurada (Configurações → Crons: URL base pública, ou URL do site de alguma loja, ou acesse o admin pelo domínio público).', 'job_id' => null, 'http_code' => 0, 'error_body' => null];
    }
    if ($tok === '' || strpos($jobUrl, 'token=') === false) {
        $em = 'URL do job global sem ?token=: cron_token oficial ausente após tentativa de geração. Verifique a base de dados.';
        setConfig('cron_global_sync_last_error', substr($em, 0, 500));

        return [
            'success' => false,
            'message' => $em,
            'job_id' => null,
            'http_code' => 0,
            'error_body' => null,
            'cron_sync_failure_code' => 'token_http_url_invalid',
        ];
    }
    $blockMsg = cronJobOrgSyncBlockMessage($jobUrl);
    if ($blockMsg !== null) {
        setConfig('cron_global_sync_last_error', substr($blockMsg, 0, 500));
        // #region agent log (81af1f audit)
        cronAudit81af1fLog('SYNC_BLOCK', 'CronJobService.php:cronJobSincronizarGlobal', 'sync_blocked_precheck', [
            'url_meta' => cronAudit81af1fUrlMeta($jobUrl),
            'cron_dev' => isCronDevEnvironment(),
        ]);
        // #endregion

        return ['success' => false, 'message' => $blockMsg, 'job_id' => null, 'http_code' => 0, 'error_body' => null];
    }
    $urlOverrideWarn = cronJobOrgNonPublicSyncOverrideWarningPt($jobUrl);
    cronJobOrgIntegrationDebugLog('sync_global_entry', [
        'hypothesisId' => 'H5',
        'job_id_len' => strlen($stored),
        'job_url_len' => strlen($jobUrl),
        'url_has_query' => $jobUrl !== '' && strpos($jobUrl, '?') !== false,
        'titulo' => $titulo,
    ]);
    cronJobOrgJobUrlSemTokenAviso('cronJobSincronizarGlobal', $jobUrl, $tok !== '');

    $schedule = cronJobOrgSchedulePayload($iv, $h1, $h2);
    $payloadJob = cronJobOrgPayloadJobCompleto($titulo, $jobUrl, $schedule, true, null, null);
    // #region agent log (81af1f audit)
    $fromReq = cronPublicBaseUrlFromRequest();
    cronAudit81af1fLog('H1', 'CronJobService.php:cronJobSincronizarGlobal', 'url_and_payload_shape', [
        'url_meta' => cronAudit81af1fUrlMeta($jobUrl),
        'config_cron_public_base_nonempty' => trim((string) getConfig('cron_public_base_url', '')) !== '',
        'fallback_request_base_meta' => $fromReq !== '' ? cronAudit81af1fUrlMeta($fromReq) : ['host' => '', 'likely_non_public_dns' => false, 'dns_risk_flags' => ['empty_request_base']],
        'schedule_timezone' => $schedule['timezone'] ?? null,
        'intervalo' => $iv,
        'h1' => $h1,
        'h2' => $h2,
        'x_cron_token_configured' => $tok !== '',
        'job_url_has_query_token' => $tok !== '' && strpos($jobUrl, 'token=') !== false,
        'job_url_path' => (string) (parse_url($jobUrl, PHP_URL_PATH) ?: ''),
        'ms_after_payload' => (int) round((microtime(true) - $auditT0) * 1000),
    ]);
    // #endregion

    $listRes = cronJobListarJobsConta(false);
    $listOk = !empty($listRes['success']);
    $jobs = ($listOk && is_array($listRes['jobs'])) ? $listRes['jobs'] : [];
    $busca = cronJobOrgBuscarJobsPorTituloExato($jobs, $titulo);
    $idPorTitulo = $busca['primeiro_id'];
    // #region agent log (81af1f audit)
    cronAudit81af1fLog('H5', 'CronJobService.php:cronJobSincronizarGlobal', 'after_list_jobs', [
        'list_ok' => $listOk,
        'jobs_count' => count($jobs),
        'ms_after_list' => (int) round((microtime(true) - $auditT0) * 1000),
    ]);
    // #endregion
    $dupMsg = '';
    if ($busca['duplicados'] && $idPorTitulo !== null && $idPorTitulo !== '') {
        $dupMsg = ' Atenção: há mais de um job com o título "' . $titulo . '"; foi usado o ID ' . $idPorTitulo . '. Apague duplicados no console cron-job.org.';
    }

    $jid = '';
    if ($idPorTitulo !== null && $idPorTitulo !== '') {
        $jid = $idPorTitulo;
        if ($stored !== $jid) {
            setConfig('cron_global_job_id', $jid);
        }
    } elseif ($stored !== '') {
        $jid = $stored;
    }

    $reusedByTitle = ($idPorTitulo !== null && $idPorTitulo !== '' && $idPorTitulo === $jid);

    if ($jid === '' && !$listOk) {
        $recPre = cronJobOrgResolverJobIdPorTituloExato($titulo, 3);
        if ($recPre !== null && $recPre !== '') {
            $jid = $recPre;
            setConfig('cron_global_job_id', $jid);
        }
    }

    if ($jid === '') {
        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if (!$resPut['success']) {
            return [
                'success' => false,
                'message' => $resPut['message'],
                'job_id' => null,
                'http_code' => (int) ($resPut['http_code'] ?? 0),
                'error_body' => $resPut['body'],
            ];
        }
        $id = cronJobOrgResolverJobIdAposPutCriacao($resPut['body'], $titulo);
        if ($id === null) {
            $putHttp = (int) ($resPut['http_code'] ?? 0);

            return [
                'success' => true,
                'message' => 'O job «' . $titulo . '» foi enviado à cron-job.org (PUT OK), mas a API limitou pedidos (HTTP 429) ao listar jobs e não foi possível obter o ID agora. Na próxima sincronização o ID será guardado automaticamente. Evite vários cliques seguidos em «Ativar».' . $urlOverrideWarn,
                'job_id' => null,
                'http_code' => $putHttp,
                'error_body' => null,
                'sync_partial_no_job_id' => true,
            ];
        }
        setConfig('cron_global_job_id', $id);
        cronJobOrgPersistGlobalLastSynced($iv, $h1, $h2, $jobUrl, $titulo, $tok);
        $msg = 'Job criado na cron-job.org com o título "' . $titulo . '".';
        if (!$listOk) {
            $msg .= ' Não foi possível listar os jobs antes; confira no painel se não existia já um "' . $titulo . '".';
        }

        return ['success' => true, 'message' => $msg . $dupMsg . $urlOverrideWarn, 'job_id' => $id, 'skipped' => false];
    }

    if (cronJobOrgGlobalPatchRedundante($iv, $h1, $h2, $jobUrl, $titulo, $tok)) {
        cronJobOrgIntegrationDebugLog('skip_sync_no_changes', [
            'hypothesisId' => 'SKIP',
            'context' => 'global',
            'job_id_len' => strlen($jid),
        ]);

        return [
            'success' => true,
            'message' => 'Já sincronizado com a cron-job.org (título "' . $titulo . '", agenda, URL e opções iguais à última gravação).' . $dupMsg . $urlOverrideWarn,
            'job_id' => $jid,
            'skipped' => true,
        ];
    }

    $res = cronJobOrgRequest('PATCH', '/jobs/' . $jid, ['job' => $payloadJob], true);
    if ($res['success']) {
        cronJobOrgPersistGlobalLastSynced($iv, $h1, $h2, $jobUrl, $titulo, $tok);
        $msg = 'Job "' . $titulo . '" atualizado na cron-job.org (URL, agenda, método GET, ativo).';
        if ($reusedByTitle) {
            $msg = 'O job "' . $titulo . '" já existia na sua conta; foi reutilizado e atualizado (não foi criado outro).' . $dupMsg;
        } else {
            $msg .= $dupMsg;
        }

        return ['success' => true, 'message' => $msg . $urlOverrideWarn, 'job_id' => $jid, 'skipped' => false];
    }

    $patchHttp = (int) ($res['http_code'] ?? 0);
    cronJobOrgIntegrationDebugLog('patch_fail', [
        'hypothesisId' => 'H4',
        'context' => 'global',
        'jid' => $jid,
        'http_code' => $patchHttp,
        'will_recreate' => ($patchHttp === 404 || $patchHttp === 410),
    ]);

    if ($patchHttp === 404 || $patchHttp === 410) {
        setConfig('cron_global_job_id', '');
        $list2 = cronJobListarJobsConta(false);
        $jobs2 = (!empty($list2['success']) && is_array($list2['jobs'])) ? $list2['jobs'] : [];
        $b2 = cronJobOrgBuscarJobsPorTituloExato($jobs2, $titulo);
        if ($b2['primeiro_id'] !== null && $b2['primeiro_id'] !== '') {
            $jid2 = $b2['primeiro_id'];
            setConfig('cron_global_job_id', $jid2);
            $res3 = cronJobOrgRequest('PATCH', '/jobs/' . $jid2, ['job' => $payloadJob], true);
            if ($res3['success']) {
                cronJobOrgPersistGlobalLastSynced($iv, $h1, $h2, $jobUrl, $titulo, $tok);

                return [
                    'success' => true,
                    'message' => 'O ID guardado apontava para um job apagado; foi encontrado "' . $titulo . '" pelo nome e atualizado.' . $urlOverrideWarn,
                    'job_id' => $jid2,
                    'skipped' => false,
                ];
            }
        }

        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if (!$resPut['success']) {
            return [
                'success' => false,
                'message' => $resPut['message'],
                'job_id' => null,
                'http_code' => (int) ($resPut['http_code'] ?? 0),
                'error_body' => $resPut['body'],
            ];
        }
        $id = cronJobOrgResolverJobIdAposPutCriacao($resPut['body'], $titulo);
        if ($id === null) {
            $putHttp = (int) ($resPut['http_code'] ?? 0);

            return [
                'success' => true,
                'message' => 'O job «' . $titulo . '» foi criado na cron-job.org, mas não foi possível obter o ID (limite da API / HTTP 429 ao listar). Na próxima sincronização o sistema guardará o ID. Evite vários cliques seguidos.' . $urlOverrideWarn,
                'job_id' => null,
                'http_code' => $putHttp,
                'error_body' => null,
                'sync_partial_no_job_id' => true,
            ];
        }
        setConfig('cron_global_job_id', $id);
        cronJobOrgPersistGlobalLastSynced($iv, $h1, $h2, $jobUrl, $titulo, $tok);
        cronJobOrgIntegrationDebugLog('recreate_ok', [
            'hypothesisId' => 'H4',
            'context' => 'global',
            'new_id_len' => strlen($id),
        ]);

        return [
            'success' => true,
            'message' => 'Job "' . $titulo . '" criado na cron-job.org (o registro anterior não existia mais e não havia outro com o mesmo nome).' . $urlOverrideWarn,
            'job_id' => $id,
            'skipped' => false,
        ];
    }

    cronJobOrgIntegrationDebugLog('final_fail', ['hypothesisId' => 'H4', 'context' => 'global', 'patch_http' => $patchHttp]);

    return [
        'success' => false,
        'message' => $res['message'],
        'job_id' => null,
        'http_code' => $patchHttp,
        'error_body' => $res['body'],
    ];
}

/**
 * URL do job na cron-job.org: mesmo script para todas as lojas, com ?loja=chave (ml, shopee, …).
 * O token vai em cabeçalho X-Cron-Token (API); legado: ?token= em {@see cronJobUrlRodarLojaComQuery}.
 *
 * @param string $loja        chave interna (ex.: ml)
 * @param string $tokenLoja   mantido na assinatura por compatibilidade; não entra na URL desta função
 */
function cronJobUrlRodarLoja(string $loja, string $tokenLoja): string {
    $base = cronPublicBaseUrl();
    if ($base === '') {
        return '';
    }
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return $base . '/cron/rodar-loja.php';
    }

    return $base . '/cron/rodar-loja.php?' . http_build_query(['loja' => $loja], '', '&', PHP_QUERY_RFC3986);
}

/** URL com ?loja=&token= para testes manuais. */
function cronJobUrlRodarLojaComQuery(string $loja, string $tokenLoja): string {
    $base = cronPublicBaseUrl();
    if ($base === '') {
        return '';
    }
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    $q = ['loja' => $loja];
    if (trim($tokenLoja) !== '') {
        $q['token'] = $tokenLoja;
    }

    return $base . '/cron/rodar-loja.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param array<string, mixed> $cfg Retorno de dadosCronLoja()
 *
 * @return array{success: bool, message: string, job_id: ?string, skipped?: bool}
 */
function cronJobSincronizarLoja(string $loja, array $cfg): array {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return ['success' => false, 'message' => 'Loja inválida.', 'job_id' => null];
    }
    if (empty($cfg['cron_individual_ativo'])) {
        return ['success' => false, 'message' => 'Cron individual desligado para esta loja.', 'job_id' => null];
    }
    $token = trim((string) ($cfg['token'] ?? ''));
    $iv = CronPolicy::normalizeInterval((int) ($cfg['intervalo_minutos'] ?? 5));
    $h1 = max(0, min(23, (int) ($cfg['hora_inicio'] ?? 0)));
    $h2 = max(0, min(23, (int) ($cfg['hora_fim'] ?? 23)));
    $stored = preg_replace('/\D/', '', trim((string) ($cfg['cron_job_id'] ?? '')));

    if ($token === '') {
        return ['success' => false, 'message' => 'Defina um token para a loja (cron individual).', 'job_id' => null];
    }

    $jobUrl = cronJobUrlRodarLojaComQuery($loja, $token);
    $fpSalt = $token . '|' . $loja;
    if ($jobUrl === '') {
        return ['success' => false, 'message' => 'URL pública não configurada (Configurações → Crons).', 'job_id' => null];
    }
    $blockLoja = cronJobOrgSyncBlockMessage($jobUrl);
    if ($blockLoja !== null) {
        return ['success' => false, 'message' => $blockLoja, 'job_id' => null];
    }
    $urlOverrideWarn = cronJobOrgNonPublicSyncOverrideWarningPt($jobUrl);
    cronJobOrgJobUrlSemTokenAviso('cronJobSincronizarLoja:' . $loja, $jobUrl, $token !== '');

    $schedule = cronJobOrgSchedulePayload($iv, $h1, $h2);
    $titulo = cronJobOrgTituloLoja($loja);
    $payloadJob = cronJobOrgPayloadJobCompleto($titulo, $jobUrl, $schedule, true, null, null);

    $listRes = cronJobListarJobsConta(false);
    $listOk = !empty($listRes['success']);
    $jobs = ($listOk && is_array($listRes['jobs'])) ? $listRes['jobs'] : [];
    $busca = cronJobOrgBuscarJobLojaPorTituloNovoOuLegado($jobs, $loja);
    $idPorTitulo = $busca['primeiro_id'];
    $dupMsg = '';
    if ($busca['duplicados'] && $idPorTitulo !== null && $idPorTitulo !== '') {
        $dupMsg = ' Atenção: há mais de um job com o mesmo título de identificação; foi usado o ID ' . $idPorTitulo . '. Apague duplicados no console cron-job.org.';
    }

    $jid = '';
    if ($idPorTitulo !== null && $idPorTitulo !== '') {
        $jid = $idPorTitulo;
    } elseif ($stored !== '') {
        $jid = $stored;
    }

    $reusedByTitle = ($idPorTitulo !== null && $idPorTitulo !== '' && $idPorTitulo === $jid);

    if ($jid === '' && !$listOk) {
        for ($round = 0; $round < 3; $round++) {
            if ($round > 0) {
                usleep(2000000);
            }
            $lr = cronJobListarJobsConta(false);
            if (empty($lr['success']) || !is_array($lr['jobs'])) {
                continue;
            }
            $br = cronJobOrgBuscarJobLojaPorTituloNovoOuLegado($lr['jobs'], $loja);
            if ($br['primeiro_id'] !== null && $br['primeiro_id'] !== '') {
                $jid = (string) $br['primeiro_id'];
                break;
            }
        }
    }

    if ($jid === '') {
        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if (!$resPut['success']) {
            return ['success' => false, 'message' => $resPut['message'], 'job_id' => null];
        }
        $id = cronJobOrgResolverJobIdAposPutLoja($resPut['body'], $loja);
        if ($id === null) {
            return [
                'success' => true,
                'message' => 'Job da loja foi enviado à cron-job.org (PUT OK), mas não foi possível obter o ID (limite da API ao listar). Na próxima sincronização o ID será guardado. Evite vários cliques seguidos.' . $urlOverrideWarn,
                'job_id' => null,
                'sync_partial_no_job_id' => true,
            ];
        }
        cronJobOrgPersistLojaLastSynced($loja, $iv, $h1, $h2, $jobUrl, $titulo, $fpSalt);
        $msg = 'Job criado na cron-job.org com o título "' . $titulo . '" (uma cron por loja).';
        if (!$listOk) {
            $msg .= ' Não foi possível listar os jobs antes; confira no painel se não existia já um "' . $titulo . '".';
        }

        return ['success' => true, 'message' => $msg . $dupMsg . $urlOverrideWarn, 'job_id' => $id, 'skipped' => false];
    }

    if (cronJobOrgLojaPatchRedundante($loja, $iv, $h1, $h2, $jobUrl, $titulo, $fpSalt)) {
        cronJobOrgIntegrationDebugLog('skip_sync_no_changes', [
            'hypothesisId' => 'SKIP',
            'context' => 'loja',
            'loja' => $loja,
            'job_id_len' => strlen($jid),
        ]);

        return [
            'success' => true,
            'message' => 'Já sincronizado (título "' . $titulo . '", agenda e URL iguais à última gravação).' . $dupMsg . $urlOverrideWarn,
            'job_id' => $jid,
            'skipped' => true,
        ];
    }

    $res = cronJobOrgRequest('PATCH', '/jobs/' . $jid, ['job' => $payloadJob], true);
    if ($res['success']) {
        cronJobOrgPersistLojaLastSynced($loja, $iv, $h1, $h2, $jobUrl, $titulo, $fpSalt);
        if ($reusedByTitle) {
            return [
                'success' => true,
                'message' => 'O job "' . $titulo . '" já existia na conta; foi reutilizado e atualizado (não foi criada outra cron para esta loja).' . $dupMsg . $urlOverrideWarn,
                'job_id' => $jid,
                'skipped' => false,
            ];
        }

        return [
            'success' => true,
            'message' => 'Job "' . $titulo . '" atualizado na cron-job.org (URL, agenda, método GET, ativo).' . $dupMsg . $urlOverrideWarn,
            'job_id' => $jid,
            'skipped' => false,
        ];
    }

    $patchHttp = (int) ($res['http_code'] ?? 0);
    if ($patchHttp === 404 || $patchHttp === 410) {
        $list2 = cronJobListarJobsConta(false);
        $jobs2 = (!empty($list2['success']) && is_array($list2['jobs'])) ? $list2['jobs'] : [];
        $b2 = cronJobOrgBuscarJobLojaPorTituloNovoOuLegado($jobs2, $loja);
        if ($b2['primeiro_id'] !== null && $b2['primeiro_id'] !== '') {
            $jid2 = $b2['primeiro_id'];
            $res3 = cronJobOrgRequest('PATCH', '/jobs/' . $jid2, ['job' => $payloadJob], true);
            if ($res3['success']) {
                cronJobOrgPersistLojaLastSynced($loja, $iv, $h1, $h2, $jobUrl, $titulo, $fpSalt);

                return [
                    'success' => true,
                    'message' => 'O ID guardado apontava para um job apagado; foi encontrado "' . $titulo . '" pelo nome e atualizado.' . $urlOverrideWarn,
                    'job_id' => $jid2,
                    'skipped' => false,
                ];
            }
        }

        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if (!$resPut['success']) {
            return ['success' => false, 'message' => $resPut['message'], 'job_id' => null];
        }
        $id = cronJobOrgResolverJobIdAposPutLoja($resPut['body'], $loja);
        if ($id === null) {
            return [
                'success' => true,
                'message' => 'Job da loja foi criado na cron-job.org, mas não foi possível obter o ID (limite da API). Na próxima sincronização o ID será guardado.' . $urlOverrideWarn,
                'job_id' => null,
                'sync_partial_no_job_id' => true,
            ];
        }
        cronJobOrgPersistLojaLastSynced($loja, $iv, $h1, $h2, $jobUrl, $titulo, $fpSalt);

        return [
            'success' => true,
            'message' => 'Job "' . $titulo . '" criado na cron-job.org (o registro anterior não existia e não havia outro com o mesmo nome).' . $urlOverrideWarn,
            'job_id' => $id,
            'skipped' => false,
        ];
    }

    return ['success' => false, 'message' => $res['message'], 'job_id' => null];
}

/**
 * @return array{success: bool, message: string}
 */
function cronJobDelete(string $jobId): array {
    $jobId = preg_replace('/\D/', '', (string) $jobId);
    if ($jobId === '') {
        return ['success' => true, 'message' => 'Nada a remover.'];
    }
    $res = cronJobOrgRequest('DELETE', '/jobs/' . $jobId, null);
    if (!empty($res['success'])) {
        return ['success' => true, 'message' => 'OK'];
    }
    $http = (int) ($res['http_code'] ?? 0);
    // Job já apagado ou ID desconhecido: tratamos como sucesso para o painel (lista atualiza sem erro).
    if ($http === 404 || $http === 410) {
        return ['success' => true, 'message' => 'Job já estava removido na cron-job.org.'];
    }

    return ['success' => false, 'message' => (string) ($res['message'] ?? 'Falha ao excluir.')];
}

/**
 * Lista todos os jobs da conta na cron-job.org (GET /jobs apenas, sem query string).
 *
 * @param bool $forcarSemCache Legado; não altera a URL (evita cache bust inválido).
 *
 * @return array{success: bool, message: string, jobs: array<int, array<string, mixed>>}
 */
function cronJobListarJobsConta(bool $forcarSemCache = false): array {
    $res = cronJobOrgRequest('GET', '/jobs', null, true);
    if (!$res['success']) {
        return ['success' => false, 'message' => $res['message'], 'jobs' => []];
    }

    $body = is_array($res['body']) ? $res['body'] : [];
    $jobs = [];
    if (isset($body['jobs']) && is_array($body['jobs'])) {
        $jobs = array_values(array_filter($body['jobs'], static function ($x): bool {
            return is_array($x);
        }));
    } elseif (isset($body['items']) && is_array($body['items'])) {
        $jobs = array_values(array_filter($body['items'], static function ($x): bool {
            return is_array($x);
        }));
    } elseif (function_exists('array_is_list') && array_is_list($body)) {
        $jobs = array_values(array_filter($body, static function ($x): bool {
            return is_array($x);
        }));
    }

    return ['success' => true, 'message' => 'OK', 'jobs' => $jobs];
}

/**
 * Detalhes completos de um job (GET /jobs/{jobId}).
 *
 * @return array{success: bool, message: string, job: ?array<string, mixed>}
 */
function cronJobObterDetalhesJob(string $jobId): array {
    $jid = preg_replace('/\D/', '', $jobId);
    if ($jid === '') {
        return ['success' => false, 'message' => 'ID do job inválido.', 'job' => null];
    }
    $res = cronJobOrgRequest('GET', '/jobs/' . $jid, null, true);
    if (!$res['success']) {
        return ['success' => false, 'message' => $res['message'], 'job' => null];
    }
    $body = is_array($res['body']) ? $res['body'] : [];
    $job = $body['jobDetails'] ?? null;
    if (!is_array($job)) {
        return ['success' => false, 'message' => 'Resposta sem jobDetails.', 'job' => null];
    }
    // #region agent log (81af1f audit)
    $remoteUrl = isset($job['url']) ? (string) $job['url'] : '';
    cronAudit81af1fLog('H3', 'CronJobService.php:cronJobObterDetalhesJob', 'remote_job_url_shape', [
        'url_meta' => $remoteUrl !== '' ? cronAudit81af1fUrlMeta($remoteUrl) : ['host' => '', 'likely_non_public_dns' => true, 'dns_risk_flags' => ['no_url_in_jobdetails']],
        'enabled' => $job['enabled'] ?? null,
        'job_id_len' => strlen($jid),
    ]);
    // #endregion

    return ['success' => true, 'message' => 'OK', 'job' => $job];
}

/**
 * Extrai se o job está habilitado na cron-job.org (resposta GET /jobs/{id}).
 *
 * @param array<string, mixed> $jobDetails
 */
function cronJobDetalhesEstaHabilitado(array $jobDetails): bool {
    if (array_key_exists('enabled', $jobDetails)) {
        return (bool) $jobDetails['enabled'];
    }
    $nested = $jobDetails['job'] ?? null;
    if (is_array($nested) && array_key_exists('enabled', $nested)) {
        return (bool) $nested['enabled'];
    }

    return true;
}

/**
 * Estado remoto do job armazenado (para painel). Usa cache curto em config.
 *
 * @return array{ok: bool, habilitado: bool, mensagem: string}
 */
function cronJobEstadoRemotoJobArmazenado(string $jobId): array {
    $jid = trim(preg_replace('/\D/', '', $jobId));
    if ($jid === '') {
        return ['ok' => false, 'habilitado' => false, 'mensagem' => 'ID do job inválido.'];
    }
    $cacheRaw = trim((string) getConfig('cron_global_org_probe_cache', ''));
    $now = time();
    if ($cacheRaw !== '') {
        $cached = json_decode($cacheRaw, true);
        if (is_array($cached)
            && (string) ($cached['jid'] ?? '') === $jid
            && isset($cached['ts'])
            && ($now - (int) $cached['ts']) < 120) {
            // #region agent log (81af1f audit)
            cronAudit81af1fLog('H4', 'CronJobService.php:cronJobEstadoRemotoJobArmazenado', 'probe_cache_hit', [
                'jid_len' => strlen($jid),
                'cached_api_ok' => !empty($cached['api_ok']),
                'cache_age_sec' => $now - (int) $cached['ts'],
            ]);
            // #endregion
            return [
                'ok' => !empty($cached['api_ok']),
                'habilitado' => !empty($cached['habilitado']),
                'mensagem' => (string) ($cached['mensagem'] ?? ''),
            ];
        }
    }
    $det = cronJobObterDetalhesJob($jid);
    $pack = [
        'jid' => $jid,
        'ts' => $now,
        'api_ok' => $det['success'],
        'habilitado' => $det['success'] ? cronJobDetalhesEstaHabilitado($det['job'] ?? []) : false,
        'mensagem' => $det['success'] ? '' : (string) ($det['message'] ?? 'Erro API cron-job.org'),
    ];
    setConfig('cron_global_org_probe_cache', json_encode($pack, JSON_UNESCAPED_UNICODE));
    // #region agent log (81af1f audit)
    cronAudit81af1fLog('H4', 'CronJobService.php:cronJobEstadoRemotoJobArmazenado', 'probe_fresh', [
        'jid_len' => strlen($jid),
        'api_ok' => !empty($det['success']),
        'habilitado' => $pack['habilitado'],
        'note' => 'API cron-job.org OK nao implica DNS/HTTP OK ate o teu servidor',
    ]);
    // #endregion

    return [
        'ok' => $det['success'],
        'habilitado' => $pack['habilitado'],
        'mensagem' => $pack['mensagem'],
    ];
}

/**
 * Atualiza campos de um job na cron-job.org (PATCH parcial).
 *
 * @param array<string, mixed> $delta Campos permitidos em job (title, url, enabled, schedule, ...)
 * @return array{success: bool, message: string}
 */
function cronJobAtualizarNaOrg(string $jobId, array $delta): array {
    $jid = preg_replace('/\D/', '', $jobId);
    if ($jid === '') {
        return ['success' => false, 'message' => 'ID do job inválido.'];
    }
    if ($delta === []) {
        return ['success' => false, 'message' => 'Nenhum campo para atualizar.'];
    }
    $res = cronJobOrgRequest('PATCH', '/jobs/' . $jid, ['job' => $delta], true);

    return ['success' => $res['success'], 'message' => $res['message']];
}

// —— Jobs por grupo WhatsApp (cron/rodar-grupo.php) ——————————————————

function cronJobOrgTituloGrupoWhatsapp(int $grupoId, string $nome): string {
    $nome = trim($nome);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($nome) > 42) {
            $nome = mb_substr($nome, 0, 39) . '…';
        }
    } elseif (strlen($nome) > 42) {
        $nome = substr($nome, 0, 39) . '…';
    }

    return 'achadinhos-grupo-' . $grupoId . ($nome !== '' ? ' — ' . $nome : '');
}

function cronGrupoWhatsappHoraDeColunaSql($sqlTime): int {
    if ($sqlTime === null || (string) $sqlTime === '') {
        return -1;
    }
    if (!function_exists('normalizarHoraPostagemGrupo')) {
        require_once dirname(__DIR__, 2) . '/config/functions.php';
    }
    $n = normalizarHoraPostagemGrupo($sqlTime);
    if ($n === null) {
        return -1;
    }
    if (preg_match('/^(\d{1,2})/', $n, $m)) {
        return max(0, min(23, (int) $m[1]));
    }

    return -1;
}

/**
 * @return array{0: int, 1: int, 2: int} intervalo normalizado, hora início, hora fim (0–23)
 */
function cronGrupoWhatsappIntervaloEJanela(array $grupo): array {
    if (!function_exists('gruposNormalizarAutomacaoLojaParaAgenda')) {
        require_once dirname(__DIR__, 2) . '/config/functions.php';
    }
    $loja = gruposNormalizarAutomacaoLojaParaAgenda($grupo);
    $delayKeys = [
        'ml' => 'ml_delay_entre_envios',
        'shopee' => 'shopee_delay_entre_envios',
        'magalu' => 'magalu_delay_entre_envios',
        'amazon' => 'amazon_delay_entre_envios',
        'shein' => 'shein_delay_entre_envios',
        'aliexpress' => 'aliexpress_delay_entre_envios',
        'ml_cupons' => 'ml_cupons_delay_entre_envios',
    ];
    $dk = $delayKeys[$loja] ?? 'ml_delay_entre_envios';
    $ivGr = isset($grupo['intervalo_minutos']) && $grupo['intervalo_minutos'] !== null && $grupo['intervalo_minutos'] !== ''
        ? (int) $grupo['intervalo_minutos'] : 0;
    $iv = ($ivGr > 0)
        ? CronPolicy::normalizeInterval($ivGr)
        : CronPolicy::normalizeInterval(max(1, min(1440, (int) getConfig($dk, '10'))));

    $hi = cronGrupoWhatsappHoraDeColunaSql($grupo['post_hora_inicio'] ?? null);
    $hf = cronGrupoWhatsappHoraDeColunaSql($grupo['post_hora_fim'] ?? null);
    if ($hi < 0 || $hf < 0) {
        return [$iv, 0, 23];
    }

    return [$iv, $hi, $hf];
}

function cronJobUrlRodarGrupoWhatsapp(int $grupoId): string {
    if (!function_exists('achadinhosCronTokenHttpOficialLer')) {
        $fp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'functions.php';
        if (is_file($fp)) {
            require_once $fp;
        }
    }
    $base = cronPublicBaseUrl();
    if ($base === '') {
        return '';
    }
    $grupoId = max(1, $grupoId);
    $tok = function_exists('achadinhosCronTokenHttpOficialLer') ? achadinhosCronTokenHttpOficialLer() : trim((string) getConfig('cron_token', ''));
    $q = ['grupo' => (string) $grupoId];
    if ($tok !== '') {
        $q['token'] = $tok;
    }

    return $base . '/cron/rodar-grupo.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

function cronJobGrupoWhatsappGarantirColunaId(): void {
    $sch = dirname(__DIR__) . '/db/SchemaHelper.php';
    if (is_file($sch)) {
        require_once $sch;
        if (function_exists('garantirColunaGruposWhatsappCronJobOrgId')) {
            garantirColunaGruposWhatsappCronJobOrgId();
        }
    }
}

function cronJobGrupoWhatsappPersistJobId(int $grupoId, ?string $jobId): void {
    $grupoId = max(1, $grupoId);
    $jid = preg_replace('/\D/', '', (string) $jobId);
    try {
        if (!function_exists('getDB')) {
            require_once dirname(__DIR__, 2) . '/config/database.php';
        }
        cronJobGrupoWhatsappGarantirColunaId();
        $pdo = getDB();
        $st = $pdo->prepare('UPDATE grupos_whatsapp SET cron_job_org_job_id = ? WHERE id = ?');
        $st->execute([$jid !== '' ? $jid : null, $grupoId]);
    } catch (Throwable $e) {
        error_log('cronJobGrupoWhatsappPersistJobId: ' . $e->getMessage());
    }
}

/**
 * Auditoria local + log NDJSON da sincronização cron-job.org por grupo (sem API key).
 *
 * @param array<string, mixed> $res Retorno parcial (success, message, job_id?, http_code?, sync_partial_no_job_id?)
 * @param array<string, mixed>|null $grupoRow Linha grupos_whatsapp quando disponível
 */
function cronJobGrupoWhatsappPersistSyncAudit(int $grupoId, ?array $grupoRow, array $res, string $lastOp, bool $staleCleared = false): void {
    $grupoId = max(1, $grupoId);
    try {
        if (!function_exists('getDB')) {
            require_once dirname(__DIR__, 2) . '/config/database.php';
        }
        $sch = dirname(__DIR__) . '/db/SchemaHelper.php';
        if (is_file($sch)) {
            require_once $sch;
            if (function_exists('garantirColunasGruposWhatsappCronOrgSyncAudit')) {
                garantirColunasGruposWhatsappCronOrgSyncAudit();
            }
        }
        if (!function_exists('colunaExiste')) {
            return;
        }
        $pdo = getDB();
        $ok = !empty($res['success']) ? 1 : 0;
        $partial = !empty($res['sync_partial_no_job_id']) ? 1 : 0;
        $http = array_key_exists('http_code', $res) ? (int) $res['http_code'] : null;
        if ($http === 0) {
            $http = null;
        }
        $msg = trim((string) ($res['message'] ?? ''));
        if ($staleCleared) {
            $msg = '[Vínculo antigo com a cron-job.org foi invalidado (job removido ou ID obsoleto); reassociação executada.] ' . $msg;
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($msg, 'UTF-8') > 500) {
            $msg = mb_substr($msg, 0, 497, 'UTF-8') . '…';
        } elseif (strlen($msg) > 500) {
            $msg = substr($msg, 0, 497) . '…';
        }
        if (colunaExiste('grupos_whatsapp', 'cron_org_sync_at')) {
            $st = $pdo->prepare(
                'UPDATE grupos_whatsapp SET cron_org_sync_at = NOW(), cron_org_sync_http_code = ?, cron_org_sync_ok = ?, ' .
                'cron_org_sync_message = ?, cron_org_sync_partial_no_job = ?, cron_org_sync_last_op = ? WHERE id = ?'
            );
            $st->execute([$http, $ok, $msg !== '' ? $msg : null, $partial, $lastOp !== '' ? $lastOp : null, $grupoId]);
        }
    } catch (Throwable $e) {
        error_log('cronJobGrupoWhatsappPersistSyncAudit: ' . $e->getMessage());
    }

    $jidResolved = isset($res['job_id']) ? preg_replace('/\D/', '', (string) $res['job_id']) : '';
    $jidStored = '';
    if (is_array($grupoRow)) {
        $jidStored = preg_replace('/\D/', '', trim((string) ($grupoRow['cron_job_org_job_id'] ?? '')));
    }
    $httpLog = array_key_exists('http_code', $res) ? (int) $res['http_code'] : null;
    if ($httpLog === 0) {
        $httpLog = null;
    }
    cronJobOrgIntegrationDebugLog('grupo_cron_org_sync', [
        'hypothesisId' => 'GRUPO_SYNC',
        'grupo_interno_id' => $grupoId,
        'job_title' => is_array($grupoRow) ? cronJobOrgTituloGrupoWhatsapp($grupoId, (string) ($grupoRow['nome'] ?? '')) : '',
        'grupo_jid_prefix' => is_array($grupoRow) ? substr((string) ($grupoRow['grupo_id'] ?? ''), 0, 64) : '',
        'automacao_loja' => is_array($grupoRow) ? (string) ($grupoRow['automacao_loja'] ?? '') : '',
        'last_op' => $lastOp,
        'stale_cleared' => $staleCleared,
        'http_code' => $httpLog,
        'api_success' => !empty($res['success']),
        'partial_no_job_id' => !empty($res['sync_partial_no_job_id']),
        'job_id_resolved_len' => strlen($jidResolved),
        'job_id_local_before_len' => strlen($jidStored),
    ]);
}

/**
 * Cria ou atualiza na cron-job.org o job que chama cron/rodar-grupo.php.
 *
 * @return array{success: bool, message: string, job_id: ?string, skipped?: bool, http_code?: int, sync_partial_no_job_id?: bool, cron_sync_failure_code?: string, token_http_official_auto_generated?: bool}
 */
function cronJobSincronizarGrupoWhatsapp(int $grupoId): array {
    if (!function_exists('achadinhosCronGarantirTokenHttpOficialParaSync')) {
        $fp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'functions.php';
        if (is_file($fp)) {
            require_once $fp;
        }
    }
    if (!function_exists('getConfig')) {
        require_once dirname(__DIR__, 2) . '/config/database.php';
    }
    $tokGenMeta = ['token_http_official_auto_generated' => false];
    $out = static function (array $r) use (&$tokGenMeta) {
        $r['token_http_official_auto_generated'] = !empty($tokGenMeta['token_http_official_auto_generated']);
        if (!empty($r['success']) && !empty($tokGenMeta['token_http_official_auto_generated'])) {
            $r['message'] = ($r['message'] ?? '') . ' O token HTTP principal (cron_token) foi gerado e gravado automaticamente; confira Configurações → Crons.';
        }

        return $r;
    };
    $apiKey = trim((string) getConfig('cron_job_org_api_key', ''));
    if ($apiKey === '') {
        $r = ['success' => false, 'message' => 'Chave API cron-job.org não configurada.', 'job_id' => null, 'http_code' => 0];
        cronJobGrupoWhatsappPersistSyncAudit(max(1, $grupoId), null, $r, 'precheck_no_api_key');

        return $out($r);
    }
    $grupoId = max(1, $grupoId);
    try {
        if (!function_exists('getDB')) {
            require_once dirname(__DIR__, 2) . '/config/database.php';
        }
        cronJobGrupoWhatsappGarantirColunaId();
        $pdo = getDB();
        $st = $pdo->prepare('SELECT * FROM grupos_whatsapp WHERE id = ? LIMIT 1');
        $st->execute([$grupoId]);
        $grupo = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $r = ['success' => false, 'message' => 'Erro ao ler grupo: ' . $e->getMessage(), 'job_id' => null];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, null, $r, 'db_error');

        return $out($r);
    }
    if (!$grupo) {
        $r = ['success' => false, 'message' => 'Grupo não encontrado.', 'job_id' => null];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, null, $r, 'not_found');

        return $out($r);
    }

    $tokGen = function_exists('achadinhosCronGarantirTokenHttpOficialParaSync')
        ? achadinhosCronGarantirTokenHttpOficialParaSync()
        : ['ok' => true, 'message' => '', 'generated' => false];
    if (empty($tokGen['ok'])) {
        $r = [
            'success' => false,
            'message' => (string) ($tokGen['message'] ?? 'Não foi possível garantir cron_token para sincronizar o job.'),
            'job_id' => null,
            'http_code' => 0,
            'cron_sync_failure_code' => 'token_http_official',
        ];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'precheck_token_http');

        return $out($r);
    }
    if (!empty($tokGen['generated'])) {
        $tokGenMeta['token_http_official_auto_generated'] = true;
    }

    $nome = (string) ($grupo['nome'] ?? '');
    $titulo = cronJobOrgTituloGrupoWhatsapp($grupoId, $nome);
    $jobUrl = cronJobUrlRodarGrupoWhatsapp($grupoId);
    if ($jobUrl === '') {
        $r = ['success' => false, 'message' => 'URL pública do site não configurada (cron_public_base_url ou acesse o admin pelo domínio público).', 'job_id' => null];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'precheck_no_public_url');

        return $out($r);
    }
    if (strpos($jobUrl, 'token=') === false) {
        $r = [
            'success' => false,
            'message' => 'URL do job de grupo sem ?token=: cron_token não está disponível. Verifique configuracoes ou permissões de escrita.',
            'job_id' => null,
            'http_code' => 0,
            'cron_sync_failure_code' => 'token_http_url_invalid',
        ];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'precheck_url_sem_token');

        return $out($r);
    }
    $blockMsg = cronJobOrgSyncBlockMessage($jobUrl);
    if ($blockMsg !== null) {
        $r = ['success' => false, 'message' => $blockMsg, 'job_id' => null];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'precheck_block');

        return $out($r);
    }
    $urlOverrideWarn = cronJobOrgNonPublicSyncOverrideWarningPt($jobUrl);

    [$iv, $h1, $h2] = cronGrupoWhatsappIntervaloEJanela($grupo);
    $enabled = !empty($grupo['ativo']) && (int) $grupo['ativo'] === 1;
    $schedule = cronJobOrgSchedulePayload($iv, $h1, $h2);
    $tokHttp = function_exists('achadinhosCronTokenHttpOficialLer') ? achadinhosCronTokenHttpOficialLer() : '';
    $payloadJob = cronJobOrgPayloadJobCompleto(
        $titulo,
        $jobUrl,
        $schedule,
        $enabled,
        $tokHttp !== '' ? $tokHttp : null,
        null
    );

    $stored = preg_replace('/\D/', '', trim((string) ($grupo['cron_job_org_job_id'] ?? '')));

    $listRes = cronJobListarJobsConta(false);
    $listOk = !empty($listRes['success']);
    $jobs = ($listOk && is_array($listRes['jobs'])) ? $listRes['jobs'] : [];
    $busca = cronJobOrgBuscarJobsPorTituloExato($jobs, $titulo);
    $idPorTitulo = $busca['primeiro_id'];
    $dupMsg = '';
    if ($busca['duplicados'] && $idPorTitulo !== null && $idPorTitulo !== '') {
        $dupMsg = ' Atenção: há jobs duplicados com o título "' . $titulo . '"; usado ID ' . $idPorTitulo . '.';
    }

    $jid = '';
    if ($idPorTitulo !== null && $idPorTitulo !== '') {
        $jid = $idPorTitulo;
        if ($stored !== $jid) {
            cronJobGrupoWhatsappPersistJobId($grupoId, $jid);
        }
    } elseif ($stored !== '') {
        $jid = $stored;
    }

    if ($jid === '' && !$listOk) {
        $recG = cronJobOrgResolverJobIdPorTituloExato($titulo, 3);
        if ($recG !== null && $recG !== '') {
            $jid = $recG;
            cronJobGrupoWhatsappPersistJobId($grupoId, $jid);
        }
    }

    if ($jid === '') {
        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if (!$resPut['success']) {
            $r = [
                'success' => false,
                'message' => $resPut['message'],
                'job_id' => null,
                'http_code' => (int) ($resPut['http_code'] ?? 0),
            ];
            cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'put_fail');

            return $out($r);
        }
        $id = cronJobOrgResolverJobIdAposPutCriacao($resPut['body'], $titulo);
        if ($id === null) {
            $r = [
                'success' => true,
                'message' => 'A cron-job.org aceitou o job (PUT OK), mas o sistema ainda não obteve o ID numérico (limite ou atraso da API). O vínculo local pode estar vazio até à próxima sincronização — evite cliques repetidos. Consulte na lista de grupos a coluna de última sync.' . $urlOverrideWarn,
                'job_id' => null,
                'http_code' => (int) ($resPut['http_code'] ?? 0),
                'sync_partial_no_job_id' => true,
            ];
            cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'put_ok_no_job_id');

            return $out($r);
        }
        cronJobGrupoWhatsappPersistJobId($grupoId, $id);
        $r = [
            'success' => true,
            'message' => 'Job criado na cron-job.org para a regra #' . $grupoId . ' (título: ' . $titulo . ').' . $dupMsg . $urlOverrideWarn,
            'job_id' => $id,
            'skipped' => false,
            'http_code' => (int) ($resPut['http_code'] ?? 0),
        ];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'put_create');

        return $out($r);
    }

    $res = cronJobOrgRequest('PATCH', '/jobs/' . $jid, ['job' => $payloadJob], true);
    if ($res['success']) {
        cronJobGrupoWhatsappPersistJobId($grupoId, $jid);
        $r = [
            'success' => true,
            'message' => 'Job da regra #' . $grupoId . ' atualizado na cron-job.org (PATCH).' . $dupMsg . $urlOverrideWarn,
            'job_id' => $jid,
            'skipped' => false,
            'http_code' => (int) ($res['http_code'] ?? 0),
        ];
        cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'patch_ok');

        return $out($r);
    }
    $patchHttp = (int) ($res['http_code'] ?? 0);
    if ($patchHttp === 404 || $patchHttp === 410) {
        cronJobGrupoWhatsappPersistJobId($grupoId, '');
        $grupoAfterClear = $grupo;
        $grupoAfterClear['cron_job_org_job_id'] = '';
        $list2 = cronJobListarJobsConta(false);
        $jobs2 = (!empty($list2['success']) && is_array($list2['jobs'])) ? $list2['jobs'] : [];
        $b2 = cronJobOrgBuscarJobsPorTituloExato($jobs2, $titulo);
        if ($b2['primeiro_id'] !== null && $b2['primeiro_id'] !== '') {
            $jid2 = $b2['primeiro_id'];
            $res3 = cronJobOrgRequest('PATCH', '/jobs/' . $jid2, ['job' => $payloadJob], true);
            if ($res3['success']) {
                cronJobGrupoWhatsappPersistJobId($grupoId, $jid2);
                $r = [
                    'success' => true,
                    'message' => 'O ID guardado apontava para um job que já não existe na cron-job.org (HTTP ' . $patchHttp . '). O vínculo local foi limpo e reassociado ao job encontrado pelo título «' . $titulo . '».' . $urlOverrideWarn,
                    'job_id' => $jid2,
                    'skipped' => false,
                    'http_code' => (int) ($res3['http_code'] ?? 0),
                ];
                cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupoAfterClear, $r, 'stale404_repatch', true);

                return $out($r);
            }
        }
        $resPut = cronJobOrgRequest('PUT', '/jobs', ['job' => $payloadJob], true);
        if ($resPut['success']) {
            $id = cronJobOrgResolverJobIdAposPutCriacao($resPut['body'], $titulo);
            if ($id !== null) {
                cronJobGrupoWhatsappPersistJobId($grupoId, $id);
                $r = [
                    'success' => true,
                    'message' => 'Job antigo inválido (HTTP ' . $patchHttp . '). Foi criado um novo job na cron-job.org e o vínculo local atualizado.' . $urlOverrideWarn,
                    'job_id' => $id,
                    'skipped' => false,
                    'http_code' => (int) ($resPut['http_code'] ?? 0),
                ];
                cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupoAfterClear, $r, 'stale404_put_new', true);

                return $out($r);
            }

            $r = [
                'success' => true,
                'message' => 'Novo job aceito na cron-job.org após ID obsoleto (HTTP ' . $patchHttp . '), mas o ID numérico ainda não foi obtido. Sincronize de novo em breve.' . $urlOverrideWarn,
                'job_id' => null,
                'http_code' => (int) ($resPut['http_code'] ?? 0),
                'sync_partial_no_job_id' => true,
            ];
            cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupoAfterClear, $r, 'stale404_put_partial', true);

            return $out($r);
        }
    }

    $r = [
        'success' => false,
        'message' => (string) ($res['message'] ?? 'Falha ao sincronizar job do grupo.'),
        'job_id' => null,
        'http_code' => $patchHttp,
    ];
    cronJobGrupoWhatsappPersistSyncAudit($grupoId, $grupo, $r, 'patch_fail');

    return $out($r);
}

/**
 * Remove o job na cron-job.org do grupo (usa ID guardado ou o passado).
 */
function cronJobRemoverGrupoWhatsappNaOrg(int $grupoId, ?string $cronJobOrgJobId = null): void {
    if (!function_exists('getConfig')) {
        require_once dirname(__DIR__, 2) . '/config/database.php';
    }
    $jid = preg_replace('/\D/', '', (string) $cronJobOrgJobId);
    if ($jid === '') {
        try {
            if (!function_exists('getDB')) {
                require_once dirname(__DIR__, 2) . '/config/database.php';
            }
            cronJobGrupoWhatsappGarantirColunaId();
            $pdo = getDB();
            $st = $pdo->prepare('SELECT cron_job_org_job_id FROM grupos_whatsapp WHERE id = ? LIMIT 1');
            $st->execute([max(1, $grupoId)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $jid = preg_replace('/\D/', '', trim((string) ($row['cron_job_org_job_id'] ?? '')));
        } catch (Throwable $e) {
            return;
        }
    }
    if ($jid === '') {
        return;
    }
    if (trim((string) getConfig('cron_job_org_api_key', '')) !== '') {
        cronJobDelete($jid);
    }
}
