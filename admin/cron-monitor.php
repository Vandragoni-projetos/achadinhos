<?php
/**
 * Monitoramento de crons foi integrado em Configurações → Crons.
 * Mantido apenas para links antigos e favoritos.
 */
$q = [];
if (isset($_GET['force_refresh']) && (string) $_GET['force_refresh'] === '1') {
    $q['force_refresh'] = '1';
}
$dest = 'configuracoes.php?tab=crons' . ($q ? '&' . http_build_query($q) : '');
header('Location: ' . $dest, true, 302);
exit;
