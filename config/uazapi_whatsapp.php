<?php
/**
 * Cliente HTTP para UazAPI (https://uazapi.dev/) — query token/admintoken + cabeçalhos token/admintoken (Uazapi GO v2).
 * Documentação: https://docs.uazapi.com
 */

/**
 * @return array{token?: string, instance?: array, message?: string}
 */
function uazapiExtrairTokenRespostaInit(?array $json): array
{
    if (!is_array($json)) {
        return [];
    }
    $tok = $json['token'] ?? $json['instance']['token'] ?? $json['data']['token'] ?? null;
    if ($tok === null && isset($json['instance']) && is_array($json['instance'])) {
        $tok = $json['instance']['token'] ?? $json['instance']['instance_token'] ?? null;
    }
    return is_string($tok) && $tok !== '' ? ['token' => $tok] : [];
}

/**
 * Monta query string admintoken + token (instância).
 */
function uazapiQueryString(string $instanceToken, string $adminToken = ''): string
{
    $q = [];
    if (trim($instanceToken) !== '') {
        $q['token'] = $instanceToken;
    }
    if (trim($adminToken) !== '') {
        $q['admintoken'] = $adminToken;
    }
    return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Cabeçalhos de autenticação (a API também aceita query; enviar os dois cobre proxies e versões).
 *
 * @return list<string>
 */
function uazapiCurlAuthHeaders(string $instanceToken, string $adminToken): array
{
    $h = [];
    $it = trim($instanceToken);
    $at = trim($adminToken);
    if ($it !== '') {
        $h[] = 'token: ' . $it;
    }
    if ($at !== '') {
        $h[] = 'admintoken: ' . $at;
    }

    return $h;
}

/**
 * @return array{qr: string, pairing: ?string}
 */
function uazapiExtrairQrEPairing($j): array
{
    if (!is_array($j)) {
        return ['qr' => '', 'pairing' => null];
    }
    $pairing = $j['pairingCode'] ?? $j['pairing_code'] ?? $j['pairing'] ?? null;
    if (!is_string($pairing) || $pairing === '') {
        $pairing = null;
    }
    $tryNodes = [$j];
    foreach (['data', 'instance', 'result', 'response'] as $wrap) {
        if (isset($j[$wrap]) && is_array($j[$wrap])) {
            $tryNodes[] = $j[$wrap];
        }
    }
    $qr = '';
    $keys = ['qrcode', 'qr', 'base64', 'code', 'qrCode', 'qrcode_base64', 'qr_base64'];
    foreach ($tryNodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        foreach ($keys as $k) {
            if (!empty($node[$k]) && is_string($node[$k])) {
                $qr = $node[$k];
                break 2;
            }
        }
    }
    if ($pairing === null) {
        foreach ($tryNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $p = $node['pairingCode'] ?? $node['pairing_code'] ?? null;
            if (is_string($p) && $p !== '') {
                $pairing = $p;
                break;
            }
        }
    }
    if (is_string($qr) && strpos($qr, 'data:image') === 0) {
        // ok
    } elseif (is_string($qr) && $qr !== '' && strpos($qr, 'http') !== 0) {
        $qr = 'data:image/png;base64,' . $qr;
    }

    return ['qr' => is_string($qr) ? $qr : '', 'pairing' => $pairing];
}

/**
 * Resposta JSON com erro explícito (HTTP 200 com success=false).
 */
function uazapiJsonMensagemErro(?array $j): ?string
{
    if (!is_array($j)) {
        return null;
    }
    if (!empty($j['error']) && $j['error'] !== false) {
        return (string) ($j['message'] ?? $j['error'] ?? 'Erro Uazapi');
    }
    if (isset($j['success']) && $j['success'] === false) {
        return (string) ($j['message'] ?? $j['error'] ?? 'Erro Uazapi');
    }

    return null;
}

/**
 * Admin token gravado na conta ou, em Uazapi com API própria (api_propria=1), fallback da config global.
 *
 * @param array<string, mixed> $conta linha evolution_contas
 */
function uazapiResolverAdminToken(array $conta): string
{
    $t = trim((string) ($conta['uazapi_admin_token'] ?? ''));
    if ($t !== '') {
        return $t;
    }
    if (($conta['provedor'] ?? '') === 'uazapi' && !empty($conta['api_propria']) && function_exists('getConfig')) {
        return trim((string) getConfig('uazapi_admin_token_global', ''));
    }

    return '';
}

/**
 * @return array{code: int, body: string}
 */
function uazapiHttpRaw(string $method, string $url, string $body = '{}', string $instanceToken = '', string $adminToken = ''): array
{
    $m = strtoupper($method);
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if (in_array($m, ['POST', 'PUT', 'PATCH'], true)) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach (uazapiCurlAuthHeaders($instanceToken, $adminToken) as $ah) {
        $headers[] = $ah;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== '' && in_array($m, ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($res) ? $res : ''];
}

/**
 * POST /instance/disconnect — encerra sessão WhatsApp na instância.
 *
 * @return array{code: int, body: string}
 */
function uazapiInstanceDisconnect(string $urlBase, string $instanceToken, string $adminToken = ''): array
{
    $base = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        return ['code' => 0, 'body' => ''];
    }
    $url = $base . '/instance/disconnect?' . uazapiQueryString($instanceToken, trim($adminToken));

    return uazapiHttpRaw('POST', $url, '{}', $instanceToken, trim($adminToken));
}

/**
 * POST /instance/restart — reinicia runtime da instância (Uazapi GO v2).
 *
 * @return array{code: int, body: string}
 */
function uazapiInstanceRestartRuntime(string $urlBase, string $instanceToken, string $adminToken = ''): array
{
    $base = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        return ['code' => 0, 'body' => ''];
    }
    $url = $base . '/instance/restart?' . uazapiQueryString($instanceToken, trim($adminToken));
    $r = uazapiHttpRaw('POST', $url, '{}', $instanceToken, trim($adminToken));
    if ($r['code'] === 404) {
        $url2 = $base . '/instance/restartRuntime?' . uazapiQueryString($instanceToken, trim($adminToken));

        return uazapiHttpRaw('POST', $url2, '{}', $instanceToken, trim($adminToken));
    }

    return $r;
}

