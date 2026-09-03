<?php
/**
 * Caixa de resultado + script fetch (como mercadolivre.php).
 * Defina antes: $lojaEnviarAgora = ['api' => 'api/shopee-enviar-agora.php', 'prefix' => 'shopee'];
 */
if (!isset($lojaEnviarAgora) || !is_array($lojaEnviarAgora)) {
    return;
}
$api = trim((string) ($lojaEnviarAgora['api'] ?? ''));
$prefix = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($lojaEnviarAgora['prefix'] ?? '')));
if ($api === '' || $prefix === '') {
    return;
}
$apiEsc = htmlspecialchars($api, ENT_QUOTES, 'UTF-8');
?>
            <div id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>EnviarAgoraResultado" class="mb-4 hidden rounded-lg border p-4 text-sm" role="status" aria-live="polite"></div>

        <script>
        (function () {
            var apiUrl = <?php echo json_encode($apiEsc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var prefix = <?php echo json_encode($prefix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var btn = document.getElementById(prefix + 'BtnEnviarAgora');
            var txt = document.getElementById(prefix + 'EnviarAgoraTexto');
            var spi = document.getElementById(prefix + 'EnviarAgoraSpinner');
            var box = document.getElementById(prefix + 'EnviarAgoraResultado');
            if (!btn || !box) return;
            var FETCH_MS = 360000;
            function escapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }
            btn.addEventListener('click', function () {
                var token = (document.body && document.body.getAttribute('data-admin-autosave-token')) || '';
                btn.disabled = true;
                if (txt) txt.textContent = 'Enviando (pode levar vários minutos)…';
                if (spi) spi.classList.remove('hidden');
                box.classList.add('hidden');
                box.innerHTML = '';
                var ac = new AbortController();
                var to = setTimeout(function () {
                    ac.abort();
                }, FETCH_MS);
                fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: ac.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Autosave-Token': token
                    },
                    body: JSON.stringify({ token: token })
                })
                    .then(function (r) {
                        return r.text().then(function (text) {
                            var data = null;
                            try {
                                data = text ? JSON.parse(text) : null;
                            } catch (e) {
                                data = null;
                            }
                            return { ok: r.ok, status: r.status, data: data, text: text };
                        });
                    })
                    .then(function (pack) {
                        clearTimeout(to);
                        btn.disabled = false;
                        if (txt) txt.textContent = 'Enviar agora';
                        if (spi) spi.classList.add('hidden');
                        box.classList.remove('hidden');
                        var d = pack.data;
                        if (!d || typeof d !== 'object') {
                            box.className = 'mb-4 rounded-lg border p-4 text-sm bg-red-50 text-red-800 border-red-200';
                            box.innerHTML =
                                '<p class="font-semibold">Erro</p><p class="mt-1">Resposta inválida (HTTP ' +
                                pack.status +
                                ').</p><pre class="mt-2 text-xs overflow-x-auto max-h-48 whitespace-pre-wrap">' +
                                escapeHtml((pack.text || '').slice(0, 8000)) +
                                '</pre>';
                            return;
                        }
                        var ok = !!d.success;
                        box.className =
                            'mb-4 rounded-lg border p-4 text-sm ' +
                            (ok ? 'bg-emerald-50 text-emerald-900 border-emerald-200' : 'bg-red-50 text-red-800 border-red-200');
                        var msg = d.message != null ? String(d.message) : ok ? 'Concluído.' : 'Falha.';
                        box.innerHTML =
                            '<p class="font-semibold">' +
                            (ok ? 'Sucesso' : 'Erro') +
                            '</p><p class="mt-1">' +
                            escapeHtml(msg) +
                            '</p>';
                        if (Array.isArray(d.errors) && d.errors.length > 0) {
                            box.innerHTML += '<ul class="mt-2 list-disc list-inside text-sm">';
                            d.errors.forEach(function (errItem) {
                                box.innerHTML += '<li>' + escapeHtml(String(errItem)) + '</li>';
                            });
                            box.innerHTML += '</ul>';
                        }
                        if (d.details != null && typeof d.details === 'object' && Object.keys(d.details).length > 0) {
                            box.innerHTML +=
                                '<pre class="mt-2 text-xs overflow-x-auto max-h-64 opacity-90 whitespace-pre-wrap">' +
                                escapeHtml(JSON.stringify(d.details, null, 2)) +
                                '</pre>';
                        }
                    })
                    .catch(function (e) {
                        clearTimeout(to);
                        btn.disabled = false;
                        if (txt) txt.textContent = 'Enviar agora';
                        if (spi) spi.classList.add('hidden');
                        box.classList.remove('hidden');
                        box.className = 'mb-4 rounded-lg border p-4 text-sm bg-red-50 text-red-800 border-red-200';
                        var em =
                            e && e.name === 'AbortError'
                                ? 'Tempo esgotado (~6 min). O servidor pode ainda estar processando; confira o site/grupos ou tente de novo.'
                                : e && e.message
                                  ? e.message
                                  : 'Falha na requisição.';
                        box.innerHTML =
                            '<p class="font-semibold">Erro</p><p class="mt-1">' + escapeHtml(em) + '</p>';
                    });
            });
        })();
        </script>
