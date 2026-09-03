<?php
/**
 * Envio opcional via tabela dispatches (fallback entre contas Evolution por prioridade).
 * Requer enviarWhatsAppMensagem (automacao-ml.php) em tempo de execução das automações.
 */

if (!defined('DISPATCH_ENVIO_LOADED')) {
    define('DISPATCH_ENVIO_LOADED', true);
}

/**
 * Dispatches multi-conta (tabela `dispatches`): em desenvolvimento sempre ativos;
 * em produção só se `dispatch_ativo_producao` = 1 (Configurações → Telegram).
 * Sem isso, as automações usam apenas os grupos WhatsApp/Telegram “clássicos” da loja.
 */
function dispatch_habilitado(): bool {
    if (getConfig('dispatch_ativo_producao', '0') === '1') {
        return true;
    }

    return defined('APP_ENV') && APP_ENV === 'development';
}

function dispatch_envio_admin_id(): int {
    $id = (int) getConfig('dispatch_admin_id', '1');
    return max(1, $id);
}

function dispatch_whatsapp_tem_destinos(array $arvoreWhatsapp): bool {
    if (!dispatch_habilitado()) {
        return false;
    }
    return !empty($arvoreWhatsapp);
}

function dispatch_telegram_tem_destinos(array $arvoreTelegram): bool {
    if (!dispatch_habilitado()) {
        return false;
    }
    return !empty($arvoreTelegram);
}

function dispatch_log(string $msg): void {
    error_log('[dispatch] ' . $msg);
    if (isset($GLOBALS['dispatch_capture_logs']) && is_array($GLOBALS['dispatch_capture_logs'])) {
        $GLOBALS['dispatch_capture_logs'][] = '[' . date('H:i:s') . '] ' . $msg;
    }
}

/**
 * Expande árvore canal → grupo_id → conta_id → linhas em lista de grupos com linhas ordenadas por prioridade, id.
 *
 * @return list<array{grupo_id: string, linhas: list<array<string, mixed>>}>
 */