/**
 * DELETE /instance — remove a instância no servidor Uazapi.
 *
 * @return array{code: int, body: string}
 */
function uazapiInstanceDeleteNaApi(string $urlBase, string $instanceToken, string $adminToken = ''): array
{
    $base = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        return ['code' => 0, 'body' => ''];
    }
    $url = $base . '/instance?' . uazapiQueryString($instanceToken, trim($adminToken));

    return uazapiHttpRaw('DELETE', $url, '', $instanceToken, trim($adminToken));
}

/**
 * Normaliza destino: grupo JID mantém; número só dígitos.
 */
function uazapiNormalizarDestino(string $number): string
{
    $number = trim($number);
    if ($number === '') {
        return '';
    }
    if (strpos($number, '@g.us') !== false || strpos($number, '@s.whatsapp.net') !== false) {
        return $number;
    }
    $digits = preg_replace('/\D/', '', $number);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) >= 10 && strlen($digits) <= 11 && substr($digits, 0, 2) !== '55') {
        $digits = '55' . $digits;
    }

    return $digits;
}

/**
 * status/state da Uazapi podem vir como objeto/array no JSON; (string) array vira "array" em PHP.
 */
function uazapiNormalizeStateToString($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_string($v)) {
        return trim($v);
    }
    if (is_bool($v)) {
        return $v ? 'open' : 'close';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    if (!is_array($v)) {
        return '';
    }
    foreach (['status', 'state', 'name', 'text', 'label', 'connectionStatus', 'connection_state', 'msg'] as $k) {
        if (array_key_exists($k, $v)) {
            $inner = uazapiNormalizeStateToString($v[$k]);
            if ($inner !== '') {
                return $inner;
            }
        }
    }
    if (array_key_exists(0, $v)) {
        $inner = uazapiNormalizeStateToString($v[0]);
        if ($inner !== '') {
            return $inner;
        }
    }

    return '';
}

/**
 * GET /instance/status
 *
 * @return array{ok: bool, connected: bool, state: string}
 */
function uazapiObterEstadoInstancia(string $urlBase, string $instanceToken, string $adminToken = ''): array
{
    $out = ['ok' => false, 'connected' => false, 'state' => ''];
    $urlBase = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($urlBase === '' || $instanceToken === '') {
        return $out;
    }
    $url = $urlBase . '/instance/status?' . uazapiQueryString($instanceToken, trim($adminToken));
    $hdr = array_merge(['Accept: application/json'], uazapiCurlAuthHeaders($instanceToken, trim($adminToken)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) {
        return $out;
    }
    $j = json_decode($raw, true);
    $out['ok'] = true;
    $state = '';
    if (is_array($j)) {
        $inst = isset($j['instance']) && is_array($j['instance']) ? $j['instance'] : [];
        foreach ([$j, $inst] as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (!empty($node['loggedIn']) || !empty($node['logged_in']) || !empty($node['isLoggedIn'])) {
                $state = 'open';
                break;
            }
        }
        if ($state === '') {
            foreach ([$j, $inst] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                foreach (['status', 'state', 'connectionStatus', 'connection_state'] as $key) {
                    if (!array_key_exists($key, $node)) {
                        continue;
                    }
                    $cand = uazapiNormalizeStateToString($node[$key]);
                    if ($cand !== '') {
                        $state = $cand;
                        break 2;
                    }
                }
            }
        }
        if ($state === 'array') {
            $state = '';
        }
        if ($state === '' && isset($j['connected'])) {
            $state = !empty($j['connected']) ? 'open' : 'close';
        }
        if ($state === '' && isset($inst['connected'])) {
            $state = !empty($inst['connected']) ? 'open' : 'close';
        }
    }
    $out['state'] = $state;
    $s = strtolower((string) $state);
    $neg = (strpos($s, 'disconnect') !== false || strpos($s, 'close') !== false || strpos($s, 'offline') !== false || strpos($s, 'logout') !== false);
    $out['connected'] = !$neg && (
        $state === '1' || $state === 'true'
        || strpos($s, 'open') !== false || strpos($s, 'online') !== false || strpos($s, 'logged') !== false
        || strpos($s, 'ready') !== false || strpos($s, 'authenticated') !== false
        || ($s !== '' && strpos($s, 'connected') !== false && strpos($s, 'disconnected') === false)
    );

    return $out;
}

/**
 * POST /instance/init — cria instância (normalmente só admintoken na query).
 *
 * @return array{ok: bool, token?: string, raw?: string, message?: string}
 */
function uazapiInstanceInit(string $urlBase, string $adminToken, string $instanceName, &$err = ''): array
{
    $err = '';
    $urlBase = rtrim(trim($urlBase), '/');
    $adminToken = trim($adminToken);
    if ($urlBase === '' || $adminToken === '') {
        $err = 'URL base e token de administrador são obrigatórios para criar instância na Uazapi.';

        return ['ok' => false];
    }
    $qs = uazapiQueryString('', $adminToken);
    $url = $urlBase . '/instance/init?' . $qs;
    $body = json_encode(['name' => $instanceName], JSON_UNESCAPED_UNICODE);
    $hdr = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders('', $adminToken)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'Uazapi init HTTP ' . $code;
        if (is_string($raw) && $raw !== '') {
            $err .= ': ' . mb_substr($raw, 0, 500);
        }

        return ['ok' => false, 'raw' => (string) $raw];
    }
    $j = json_decode((string) $raw, true);
    $jsonErr = uazapiJsonMensagemErro(is_array($j) ? $j : null);
    if ($jsonErr !== null) {
        $err = $jsonErr;

        return ['ok' => false, 'raw' => (string) $raw];
    }
    $ext = uazapiExtrairTokenRespostaInit(is_array($j) ? $j : null);
    if (!isset($ext['token'])) {
        $err = 'Resposta da Uazapi sem token de instância. Verifique o token de administrador e a URL da API.';

        return ['ok' => false, 'raw' => (string) $raw];
    }

    return ['ok' => true, 'token' => $ext['token'], 'raw' => (string) $raw];
}

