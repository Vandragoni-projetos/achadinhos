<?php
/**
 * Caixa “criar manualmente na cron-job.org” — mesmo modelo (título, URL curta, headers, GET, crontab, falhas).
 *
 * Defina antes do include uma destas formas:
 * - $cronManualVariantes: list<array{rotulo: string, titulo: string, url: string, iv: int, h1: int, h2: int}>
 * - ou variáveis únicas: $cronManualRotulo, $cronManualTitulo, $cronManualUrl, $cronManualIv, $cronManualH1, $cronManualH2
 */
if (!function_exists('cronPainelPreviewExemplo')) {
    require_once __DIR__ . '/../../core/cron/CronJobService.php';
}
if (!class_exists('CronPolicy', false)) {
    require_once __DIR__ . '/../../core/cron/CronPolicy.php';
}

if (!function_exists('cronManualCronjobOrgExprUsuario')) {
/**
 * @return string|null Expressão estilo crontab 5 campos (só janela simples h1<=h2 e iv<60)
 */
function cronManualCronjobOrgExprUsuario(int $iv, int $h1, int $h2): ?string {
    $iv = CronPolicy::normalizeInterval($iv);
    $h1 = max(0, min(23, $h1));
    $h2 = max(0, min(23, $h2));
    if ($iv >= 60) {
        return null;
    }
    if ($h1 > $h2) {
        return null;
    }

    return '*/' . $iv . ' ' . $h1 . '-' . $h2 . ' * * *';
}
}

if (!isset($cronManualVariantes) || !is_array($cronManualVariantes)) {
    $cronManualVariantes = [[
        'rotulo' => (string) ($cronManualRotulo ?? 'Job na cron-job.org'),
        'titulo' => (string) ($cronManualTitulo ?? 'cron-global'),
        'url' => (string) ($cronManualUrl ?? ''),
        'iv' => (int) ($cronManualIv ?? 5),
        'h1' => (int) ($cronManualH1 ?? 8),
        'h2' => (int) ($cronManualH2 ?? 22),
    ]];
}
?>
                <div class="rounded-lg border border-amber-200 bg-amber-50/90 p-4 text-sm text-gray-800 mb-6 shadow-sm">
                    <h3 class="font-semibold text-gray-900 mb-1">Criar manualmente na cron-job.org</h3>
                    <p class="text-xs text-gray-600 mb-4">Modelo <strong>recomendado</strong> (igual à sincronização automática do painel): URL do script com <code class="rounded bg-white px-1">?token=…</code> na query (o agendador envia o token de forma fiável). Método <strong>GET</strong>, agenda em crontab quando aplicável. Alternativa: URL sem query + cabeçalho <code class="rounded bg-white px-1">X-Cron-Token</code> — só se o serviço repassar headers ao seu servidor.</p>
                    <?php foreach ($cronManualVariantes as $idx => $v):
                        if (!is_array($v)) {
                            continue;
                        }
                        $rot = trim((string) ($v['rotulo'] ?? ''));
                        $ttl = trim((string) ($v['titulo'] ?? ''));
                        $u = trim((string) ($v['url'] ?? ''));
                        $iv = (int) ($v['iv'] ?? 5);
                        $h1 = (int) ($v['h1'] ?? 8);
                        $h2 = (int) ($v['h2'] ?? 22);
                        $exprSimples = cronManualCronjobOrgExprUsuario($iv, $h1, $h2);
                        $prev = cronPainelPreviewExemplo($iv, $h1, $h2);
                        $exprRef = (string) ($prev['expr'] ?? '');
                        ?>
                    <div class="<?php echo $idx > 0 ? 'mt-4 pt-4 border-t border-amber-200/70' : ''; ?>">
                        <?php if ($rot !== ''): ?>
                        <p class="text-xs font-semibold text-amber-900 uppercase tracking-wide mb-2"><?php echo htmlspecialchars($rot); ?></p>
                        <?php endif; ?>
                        <ol class="list-decimal list-inside space-y-2 text-xs text-gray-800">
                            <li><strong>Title</strong> (identificação): <code class="rounded bg-white px-1.5 py-0.5 text-gray-900 border border-amber-100"><?php echo htmlspecialchars($ttl !== '' ? $ttl : '—'); ?></code></li>
                            <li><strong>URL</strong>: método <strong>GET</strong>. <strong>Recomendado:</strong> <code class="rounded bg-white px-1">.../rodar-tudo.php?token=…</code> (global) ou <code class="rounded bg-white px-1">.../rodar-loja.php?loja=ml&amp;token=…</code> (loja). Opcional: headers <code class="rounded bg-white px-1">X-Cron-Token</code> / <code class="rounded bg-white px-1">X-Cron-Loja</code> se não usar query. <?php if ($u !== ''): ?>Exemplo com valores atuais:<br><code class="mt-1 block break-all rounded bg-white px-2 py-1.5 text-[11px] leading-snug border border-gray-200"><?php echo htmlspecialchars($u); ?></code><?php else: ?><span class="text-amber-800">Defina a URL pública do site para ver o exemplo.</span><?php endif; ?></li>
                            <li><strong>Habilitar</strong> o job (ativo) e desativar a opção que <strong>pausa o job após muitas falhas</strong> (wording no site: varia entre “desativar por falhas” / “disable after failures”).</li>
                            <li><strong>Fuso horário</strong> do agendamento: <code class="rounded bg-white px-1">America/Sao_Paulo</code> (alinhado ao servidor e à janela abaixo).</li>
                            <li><strong>Expressão crontab</strong> (5 campos: minuto · hora · dia do mês · mês · dia da semana), alinhada ao intervalo e às horas que configurou neste painel:
                                <?php if ($exprSimples !== null): ?>
                                <code class="mt-1 block break-all rounded bg-white px-2 py-1.5 text-[11px] font-mono border border-gray-200"><?php echo htmlspecialchars($exprSimples); ?></code>
                                <span class="text-gray-600">Significa: a cada <?php echo (int) $iv; ?> minutos, entre <?php echo (int) $h1; ?>h e <?php echo (int) $h2; ?>h (no fuso do job).</span>
                                <?php else: ?>
                                <span class="text-gray-700">Com intervalo ≥ 60 min ou janela noturna (hora fim &lt; hora início), o formato simples <code class="rounded bg-white px-1">*/N H1-H2 * * *</code> não cobre tudo. Use o modo avançado da cron-job.org ou o exemplo ilustrativo: <code class="block mt-1 break-all rounded bg-white px-2 py-1 text-[11px] font-mono border border-gray-200"><?php echo htmlspecialchars($exprRef); ?></code></span>
                                <?php endif; ?>
                            </li>
                        </ol>
                    </div>
                    <?php endforeach; ?>
                </div>
