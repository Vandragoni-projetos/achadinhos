<?php

require_once __DIR__ . '/TelegramResolver.php';

/**
 * Envia para um chat (usa enviarTelegram global: token + override de chat_id).
 *
 * @param string|null $imagemUrl
 * @return bool
 */
function telegramEnviarMensagem($mensagem, $chatId, $imagemUrl = null)
{
    $err = '';
    return enviarTelegram($mensagem, $imagemUrl, $err, $chatId);
}

/**
 * Chats da loja + fallback no chat global (comportamento legado).
 *
 * @param array<int|string, string> $errosAcumulado
 * @param string|null $imagemUrl
 * @param string|null $imagemBase64 Base64 (ou data URI) da mesma imagem do WhatsApp; priorizado sobre URL.
 */
function telegramEnviarPorLoja($loja, $mensagem, array &$errosAcumulado, $imagemUrl = null, $imagemBase64 = null)
{
    if (getConfig('telegram_ativo', '0') !== '1' || !function_exists('enviarTelegram')) {
        return;
    }

    $lojaKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $loja));
    if ($lojaKey !== '' && getConfig($lojaKey . '_telegram_envio_ativo', '1') !== '1') {
        return;
    }

    $userId = telegramLojaOwnerUserId();

    $chatIds = resolverTelegramChatsPorLoja($loja, $userId);

    if (!empty($chatIds)) {
        foreach ($chatIds as $chatId) {
            $errTg = '';
            if (!enviarTelegram($mensagem, $imagemUrl, $errTg, $chatId, $imagemBase64) && $errTg !== '') {
                $errosAcumulado[] = 'Telegram: ' . $errTg;
            }
        }
        return;
    }

    $errTg = '';
    if (!enviarTelegram($mensagem, $imagemUrl, $errTg, null, $imagemBase64) && $errTg !== '') {
        $errosAcumulado[] = 'Telegram: ' . $errTg;
    }
}

/**
 * Baixa bytes de uma URL de imagem HTTP(S).
 */
function telegramBaixarImagemUrl(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'AchadinhosCron/1.0',
    ]);
    $b = curl_exec($ch);
    curl_close($ch);

    return ($b !== false && strlen((string) $b) > 200) ? (string) $b : null;
}

/**
 * Redimensiona para 1080×1920 (letterbox) em JPEG. Requer GD.
 */
function telegramStoryPrepararFoto1080x1920(string $imageBytes): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($imageBytes);
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);

        return null;
    }
    $tw = 1080;
    $th = 1920;
    $dst = imagecreatetruecolor($tw, $th);
    if (!$dst) {
        imagedestroy($src);

        return null;
    }
    $bg = imagecolorallocate($dst, 24, 24, 26);
    imagefill($dst, 0, 0, $bg);
    $scale = min($tw / $w, $th / $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $ox = (int) (($tw - $nw) / 2);
    $oy = (int) (($th - $nh) / 2);
    imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    ob_start();
    imagejpeg($dst, null, 90);
    $out = ob_get_clean();
    imagedestroy($dst);

    return ($out !== false && $out !== '') ? $out : null;
}

/**
 * postStory (Bot API) — conta Telegram Business ligada ao bot.
 *
 * @return bool
 */
function telegramPostStoryBusinessConnection(string $businessConnectionId, string $jpegBinary, string $caption, &$err = '')
{
    $err = '';
    $token = trim((string) getConfig('telegram_bot_token', ''));
    if ($token === '') {
        $err = 'token do bot ausente';

        return false;
    }
    $bc = trim($businessConnectionId);
    if ($bc === '') {
        $err = 'business_connection_id ausente';

        return false;
    }
    if (strlen($jpegBinary) > 10 * 1024 * 1024) {
        $err = 'imagem maior que 10 MB';

        return false;
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'tgst');
    if ($tmp === false) {
        $err = 'temp file';

        return false;
    }
    file_put_contents($tmp, $jpegBinary);
    $attachName = 'storyph';
    $content = json_encode([
        'type' => 'photo',
        'photo' => 'attach://' . $attachName,
    ], JSON_UNESCAPED_SLASHES);

    $url = 'https://api.telegram.org/bot' . $token . '/postStory';
    $post = [
        'business_connection_id' => $bc,
        'content' => $content,
        'active_period' => '86400',
        'caption' => mb_substr($caption, 0, 2000),
    ];
    $post[$attachName] = new CURLFile($tmp, 'image/jpeg', 'story.jpg');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);

    if ($code !== 200) {
        $err = 'HTTP ' . $code;

        return false;
    }
    $j = @json_decode((string) $res, true);
    if (!$j || empty($j['ok'])) {
        $err = (string) ($j['description'] ?? 'postStory falhou');

        return false;
    }

    return true;
}

/**
 * Se a loja tiver Story ativo e houver Business connection global, publica story com a mesma mídia/texto do envio aos grupos.
 *
 * @param string|null $imagemUrl
 * @param array<int|string, string> $errosAcumulado
 */
function telegramStoryPorLoja(string $loja, string $mensagem, $imagemUrl, array &$errosAcumulado): void
{
    if (getConfig('telegram_ativo', '0') !== '1') {
        return;
    }
    $lojaKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $loja));
    if ($lojaKey === '' || getConfig($lojaKey . '_telegram_story_ativo', '0') !== '1') {
        return;
    }
    $bc = trim((string) getConfig('telegram_business_connection_id', ''));
    if ($bc === '') {
        $errosAcumulado[] = 'Telegram Story: defina o Business connection ID em Configurações → Telegram (conta Business ligada ao bot, permissão de stories).';

        return;
    }
    $raw = telegramBaixarImagemUrl($imagemUrl !== null ? (string) $imagemUrl : '');
    if ($raw === null || $raw === '') {
        $errosAcumulado[] = 'Telegram Story: é necessária URL de imagem do produto.';

        return;
    }
    $jpeg = telegramStoryPrepararFoto1080x1920($raw);
    if ($jpeg === null) {
        $jpeg = $raw;
    }
    $err = '';
    if (!telegramPostStoryBusinessConnection($bc, $jpeg, $mensagem, $err)) {
        $errosAcumulado[] = 'Telegram Story: ' . $err;
    }
}