/**
 * POST /instance/connect — QR code
 *
 * @return array{ok: bool, qr?: string, pairingCode?: string, message?: string}
 */
function uazapiInstanceConnect(string $urlBase, string $instanceToken, string $adminToken = '', ?string $phone = null): array
{
    $urlBase = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    $adminToken = trim($adminToken);
    if ($urlBase === '' || $instanceToken === '') {
        return ['ok' => false, 'message' => 'URL ou token da instância vazio'];
    }
    $url = $urlBase . '/instance/connect?' . uazapiQueryString($instanceToken, $adminToken);
    $payload = [];
    if ($phone !== null && trim($phone) !== '') {
        $payload['phone'] = preg_replace('/\D/', '', $phone);
    }
    $postBody = $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE);
    $hdr = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders($instanceToken, $adminToken)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $snippet = is_string($raw) ? mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 280) : '';

        return ['ok' => false, 'message' => 'HTTP ' . $code . ($snippet !== '' ? (' — ' . $snippet) : '')];
    }
    $j = json_decode((string) $raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'message' => 'JSON inválido na resposta do /instance/connect'];
    }
    $jsonErr = uazapiJsonMensagemErro($j);
    if ($jsonErr !== null) {
        return ['ok' => false, 'message' => $jsonErr];
    }
    $ext = uazapiExtrairQrEPairing($j);
    $qr = $ext['qr'];
    $pairing = $ext['pairing'];
    if ($qr === '' && ($pairing === null || $pairing === '')) {
        $stUrl = $urlBase . '/instance/status?' . uazapiQueryString($instanceToken, $adminToken);
        $hdrSt = array_merge(['Accept: application/json'], uazapiCurlAuthHeaders($instanceToken, $adminToken));
        $ch2 = curl_init($stUrl);
        curl_setopt_array($ch2, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $hdrSt,
        ]);
        $raw2 = curl_exec($ch2);
        $code2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($code2 === 200 && is_string($raw2)) {
            $j2 = json_decode($raw2, true);
            if (is_array($j2)) {
                $ext2 = uazapiExtrairQrEPairing($j2);
                if ($ext2['qr'] !== '') {
                    $qr = $ext2['qr'];
                }
                if (($pairing === null || $pairing === '') && $ext2['pairing'] !== null && $ext2['pairing'] !== '') {
                    $pairing = $ext2['pairing'];
                }
            }
        }
    }

    return [
        'ok' => true,
        'qr' => $qr,
        'pairingCode' => $pairing,
    ];
}

/**
 * Envia texto ou mídia (base64) via Uazapi.
 *
 * @param string|null $mediaBase64 base64 puro ou data URI
 */
function enviarWhatsAppUazapi(string $baseUrl, string $instanceToken, string $adminToken, string $number, string $caption, $mediaBase64, &$err): bool
{
    $err = '';
    $base = rtrim(trim($baseUrl), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        $err = 'Uazapi: URL base ou token da instância vazio';

        return false;
    }
    $to = uazapiNormalizarDestino($number);
    if ($to === '') {
        $err = 'Destino inválido';

        return false;
    }
    $qs = uazapiQueryString($instanceToken, trim($adminToken));
    $headers = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders($instanceToken, trim($adminToken))
    );

    if (!empty($mediaBase64)) {
        $file = trim((string) $mediaBase64);
        if (strpos($file, 'data:') !== 0) {
            $file = 'data:image/jpeg;base64,' . $file;
        }
        $captionTrim = trim((string) $caption);
        $urlMedia = $base . '/send/media?' . $qs;
        $baseMedia = [
            'number' => $to,
            'type' => 'image',
            'file' => $file,
        ];

        $postMedia = static function (array $body) use ($urlMedia, $headers): array {
            $ch = curl_init($urlMedia);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 45,
            ]);
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $j = @json_decode((string) $res, true);
            $ok = $code >= 200 && $code < 300
                && !(is_array($j) && (!empty($j['error']) || (isset($j['success']) && $j['success'] === false)));

            return [$ok, $code, (string) $res, $j];
        };

        // Igual à Evolution: uma requisição sendMedia com legenda no mesmo JSON (caption / text / message).
        $tentativas = [];
        if ($captionTrim === '') {
            $tentativas[] = $baseMedia;
        } else {
            $tentativas[] = array_merge($baseMedia, ['caption' => $captionTrim]);
            $tentativas[] = array_merge($baseMedia, ['text' => $captionTrim]);
            $tentativas[] = array_merge($baseMedia, ['message' => $captionTrim]);
        }

        $ok = false;
        $code = 0;
        $res = '';
        $j = null;
        foreach ($tentativas as $bodyTry) {
            [$ok, $code, $res, $j] = $postMedia($bodyTry);
            if ($ok) {
                break;
            }
        }

        if (!$ok) {
            $err = 'Uazapi HTTP ' . $code;
            if ($res !== '') {
                if (is_array($j)) {
                    $err .= ': ' . (string) ($j['message'] ?? $j['error'] ?? $j['msg'] ?? mb_substr($res, 0, 200));
                } else {
                    $err .= ': ' . mb_substr($res, 0, 200);
                }
            }
            if (function_exists('achadinhos_agent_debug_ndjson')) {
                achadinhos_agent_debug_ndjson(
                    'uazapi_whatsapp.php:enviarWhatsAppUazapi',
                    'mídia',
                    [
                        'provider' => 'uazapi',
                        'endpoint' => '/send/media',
                        'caption_len' => strlen($captionTrim),
                        'media_ok' => false,
                    ],
                    'WA-CAP'
                );
            }

            return false;
        }

        if (function_exists('achadinhos_agent_debug_ndjson')) {
            achadinhos_agent_debug_ndjson(
                'uazapi_whatsapp.php:enviarWhatsAppUazapi',
                'mídia com legenda (um POST)',
                [
                    'provider' => 'uazapi',
                    'endpoint' => '/send/media',
                    'caption_len' => strlen($captionTrim),
                    'media_ok' => true,
                ],
                'WA-CAP'
            );
        }

        return true;
    }

    $url = $base . '/send/text?' . $qs;
    $body = ['number' => $to, 'text' => $caption];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'Uazapi HTTP ' . $code;
        if (is_string($res) && $res !== '') {
            $j = json_decode($res, true);
            if (is_array($j)) {
                $err .= ': ' . (string) ($j['message'] ?? $j['error'] ?? $j['msg'] ?? mb_substr($res, 0, 200));
            } else {
                $err .= ': ' . mb_substr($res, 0, 200);
            }
        }

        return false;
    }
    $j = @json_decode((string) $res, true);
    if (is_array($j) && (!empty($j['error']) || (isset($j['success']) && $j['success'] === false))) {
        $err = (string) ($j['message'] ?? $j['error'] ?? 'Erro Uazapi');

        return false;
    }

    return true;
}