function dispatch_expandir_linhas_por_grupo_prioridade(array $arvoreCanal): array {
    if (empty($arvoreCanal)) {
        return [];
    }
    ksort($arvoreCanal, SORT_STRING);
    $out = [];
    foreach ($arvoreCanal as $grupoId => $porConta) {
        if (!is_array($porConta)) {
            continue;
        }
        $linhas = [];
        foreach ($porConta as $lista) {
            if (!is_array($lista)) {
                continue;
            }
            foreach ($lista as $row) {
                if (is_array($row)) {
                    $linhas[] = $row;
                }
            }
        }
        usort($linhas, static function ($a, $b) {
            $pa = (int) ($a['prioridade'] ?? 0);
            $pb = (int) ($b['prioridade'] ?? 0);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $out[] = ['grupo_id' => (string) $grupoId, 'linhas' => $linhas];
    }
    return $out;
}

function dispatch_grupo_whatsapp_id_por_jid(string $jid): int {
    if ($jid === '') {
        return 0;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id FROM grupos_whatsapp WHERE grupo_id = ? LIMIT 1');
        $stmt->execute([$jid]);
        $r = $stmt->fetch(PDO::FETCH_COLUMN);
        return $r ? (int) $r : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * @return array{url_base: string, instancia: string, api_key: string, provedor?: string, uazapi_admin_token?: string}|null
 */
function dispatch_resolver_evolution_conta($contaId): ?array {
    $id = (int) $contaId;
    if ($id <= 0) {
        return null;
    }
    try {
        $pdo = getDB();
        static $sqlEv = null;
        if ($sqlEv === null) {
            try {
                $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
                $sqlEv = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token, COALESCE(api_propria, 0) AS api_propria FROM evolution_contas WHERE id = ? AND ativo = 1';
            } catch (Exception $e) {
                try {
                    $pdo->query('SELECT provedor FROM evolution_contas LIMIT 1');
                    $sqlEv = 'SELECT url_base, instancia, api_key, COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token FROM evolution_contas WHERE id = ? AND ativo = 1';
                } catch (Exception $e2) {
                    $sqlEv = 'SELECT url_base, instancia, api_key FROM evolution_contas WHERE id = ? AND ativo = 1';
                }
            }
        }
        $stmt = $pdo->prepare($sqlEv);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $out = [
            'url_base' => rtrim((string) $row['url_base'], '/'),
            'instancia' => (string) $row['instancia'],
            'api_key' => (string) $row['api_key'],
            'provedor' => $row['provedor'] ?? 'evolution',
            'uazapi_admin_token' => (string) ($row['uazapi_admin_token'] ?? ''),
            'api_propria' => (int) ($row['api_propria'] ?? 0),
        ];

        return $out;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * @param callable(int $idx, int $total): string $mensagemParaIndice
 * @param null|array{url_base: string, instancia: string, api_key: string} $evoFallbackStatus atualizado com primeira Evolution que enviou com sucesso
 * @param null|callable(string $grupoJid): void $aposSucessoPorGrupo opcional (ex.: cupons_enviados)
 * @param bool $ignorarIntervaloGrupos true = não aplicar grupoPodeReceberEnvio (execução forçada / teste)
 * @param null|callable(int $idx, int $total): ?string $imagemB64ParaIndice se definido, substitui $imgB64 por destino
 */
function dispatch_executar_whatsapp_destinos(
    array $arvoreWhatsapp,
    string $lojaPrefix,
    int $delay,
    string $imgB64,
    callable $mensagemParaIndice,
    array &$errosProduto,
    int &$enviados,
    &$evoFallbackStatus,
    ?callable $aposSucessoPorGrupo = null,
    bool $ignorarIntervaloGrupos = false,
    ?callable $imagemB64ParaIndice = null
): void {
    // #region agent log (sentinela temporária df3052)
    if (function_exists('achadinhos_agent_debug_sentinela')) {
        achadinhos_agent_debug_sentinela('dispatch_executar_whatsapp_destinos');
    }
    // #endregion
    if (!dispatch_habilitado()) {
        return;
    }
    if (!function_exists('enviarWhatsAppMensagem')) {
        dispatch_log('enviarWhatsAppMensagem indisponível; ignorando dispatch WhatsApp');
        return;
    }
    $destinos = dispatch_expandir_linhas_por_grupo_prioridade($arvoreWhatsapp);
    $total = count($destinos);
    // #region agent log
    $ag_skip_msg = 0;
    $ag_skip_janela = 0;
    $ag_skip_iv = 0;
    $ag_sent_ok = 0;
    $ag_dest_fail = 0;
    // #endregion
    foreach ($destinos as $idx => $dest) {
        $grupoJid = $dest['grupo_id'];
        $linhas = $dest['linhas'];
        $mensagem = trim((string) $mensagemParaIndice($idx, $total));
        if ($mensagem === '') {
            // #region agent log
            $ag_skip_msg++;
            // #endregion
            dispatch_log("pulado grupo {$grupoJid} (sem mensagem)");
            if ($idx < $total - 1) {
                sleep((int) $delay);
            }
            continue;
        }
        $imgUsar = $imgB64;
        if ($imagemB64ParaIndice !== null) {
            $imgAlt = $imagemB64ParaIndice($idx, $total);
            if ($imgAlt !== null && $imgAlt !== '') {
                $imgUsar = $imgAlt;
            }
        }
        $grupoIdDb = dispatch_grupo_whatsapp_id_por_jid($grupoJid);
        if (!$ignorarIntervaloGrupos && $grupoIdDb > 0 && function_exists('grupoEstaNaJanelaPostagem') && function_exists('grupo_whatsapp_horarios_postagem')) {
            $h = grupo_whatsapp_horarios_postagem($grupoIdDb);
            if (!grupoEstaNaJanelaPostagem($h['post_hora_inicio'] ?? null, $h['post_hora_fim'] ?? null)) {
                // #region agent log
                $ag_skip_janela++;
                // #endregion
                dispatch_log("pulado grupo {$grupoJid} (fora da janela de postagem)");
                if ($idx < $total - 1) {
                    sleep((int) $delay);
                }
                continue;
            }
        }
        if (!$ignorarIntervaloGrupos && $grupoIdDb > 0 && function_exists('grupoPodeReceberEnvio') && !grupoPodeReceberEnvio($grupoIdDb, $lojaPrefix, null, $delay)) {
            // #region agent log
            $ag_skip_iv++;
            // #endregion
            dispatch_log("pulado grupo {$grupoJid} (intervalo)");
            if ($idx < $total - 1) {
                sleep((int) $delay);
            }
            continue;
        }
        $sent = false;
        $errLast = '';
        foreach ($linhas as $row) {
            $cid = (string) ($row['conta_id'] ?? '');
            dispatch_log("tentando conta {$cid} grupo {$grupoJid}");
            $evo = dispatch_resolver_evolution_conta($cid);
            if (!$evo) {
                $errLast = 'Conta Evolution não encontrada ou inativa: ' . $cid;
                dispatch_log("falhou conta {$cid}: {$errLast}");
                continue;
            }
            $err = '';
            $ok = enviarWhatsAppMensagem($evo, $grupoJid, $mensagem, $imgUsar, $err);
            if ($ok) {
                // #region agent log
                $ag_sent_ok++;
                // #endregion
                dispatch_log("enviado com conta {$cid} grupo {$grupoJid}");
                $enviados++;
                if ($grupoIdDb > 0 && function_exists('registrarEnvioGrupo')) {
                    registrarEnvioGrupo($grupoIdDb, $lojaPrefix);
                }
                if ($evoFallbackStatus === null) {
                    $evoFallbackStatus = $evo;
                }
                if ($aposSucessoPorGrupo !== null) {
                    $aposSucessoPorGrupo($grupoJid);
                }
                $sent = true;
                break;
            }
            $errLast = $err;
            dispatch_log("falhou conta {$cid}: {$err}");
        }
        if (!$sent && $errLast !== '') {
            // #region agent log
            $ag_dest_fail++;
            // #endregion
            $errosProduto[] = 'WhatsApp dispatch grupo ' . $grupoJid . ': ' . $errLast;
        }
        if ($idx < $total - 1) {
            sleep((int) $delay);
        }
    }
    // #region agent log
    if (function_exists('achadinhos_agent_debug_ndjson')) {
        achadinhos_agent_debug_ndjson(
            'dispatch-envio.php:dispatch_executar_whatsapp_destinos',
            'resumo dispatch whatsapp',
            [
                'lojaPrefix' => $lojaPrefix,
                'dest_total' => $total,
                'skip_sem_mensagem' => $ag_skip_msg,
                'skip_janela' => $ag_skip_janela,
                'skip_intervalo' => $ag_skip_iv,
                'envios_ok_destino' => $ag_sent_ok,
                'destinos_com_falha_api' => $ag_dest_fail,
            ],
            'ML-A'
        );
    }
    // #endregion
}

/**
 * @param array &$errosAcumulado referência para mensagens de erro
 * @param string|null $imagemBase64 Opcional: upload de foto (ex.: mesmo base64 do fluxo WhatsApp).
 */
function dispatch_executar_telegram_destinos(array $arvoreTelegram, string $mensagem, ?string $imagemUrl, array &$errosAcumulado, ?string $imagemBase64 = null): void {
    if (!dispatch_habilitado()) {
        return;
    }
    if (getConfig('telegram_ativo', '0') !== '1') {
        return;
    }
    if (!function_exists('enviarTelegram')) {
        return;
    }
    $destinos = dispatch_expandir_linhas_por_grupo_prioridade($arvoreTelegram);
    foreach ($destinos as $dest) {
        $chatId = $dest['grupo_id'];
        $linhas = $dest['linhas'];
        $okChat = false;
        $errLast = '';
        foreach ($linhas as $row) {
            $cid = (string) ($row['conta_id'] ?? '');
            dispatch_log("telegram tentando conta {$cid} chat {$chatId}");
            $err = '';
            if (enviarTelegram($mensagem, $imagemUrl, $err, $chatId, $imagemBase64)) {
                dispatch_log("telegram enviado conta {$cid} chat {$chatId}");
                $okChat = true;
                break;
            }
            $errLast = $err;
            dispatch_log("telegram falhou conta {$cid}: {$err}");
        }
        if (!$okChat && !empty($linhas)) {
            $errosAcumulado[] = 'Telegram dispatch chat ' . $chatId . ': ' . ($errLast ?: 'falha');
        }
    }
}
