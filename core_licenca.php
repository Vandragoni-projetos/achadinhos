<?php
/**
 * Núcleo de licença - NÃO DISTRIBUIR. Usado só pelo encode_licenca.php para gerar o loader.
 * Este arquivo é criptografado no loader; o cliente não deve recebê-lo.
 */
if (!defined('LP_ROOT')) { define('LP_ROOT', __DIR__); }
if (!defined('LICENCA_SECRET')) {
    define('LICENCA_SECRET', 'AfiliadosPRO_Gerador_2024_ChaveSecreta_AltereEmProducao');
}
if (!function_exists('lp_normalizar_dominio')) {
    function lp_normalizar_dominio($host) {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/:\d+$/', '', $host);
        if (in_array($host, ['127.0.0.1', '::1', '0.0.0.0'], true)) return 'localhost';
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        return $host;
    }
}
if (!function_exists('lp_base64url_decode')) {
    function lp_base64url_decode($data) {
        $data = (string) $data;
        $r = strlen($data) % 4;
        if ($r) { $data .= str_repeat('=', 4 - $r); }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
if (!function_exists('lp_verificar_chave_licenca')) {
    /**
     * Verifica se a chave é válida para o domínio atual.
     * @param string $chave Chave de licença
     * @param string $dominioAtual Domínio atual
     * @param bool $ignorarExpiracao Se true, ignora verificação de expiração (para licenças já ativadas)
     * @return bool
     */
    function lp_verificar_chave_licenca($chave, $dominioAtual, $ignorarExpiracao = false) {
        $chave = trim((string) $chave);
        if ($chave === '' || strpos($chave, '.') === false) return false;
        $dominioAtual = lp_normalizar_dominio($dominioAtual);
        $parts = explode('.', $chave, 2);
        $payloadB64 = $parts[0]; $signatureHex = $parts[1];
        if (strlen($signatureHex) !== 64 || !ctype_xdigit($signatureHex)) return false;
        $payload = lp_base64url_decode($payloadB64);
        if ($payload === false || strpos($payload, '|') === false) return false;
        $arr = explode('|', $payload, 2);
        $domain = lp_normalizar_dominio($arr[0]); $expiry = (int) $arr[1];
        if ($domain !== $dominioAtual) return false;
        // Só verifica expiração se não estiver ignorando (primeira ativação)
        if (!$ignorarExpiracao && $expiry < time()) return false;
        return hash_equals(hash_hmac('sha256', $payload, LICENCA_SECRET), $signatureHex);
    }
}
if (!function_exists('lp_assinatura_valida')) {
    function lp_assinatura_valida($chave) {
        $chave = trim((string) $chave);
        if ($chave === '' || strpos($chave, '.') === false) return false;
        $parts = explode('.', $chave, 2);
        $payloadB64 = $parts[0]; $signatureHex = $parts[1];
        if (strlen($signatureHex) !== 64 || !ctype_xdigit($signatureHex)) return false;
        $payload = lp_base64url_decode($payloadB64);
        if ($payload === false || strpos($payload, '|') === false) return false;
        return hash_equals(hash_hmac('sha256', $payload, LICENCA_SECRET), $signatureHex);
    }
}
if (!function_exists('lp_ap_is_json_request')) {
    function lp_ap_is_json_request() {
        $a = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $x = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $u = strtolower($_SERVER['REQUEST_URI'] ?? '');
        return (strpos($a, 'application/json') !== false || $x === 'xmlhttprequest' || strpos($u, '/api/') !== false);
    }
}
if (!function_exists('bloquearAfiliadosPRO')) {
    function bloquearAfiliadosPRO($mensagem, $formError = '', $asJson = false) {
        if (ob_get_level() > 0) { while (ob_get_level() > 0) { @ob_end_clean(); } }
        http_response_code(403);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'bloqueado', 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8');
        $e = $formError !== '' ? htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') : '';
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AfiliadosPRO</title>';
        echo '<style>*{margin:0;padding:0;}body{font-family:system-ui,sans-serif;background:#0b1020;color:#e5e7eb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}';
        echo '.c{max-width:520px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:32px;}';
        echo 'input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(34,211,238,.4);background:rgba(15,23,42,.6);color:#e5e7eb;}';
        echo 'button{padding:12px 20px;border-radius:10px;border:0;background:linear-gradient(135deg,#7c3aed,#22d3ee);color:#fff;font-weight:700;cursor:pointer;}';
        echo '.err{margin-top:14px;padding:12px;border-radius:10px;background:rgba(239,68,68,.2);color:#fecaca;}</style></head><body><div class="c">';
        echo '<p>' . $m . '</p><form method="POST"><label>Chave de licença</label><input name="lp_license_key" placeholder="Cole a chave para este domínio" required>';
        echo '<div style="margin-top:14px"><button type="submit">Ativar</button></div></form>';
        if ($e !== '') echo '<div class="err">' . $e . '</div>';
        echo '</div></body></html>';
        exit;
    }
}
if (!function_exists('validarLicencaAfiliadosPRO')) {
    function validarLicencaAfiliadosPRO() {
        // Se já está ativa, não validar novamente
        if (defined('LICENCA_ATIVA') && LICENCA_ATIVA) {
            return;
        }
        $storageDir = LP_ROOT . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir)) { @mkdir($storageDir, 0755, true); }
        $keyFile = $storageDir . DIRECTORY_SEPARATOR . 'licence.key';
        $licenca = is_file($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
        if (php_sapi_name() === 'cli') {
            if ($licenca === '' || strpos($licenca, '.') === false) { define('LICENCA_ATIVA', false); return; }
            // Para CLI, também ignora expiração após primeira ativação
            $parts = explode('.', $licenca, 2);
            $payload = lp_base64url_decode($parts[0]);
            if ($payload === false || strpos($payload, '|') === false) { define('LICENCA_ATIVA', false); return; }
            // Verifica apenas assinatura, não expiração (licença já foi ativada)
            if (!hash_equals(hash_hmac('sha256', $payload, LICENCA_SECRET), $parts[1])) { define('LICENCA_ATIVA', false); return; }
            define('LICENCA_ATIVA', true); return;
        }
        $dominio = lp_normalizar_dominio((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($dominio === '') { bloquearAfiliadosPRO('Domínio não identificado.', '', lp_ap_is_json_request()); return; }
        if (!lp_ap_is_json_request() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['lp_license_key'])) {
            $key = trim((string) $_POST['lp_license_key']);
            if ($key === '') { bloquearAfiliadosPRO('Chave obrigatória.', 'Informe a chave.'); return; }
            if (lp_verificar_chave_licenca($key, $dominio)) {
                // Salvar a chave e garantir que foi salva
                $saved = @file_put_contents($keyFile, $key);
                if ($saved === false) {
                    bloquearAfiliadosPRO('Erro ao salvar a licença. Verifique permissões da pasta storage.', 'Erro de permissão.');
                    return;
                }
                // Definir flag imediatamente após salvar
                define('LICENCA_ATIVA', true);
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
                exit;
            }
            bloquearAfiliadosPRO('Chave inválida ou expirada para este domínio.', 'Gere uma nova chave no gerador.');
            return;
        }
        $licenca = is_file($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
        if ($licenca === '') {
            // Não há licença salva - mostrar formulário apenas se não for requisição JSON
            bloquearAfiliadosPRO('Insira a chave gerada para este domínio.', '', lp_ap_is_json_request());
            return;
        }
        // Validar a licença salva (ignorando expiração - licença já foi ativada uma vez)
        if (lp_verificar_chave_licenca($licenca, $dominio, true)) {
            if (!defined('LICENCA_ATIVA')) define('LICENCA_ATIVA', true);
            return;
        }
        // Assinatura válida mas domínio variou (www, 127.0.0.1 vs localhost) = aceitar, nunca apagar
        if (lp_assinatura_valida($licenca)) {
            if (!defined('LICENCA_ATIVA')) define('LICENCA_ATIVA', true);
            return;
        }
        @unlink($keyFile);
        bloquearAfiliadosPRO('Licença inválida ou corrompida.', 'Gere uma nova chave.');
        return;
    }
}
validarLicencaAfiliadosPRO();
// Se chegou aqui e ainda não definiu, definir agora (fallback)
if (!defined('LICENCA_ATIVA')) {
    define('LICENCA_ATIVA', true);
}