/**
 * Publica Status (Stories) na Uazapi — tenta rotas/formatos comuns.
 *
 * @param string|null $imagemUrl URL http(s) da imagem
 * @return bool true se a API respondeu sucesso em alguma tentativa
 */
function enviarWhatsAppUazapiStatus(string $baseUrl, string $instanceToken, string $adminToken, string $mensagem, $imagemUrl, &$err): bool
{
    $err = '';
    $base = rtrim(trim($baseUrl), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        $err = 'Uazapi status: URL ou token vazio';

        return false;
    }
    $qs = uazapiQueryString($instanceToken, trim($adminToken));
    $headers = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders($instanceToken, trim($adminToken))
    );
    $msg = mb_substr(trim($mensagem), 0, 650);
    if ($msg === '') {
        $msg = 'Oferta';
    }
    $hasUrl = is_string($imagemUrl) && preg_match('#^https?://#i', $imagemUrl);

    $tentativas = [];
    if ($hasUrl) {
        $u = trim((string) $imagemUrl);
        $tentativas[] = ['/send/status', ['type' => 'image', 'url' => $u, 'caption' => $msg, 'text' => $msg]];
        $tentativas[] = ['/send/status', ['type' => 'image', 'file' => $u, 'caption' => $msg]];
        $tentativas[] = ['/send/story', ['type' => 'image', 'url' => $u, 'caption' => $msg]];
    }
    $tentativas[] = ['/send/status', ['type' => 'text', 'text' => $msg]];
    $tentativas[] = ['/send/status', ['type' => 'text', 'caption' => $msg]];

    $ultimoCode = 0;
    $ultimoTrecho = '';
    foreach ($tentativas as $pair) {
        $path = $pair[0];
        $body = $pair[1];
        $url = $base . $path . '?' . $qs;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ultimoCode = $code;
        if ($code === 404) {
            continue;
        }
        if ($code >= 200 && $code < 300) {
            $j = @json_decode((string) $res, true);
            $bad = is_array($j) && (!empty($j['error']) || (isset($j['success']) && $j['success'] === false));
            if (!$bad) {
                return true;
            }
            $ultimoTrecho = (string) ($j['message'] ?? $j['error'] ?? $j['msg'] ?? '');
        } else {
            $ultimoTrecho = is_string($res) ? mb_substr($res, 0, 200) : '';
        }
    }
    $err = 'Uazapi status HTTP ' . $ultimoCode . ($ultimoTrecho !== '' ? (': ' . $ultimoTrecho) : '');

    return false;
}

/**
 * Extrai array de chats da resposta POST /chat/find.
 *
 * @return list<array<string, mixed>>
 */
function uazapiExtrairListaChatsResposta($j): array
{
    if (!is_array($j)) {
        return [];
    }
    foreach (['chats', 'records', 'items', 'result', 'data'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) {
            $arr = $j[$k];
            if ($arr !== [] && array_keys($arr) === range(0, count($arr) - 1)) {
                return $arr;
            }
        }
    }
    if ($j !== [] && array_keys($j) === range(0, count($j) - 1)) {
        return $j;
    }

    return [];
}

/**
 * Lista todos os grupos (JID @g.us) retornados por POST /chat/find com paginação (offset).
 * Complementa /group/list quando a API não devolve todos os grupos em que o número participa.
 *
 * Duas passagens: (1) wa_isGroup=true — rápido; (2) sem wa_isGroup — captura comunidades / avisos que a API
 * marca como não-grupo mas mantêm JID @g.us (filtramos só @g.us no cliente).
 *
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function uazapiListarGruposDesdeChatFindPaginado(string $base, string $instanceToken, string $adminToken): array
{
    $base = rtrim(trim($base), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        return [];
    }
    $url = $base . '/chat/find?' . uazapiQueryString($instanceToken, trim($adminToken));
    $hdr = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders($instanceToken, trim($adminToken))
    );
    $limit = 500;
    $maxOffset = 200000;
    $seen = [];
    $out = [];

    $processarPagina = static function (array $list) use (&$seen, &$out): void {
        foreach ($list as $c) {
            if (!is_array($c)) {
                continue;
            }
            $jid = (string) ($c['wa_chatid'] ?? $c['wa_fastid'] ?? $c['chatid'] ?? $c['id'] ?? $c['jid'] ?? '');
            $jid = trim($jid);
            if ($jid !== '' && strpos($jid, '@') === false && preg_match('/^\d+$/', $jid)) {
                $jid .= '@g.us';
            }
            if ($jid === '' || strpos($jid, '@g.us') === false) {
                continue;
            }
            $lk = strtolower($jid);
            if (isset($seen[$lk])) {
                continue;
            }
            $seen[$lk] = true;
            $nome = trim((string) ($c['wa_name'] ?? $c['name'] ?? $c['wa_contactName'] ?? $c['subject'] ?? ''));
            $size = 0;
            if (isset($c['wa_participantCount'])) {
                $size = (int) $c['wa_participantCount'];
            } elseif (isset($c['participants']) && is_array($c['participants'])) {
                $size = count($c['participants']);
            }
            $destino = achadinhosWhatsappResolverDestinoPainelGrupo(uazapiAchatarJsonGrupoParaDeteccao($c));
            $out[] = [
                'id' => $jid,
                'subject' => $nome,
                'size' => $size,
                'destino' => $destino,
            ];
        }
    };

    foreach ([true, false] as $waIsGroupFilter) {
        $offset = 0;
        while ($offset <= $maxOffset) {
            $payload = [
                'operator' => 'AND',
                'sort' => '-wa_lastMsgTimestamp',
                'limit' => $limit,
                'offset' => $offset,
            ];
            if ($waIsGroupFilter) {
                $payload['wa_isGroup'] = true;
            }
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 55,
                CURLOPT_HTTPHEADER => $hdr,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300 || !is_string($raw)) {
                break;
            }
            $j = json_decode($raw, true);
            $list = uazapiExtrairListaChatsResposta($j);
            if ($list === []) {
                break;
            }
            $processarPagina($list);
            if (count($list) < $limit) {
                break;
            }
            $offset += $limit;
        }
    }

    return $out;
}

/**
 * Junta grupos vindos do chat/find cujo JID ainda não está na lista principal.
 *
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $principal
 * @param list<array{id: string, subject: string, size: int, destino: string}>   $desdeChat
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function uazapiUnirGruposChatFindFaltantes(array $principal, array $desdeChat): array
{
    $seen = [];
    foreach ($principal as $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k !== '') {
            $seen[$k] = true;
        }
    }
    $out = $principal;
    foreach ($desdeChat as $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k === '' || isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        if (!isset($g['destino']) || (string) $g['destino'] === '') {
            $g['destino'] = 'grupo';
        }
        $out[] = $g;
    }

    return $out;
}

/**
 * Mapa JID (minúsculo) => nome de exibição a partir de POST /chat/find (wa_isGroup), todas as páginas.
 *
 * @return array<string, string>
 */
function uazapiMapNomesGruposViaChatFind(string $base, string $instanceToken, string $adminToken): array
{
    $map = [];
    foreach (uazapiListarGruposDesdeChatFindPaginado($base, $instanceToken, $adminToken) as $row) {
        $lk = strtolower(trim((string) ($row['id'] ?? '')));
        $nome = trim((string) ($row['subject'] ?? ''));
        if ($lk !== '' && $nome !== '') {
            $map[$lk] = $nome;
        }
    }

    return $map;
}

/**
 * Extrai o nome do grupo a partir do JSON já retornado por POST /group/info.
 *
 * @param array<string, mixed>|null $j
 */
function uazapiGrupoInfoSubjectFromDecoded(?array $j): string
{
    if (!is_array($j)) {
        return '';
    }
    $nome = trim((string) ($j['subject'] ?? $j['name'] ?? $j['Subject'] ?? ''));
    if ($nome !== '') {
        return $nome;
    }
    if (isset($j['group']) && is_array($j['group'])) {
        $nome = trim((string) ($j['group']['subject'] ?? $j['group']['name'] ?? ''));
        if ($nome !== '') {
            return $nome;
        }
    }
    if (isset($j['data']) && is_array($j['data'])) {
        $nome = trim((string) ($j['data']['subject'] ?? $j['data']['name'] ?? ''));
        if ($nome !== '') {
            return $nome;
        }
    }

    return '';
}

/**
 * Nome do grupo via POST /group/info (fallback quando /group/list não traz subject).
 */
function uazapiGrupoInfoSubject(string $base, string $instanceToken, string $adminToken, string $groupjid): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '' || trim($instanceToken) === '' || trim($groupjid) === '') {
        return '';
    }
    $j = uazapiGroupInfoJson($base, $instanceToken, $adminToken, $groupjid);

    return uazapiGrupoInfoSubjectFromDecoded($j);
}

/**
 * POST /group/info — retorna JSON decodificado ou null.
 *
 * @return ?array<string, mixed>
 */
function uazapiGroupInfoJson(string $base, string $instanceToken, string $adminToken, string $groupjid): ?array
{
    $base = rtrim(trim($base), '/');
    if ($base === '' || trim($instanceToken) === '' || trim($groupjid) === '') {
        return null;
    }
    $url = $base . '/group/info?' . uazapiQueryString($instanceToken, trim($adminToken));
    $hdrGi = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        uazapiCurlAuthHeaders($instanceToken, trim($adminToken))
    );
    foreach ([['groupjid' => $groupjid], ['groupJid' => $groupjid]] as $payload) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 14,
            CURLOPT_HTTPHEADER => $hdrGi,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($raw)) {
            continue;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }

    return null;
}

/**
 * Tenta obter JID do usuário conectado a partir do JSON de GET /instance/status.
 */
function uazapiExtrairOwnerJidDoStatusJson($node, int $depth = 0): string
{
    if ($depth > 10 || $node === null) {
        return '';
    }
    if (is_string($node)) {
        $t = trim($node);
        if (preg_match('/\d+@[gs]\.whatsapp\.net$/i', $t)) {
            return $t;
        }

        return '';
    }
    if (!is_array($node)) {
        return '';
    }
    foreach (['owner', 'ownerJid', 'wid', 'userJid', 'jid', 'me', 'phoneJid', 'wuid'] as $k) {
        if (!isset($node[$k])) {
            continue;
        }
        $x = uazapiExtrairOwnerJidDoStatusJson($node[$k], $depth + 1);
        if ($x !== '') {
            return $x;
        }
    }
    foreach ($node as $v) {
        $x = uazapiExtrairOwnerJidDoStatusJson($v, $depth + 1);
        if ($x !== '') {
            return $x;
        }
    }

    return '';
}

/**
 * JID do número logado na instância (heurística sobre /instance/status).
 */
function uazapiObterOwnerJidInstancia(string $urlBase, string $instanceToken, string $adminToken): string
{
    $urlBase = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($urlBase === '' || $instanceToken === '') {
        return '';
    }
    $url = $urlBase . '/instance/status?' . uazapiQueryString($instanceToken, trim($adminToken));
    $hdr = array_merge(['Accept: application/json'], uazapiCurlAuthHeaders($instanceToken, trim($adminToken)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) {
        return '';
    }
    $j = json_decode($raw, true);

    return is_array($j) ? uazapiExtrairOwnerJidDoStatusJson($j) : '';
}

/**
 * Se o item de /group/list já informa se o membro é admin.
 *
 * @return ?bool null = não dá para saber só pelo item
 */
function uazapiItemListaIndicaAdminGrupo(array $item): ?bool
{
    foreach (['isAdmin', 'IsAdmin', 'is_admin', 'isParticipantAdmin', 'participating_as_admin', 'isGroupAdmin'] as $k) {
        if (!array_key_exists($k, $item)) {
            continue;
        }
        $v = $item[$k];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        $s = strtolower(trim((string) $v));
        if ($s === 'true' || $s === '1' || $s === 'admin' || $s === 'superadmin') {
            return true;
        }
        if ($s === 'false' || $s === '0' || $s === 'member' || $s === 'participant') {
            return false;
        }
    }
    $role = strtolower(trim((string) ($item['role'] ?? $item['Role'] ?? $item['memberRole'] ?? $item['MemberRole'] ?? '')));
    if ($role === 'admin' || $role === 'superadmin') {
        return true;
    }
    if ($role === 'member' || $role === 'participant' || $role === 'left') {
        return false;
    }

    return null;
}

/**
 * @return list<array{id: string, admin: mixed}>
 */
function uazapiExtrairLinhasParticipantesDeJson(?array $j): array
{
    if (!is_array($j)) {
        return [];
    }
    $lists = [];
    foreach (['participants', 'Participants', 'members', 'Members', 'groupParticipants', 'GroupParticipants'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) {
            $lists[] = $j[$k];
        }
    }
    foreach (['group', 'data', 'GroupMetadata', 'groupMetadata'] as $root) {
        if (!isset($j[$root]) || !is_array($j[$root])) {
            continue;
        }
        $sub = $j[$root];
        foreach (['participants', 'Participants', 'members'] as $k) {
            if (isset($sub[$k]) && is_array($sub[$k])) {
                $lists[] = $sub[$k];
            }
        }
    }
    $out = [];
    foreach ($lists as $plist) {
        foreach ($plist as $p) {
            if (is_string($p)) {
                $pid = trim($p);
                if ($pid !== '') {
                    $out[] = ['id' => $pid, 'admin' => null];
                }

                continue;
            }
            if (!is_array($p)) {
                continue;
            }
            $pid = trim((string) ($p['id'] ?? $p['jid'] ?? $p['JID'] ?? $p['user'] ?? ''));
            if ($pid === '' && isset($p['key']) && is_array($p['key'])) {
                $pid = trim((string) ($p['key']['remoteJid'] ?? $p['key']['participant'] ?? ''));
            }
            if ($pid === '') {
                continue;
            }
            $out[] = [
                'id' => $pid,
                'admin' => $p['admin'] ?? $p['isAdmin'] ?? $p['rank'] ?? $p['role'] ?? $p['Role'] ?? null,
                'phoneNumber' => $p['phoneNumber'] ?? $p['phone'] ?? $p['pn'] ?? null,
            ];
        }
    }

    return $out;
}

function uazapiParticipanteCampoEhAdmin($admin): bool
{
    if ($admin === null) {
        return false;
    }
    if (is_bool($admin)) {
        return $admin;
    }
    if (is_int($admin)) {
        return $admin === 1 || $admin === 2;
    }
    if (is_string($admin) && $admin !== '' && ctype_digit($admin)) {
        $i = (int) $admin;

        return $i === 1 || $i === 2;
    }
    $v = strtolower(trim((string) $admin));

    return $v !== '' && !in_array($v, ['false', '0', 'null', 'member', 'regular'], true)
        && ($v === 'admin' || $v === 'superadmin' || $v === 'true' || $v === '1' || $v === 'creator');
}

function uazapiJidsMesmoUsuario(string $jidA, string $jidB): bool
{
    $a = strtolower(trim($jidA));
    $b = strtolower(trim($jidB));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $pa = strstr($a, '@', true) ?: $a;
    $pb = strstr($b, '@', true) ?: $b;
    $da = preg_replace('/\D/', '', $pa);
    $db = preg_replace('/\D/', '', $pb);

    return $da !== '' && $da === $db;
}

/**
 * @param array{id?: string, admin?: mixed, phoneNumber?: mixed, phone?: mixed, pn?: mixed} $row
 */
function uazapiParticipanteRowCorrespondeOwner(array $row, string $ownerJid): bool
{
    $ownerJid = trim($ownerJid);
    if ($ownerJid === '') {
        return false;
    }
    $pid = trim((string) ($row['id'] ?? ''));
    if ($pid !== '' && uazapiJidsMesmoUsuario($pid, $ownerJid)) {
        return true;
    }
    $digitsOwner = preg_replace('/\D/', '', strstr($ownerJid, '@', true) ?: $ownerJid);
    if ($digitsOwner === '') {
        return false;
    }
    foreach (['phoneNumber', 'phone', 'pn', 'user', 'wid', 'notify'] as $k) {
        if (empty($row[$k]) && $row[$k] !== 0 && $row[$k] !== '0') {
            continue;
        }
        $d = preg_replace('/\D/', '', (string) $row[$k]);
        if ($d !== '' && $d === $digitsOwner) {
            return true;
        }
    }

    return false;
}

function uazapiItemIndicaUsuarioEhDonoDoGrupo(array $item, string $ownerJid): bool
{
    $ownerJid = trim($ownerJid);
    if ($ownerJid === '') {
        return false;
    }
    $gm = isset($item['groupMetadata']) && is_array($item['groupMetadata']) ? $item['groupMetadata'] : null;
    foreach (['owner', 'Owner', 'descOwner', 'groupOwner', 'ownerJid'] as $k) {
        $ow = trim((string) ($item[$k] ?? ($gm !== null ? ($gm[$k] ?? '') : '')));
        if ($ow !== '' && uazapiJidsMesmoUsuario($ow, $ownerJid)) {
            return true;
        }
    }

    return false;
}

/**
 * Indica se ownerJid é admin no JSON de group/info (ou estrutura parecida).
 */
function uazapiJsonUsuarioEhAdminNoGrupo(?array $j, string $ownerJid): bool
{
    $ownerJid = trim($ownerJid);
    if ($ownerJid === '') {
        return false;
    }
    foreach (uazapiExtrairLinhasParticipantesDeJson($j) as $row) {
        if (!uazapiParticipanteRowCorrespondeOwner($row, $ownerJid)) {
            continue;
        }
        if (uazapiParticipanteCampoEhAdmin($row['admin'] ?? null)) {
            return true;
        }
    }

    return false;
}

/**
 * Achata JSON de grupo da Uazapi (list/info) para detecção de comunidade — metadados vêm em group/data/response.
 *
 * @param array<string, mixed>|null $j
 * @return array<string, mixed>
 */
function uazapiAchatarJsonGrupoParaDeteccao(?array $j): array
{
    if (!is_array($j)) {
        return [];
    }
    $acc = $j;
    $mergeLevel = static function (array $from) use (&$acc): void {
        foreach ($from as $k => $v) {
            if (!array_key_exists($k, $acc) || $acc[$k] === null || $acc[$k] === '') {
                $acc[$k] = $v;
            }
        }
    };
    foreach (['group', 'Group', 'data', 'Data', 'response', 'result', 'chat', 'Chat'] as $wrap) {
        if (empty($j[$wrap]) || !is_array($j[$wrap])) {
            continue;
        }
        $mergeLevel($j[$wrap]);
        foreach (['group', 'Group', 'metadata', 'groupMetadata', 'group_metadata'] as $inner) {
            if (!empty($j[$wrap][$inner]) && is_array($j[$wrap][$inner])) {
                $mergeLevel($j[$wrap][$inner]);
            }
        }
    }

    return $acc;
}

/**
 * Lista grupos da instância (GET /group/list).
 * Normaliza para o mesmo formato usado na UI da Evolution: id (JID), subject (nome), size.
 * Enriquece nomes com POST /chat/find e, se ainda faltar, POST /group/info (limite).
 *
 * @return array{ok: bool, grupos: list<array{id: string, subject: string, size: int, destino?: string}>, message?: string}
 */
function uazapiListarGrupos(string $urlBase, string $instanceToken, string $adminToken = '', bool $adminOnly = false): array
{
    $out = ['ok' => false, 'grupos' => []];
    $base = rtrim(trim($urlBase), '/');
    $instanceToken = trim($instanceToken);
    if ($base === '' || $instanceToken === '') {
        $out['message'] = 'URL base ou token da instância vazio.';

        return $out;
    }
    $qs = uazapiQueryString($instanceToken, trim($adminToken));
    $url = $base . '/group/list?' . $qs . '&force=1';
    $hdrGl = array_merge(['Accept: application/json'], uazapiCurlAuthHeaders($instanceToken, trim($adminToken)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_HTTPHEADER => $hdrGl,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $out['message'] = 'Uazapi HTTP ' . $code;
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $out['message'] .= ': ' . (string) ($j['message'] ?? $j['error'] ?? mb_substr($raw, 0, 200));
            } else {
                $out['message'] .= ': ' . mb_substr($raw, 0, 200);
            }
        }

        return $out;
    }
    $j = json_decode((string) $raw, true);
    $lista = [];
    if (is_array($j)) {
        if (isset($j['groups']) && is_array($j['groups'])) {
            $lista = $j['groups'];
        } elseif (isset($j['data']) && is_array($j['data'])) {
            $lista = $j['data'];
        } elseif ($j !== [] && array_keys($j) === range(0, count($j) - 1)) {
            $lista = $j;
        }
    }

    $ownerJid = '';
    if ($adminOnly) {
        $ownerJid = uazapiObterOwnerJidInstancia($base, $instanceToken, trim($adminToken));
        if ($ownerJid === '') {
            $out['message'] = 'Não foi possível identificar o JID do número conectado no Uazapi (GET /instance/status).';

            return $out;
        }
    }

    $grupos = [];
    $pendentesAdmin = [];
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }
        $jid = (string) ($item['id'] ?? $item['jid'] ?? $item['JID'] ?? $item['groupJid'] ?? $item['groupjid'] ?? '');
        if ($jid === '' && isset($item['key']) && is_array($item['key'])) {
            $jid = (string) ($item['key']['remoteJid'] ?? $item['key']['id'] ?? '');
        }
        $jid = trim($jid);
        if ($jid !== '' && strpos($jid, '@') === false && preg_match('/^\d+$/', $jid)) {
            $jid .= '@g.us';
        }
        if ($jid === '' || strpos($jid, '@g.us') === false) {
            continue;
        }
        $nome = trim((string) ($item['subject'] ?? $item['name'] ?? $item['Nome'] ?? $item['Subject'] ?? $item['wa_name'] ?? $item['title'] ?? ''));
        $size = 0;
        if (isset($item['size'])) {
            $size = (int) $item['size'];
        } elseif (isset($item['participants']) && is_array($item['participants'])) {
            $size = count($item['participants']);
        } elseif (isset($item['participantsCount'])) {
            $size = (int) $item['participantsCount'];
        }
        $destino = achadinhosWhatsappResolverDestinoPainelGrupo(uazapiAchatarJsonGrupoParaDeteccao($item));
        $row = ['id' => $jid, 'subject' => $nome, 'size' => $size, 'destino' => $destino];

        if ($adminOnly) {
            if (uazapiItemIndicaUsuarioEhDonoDoGrupo($item, $ownerJid)) {
                $grupos[] = $row;

                continue;
            }
            $flagLista = uazapiItemListaIndicaAdminGrupo($item);
            if ($flagLista === true) {
                $grupos[] = $row;

                continue;
            }
            if ($flagLista === false) {
                $pendentesAdmin[] = $row;

                continue;
            }
            if (uazapiJsonUsuarioEhAdminNoGrupo($item, $ownerJid)) {
                $grupos[] = $row;

                continue;
            }
            if (uazapiExtrairLinhasParticipantesDeJson($item) !== []) {
                continue;
            }
            $pendentesAdmin[] = $row;

            continue;
        }

        $grupos[] = $row;
    }

    if ($adminOnly && $pendentesAdmin !== []) {
        $maxInfo = min(2500, count($pendentesAdmin));
        for ($pi = 0; $pi < $maxInfo; $pi++) {
            $pr = $pendentesAdmin[$pi];
            $ji = uazapiGroupInfoJson($base, $instanceToken, trim($adminToken), $pr['id']);
            if (uazapiJsonUsuarioEhAdminNoGrupo($ji, $ownerJid)) {
                $grupos[] = $pr;
            }
        }
    }

    $mapChatNomesAdmin = [];
    if ($adminOnly && $ownerJid !== '') {
        $seenAdmin = [];
        foreach ($grupos as $ga) {
            $seenAdmin[strtolower(trim((string) ($ga['id'] ?? '')))] = true;
        }
        $fromChatAdmin = uazapiListarGruposDesdeChatFindPaginado($base, $instanceToken, trim($adminToken));
        $maxExtraInfo = 1000;
        $extraN = 0;
        foreach ($fromChatAdmin as $fc) {
            $lj = strtolower(trim((string) ($fc['id'] ?? '')));
            $subChat = trim((string) ($fc['subject'] ?? ''));
            if ($lj !== '' && $subChat !== '') {
                $mapChatNomesAdmin[$lj] = $subChat;
            }
            if ($lj === '' || isset($seenAdmin[$lj])) {
                continue;
            }
            if ($extraN >= $maxExtraInfo) {
                continue;
            }
            ++$extraN;
            $ji = uazapiGroupInfoJson($base, $instanceToken, trim($adminToken), (string) $fc['id']);
            if (uazapiJsonUsuarioEhAdminNoGrupo($ji, $ownerJid)) {
                $seenAdmin[$lj] = true;
                $grupos[] = $fc;
            }
        }
        $grupos = achadinhosWhatsappMesclarDestinoPreferirAvisosComunidade($grupos, $fromChatAdmin);
    }

    $mapChat = [];
    if (!$adminOnly) {
        $fromChat = uazapiListarGruposDesdeChatFindPaginado($base, $instanceToken, trim($adminToken));
        $grupos = uazapiUnirGruposChatFindFaltantes($grupos, $fromChat);
        $grupos = achadinhosWhatsappMesclarDestinoPreferirAvisosComunidade($grupos, $fromChat);
        foreach ($fromChat as $fc) {
            $lk = strtolower(trim((string) ($fc['id'] ?? '')));
            $nome = trim((string) ($fc['subject'] ?? ''));
            if ($lk !== '' && $nome !== '') {
                $mapChat[$lk] = $nome;
            }
        }
    } else {
        $mapChat = $mapChatNomesAdmin !== [] ? $mapChatNomesAdmin : uazapiMapNomesGruposViaChatFind($base, $instanceToken, $adminToken);
    }
    $infoCalls = 0;
    // Refinar nome e tipo (comunidade / avisos): /group/list costuma omitir flags; /group/info alinha à Evolution.
    $infoLim = $adminOnly ? 220 : 130;
    foreach ($grupos as $i => $g) {
        $lk = strtolower(trim((string) ($g['id'] ?? '')));
        if (trim((string) ($g['subject'] ?? '')) === '' && $lk !== '' && isset($mapChat[$lk])) {
            $grupos[$i]['subject'] = $mapChat[$lk];
        }
        $subOk = trim((string) ($grupos[$i]['subject'] ?? '')) !== '';
        $destCur = (string) ($grupos[$i]['destino'] ?? 'grupo');
        $precisaNome = !$subOk;
        $precisaComunidade = ($destCur === 'grupo');
        if ((!$precisaNome && !$precisaComunidade) || $infoCalls >= $infoLim) {
            continue;
        }
        ++$infoCalls;
        $ji = uazapiGroupInfoJson($base, $instanceToken, trim($adminToken), (string) ($g['id'] ?? ''));
        if (!is_array($ji)) {
            continue;
        }
        $sub = uazapiGrupoInfoSubjectFromDecoded($ji);
        if ($sub !== '') {
            $grupos[$i]['subject'] = $sub;
        }
        $dInfo = achadinhosWhatsappResolverDestinoPainelGrupo(uazapiAchatarJsonGrupoParaDeteccao($ji));
        $curD = (string) ($grupos[$i]['destino'] ?? 'grupo');
        if (achadinhosWhatsappDestinoPainelPrioridade($dInfo) > achadinhosWhatsappDestinoPainelPrioridade($curD)) {
            $grupos[$i]['destino'] = $dInfo;
        }
    }

    $out['ok'] = true;
    $out['grupos'] = $grupos;

    return $out;
}
