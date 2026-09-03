<?php
/**
 * Autosave JSON das páginas de loja: whitelist de chaves e mesma semântica dos POSTs.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Normaliza valor do select «categoria fixa»: -2 (mais-vendidos), -1 (Todos / automático), id positivo.
 * Legado 0 ou vazio → -1.
 */
function lojaAutosaveNormalizarCategoriaFixa($raw): string {
    if ($raw === null || $raw === '') {
        return '-1';
    }
    $n = (int) $raw;
    if ($n === 0 || $n === -1) {
        return '-1';
    }
    if ($n === -2) {
        return '-2';
    }
    if ($n < 0) {
        return '-1';
    }

    return (string) $n;
}

/** @return array<string, list<string>> */
function lojaAutosaveWhitelistPorLoja(): array
{
    $comumCron = [
        'cron_painel_presente',
        'cron_individual_ativo',
        'cron_token',
        'cron_intervalo_minutos',
        'cron_hora_inicio',
        'cron_hora_fim',
        'cron_dias_remocao',
        'cron_job_id',
    ];
    $comumEvolution = [
        'whatsapp_status_ativo',
        'telegram_envio_ativo',
        'telegram_story_ativo',
    ];

    return [
        'ml' => array_merge([
            'ml_automacao_ativa',
            'ml_tag_afiliado',
            'ml_csrf_token',
            'ml_cookie',
            'ml_openai_model',
            'ml_openai_prompt',
            'ml_link_grupo_whatsapp',
            'ml_evolution_conta_id',
            'ml_grupos_ids',
            'ml_telegram_chat_ids',
            'ml_produtos_por_execucao',
            'ml_delay_entre_envios',
            'ml_dias_evitar_repetir',
            'ml_site_publicar',
            'ml_site_categoria_id',
            'ml_whatsapp_exigir_foto',
        ], $comumEvolution, $comumCron),
        'shopee' => array_merge([
            'shopee_automacao_ativa',
            'shopee_app_id',
            'shopee_secret',
            'shopee_openai_model',
            'shopee_openai_prompt',
            'shopee_evolution_conta_id',
            'shopee_grupos_ids',
            'shopee_telegram_chat_ids',
            'shopee_produtos_por_execucao',
            'shopee_delay_entre_envios',
            'shopee_dias_evitar_repetir',
            'shopee_site_publicar',
            'shopee_site_categoria_id',
        ], $comumEvolution, $comumCron),
        'magalu' => array_merge([
            'magalu_automacao_ativa',
            'magalu_loja_url',
            'magalu_loja_url_alternativa',
            'magalu_scraper_api_key',
            'magalu_openai_model',
            'magalu_openai_prompt',
            'magalu_evolution_conta_id',
            'magalu_grupos_ids',
            'magalu_telegram_chat_ids',
            'magalu_produtos_por_execucao',
            'magalu_delay_entre_envios',
            'magalu_dias_evitar_repetir',
            'magalu_site_publicar',
            'magalu_site_categoria_id',
        ], $comumEvolution, $comumCron),
        'amazon' => array_merge([
            'amazon_automacao_ativa',
            'amazon_access_key',
            'amazon_secret_key',
            'amazon_associate_tag',
            'amazon_region',
            'amazon_search_keywords',
            'amazon_openai_model',
            'amazon_openai_prompt',
            'amazon_telegram_chat_ids',
            'amazon_site_publicar',
            'amazon_site_categoria_id',
            'telegram_envio_ativo',
            'telegram_story_ativo',
        ], $comumCron),
        'shein' => array_merge([
            'shein_automacao_ativa',
            'shein_api_key',
            'shein_api_secret',
            'shein_openai_model',
            'shein_openai_prompt',
            'shein_evolution_conta_id',
            'shein_grupos_ids',
            'shein_telegram_chat_ids',
            'shein_produtos_por_execucao',
            'shein_delay_entre_envios',
            'shein_dias_evitar_repetir',
            'shein_site_publicar',
            'shein_site_categoria_id',
        ], $comumEvolution, $comumCron),
        'aliexpress' => array_merge([
            'aliexpress_automacao_ativa',
            'aliexpress_app_key',
            'aliexpress_app_secret',
            'aliexpress_openai_model',
            'aliexpress_openai_prompt',
            'aliexpress_evolution_conta_id',
            'aliexpress_grupos_ids',
            'aliexpress_telegram_chat_ids',
            'aliexpress_site_publicar',
            'aliexpress_site_categoria_id',
        ], $comumEvolution, $comumCron),
        'ml_cupons' => array_merge([
            'ml_cupons_cookie',
            'ml_cupons_csrf_token',
            'ml_api_client_id',
            'ml_api_client_secret',
            'ml_api_redirect_uri',
            'ml_api_access_token',
            'ml_api_refresh_token',
            'ml_api_user_id',
            'ml_cupons_automacao_ativa',
            'ml_cupons_evolution_conta_id',
            'ml_cupons_grupos',
            'ml_cupons_telegram_chat_ids',
            'ml_cupons_link_ativacao',
            'ml_cupons_delay_entre_envios',
            'ml_cupons_produtos_por_execucao',
            'ml_cupons_dias_evitar_repetir',
        ], $comumEvolution, $comumCron),
    ];
}

function lojaAutosaveLojaValida(string $loja): bool
{
    return isset(lojaAutosaveWhitelistPorLoja()[$loja]);
}

/** Chave de config da automação principal da loja (para padrão de cron ao «ativar»). */
function lojaAutosaveChaveAutomacaoAtiva(string $loja): ?string
{
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    $map = [
        'ml' => 'ml_automacao_ativa',
        'shopee' => 'shopee_automacao_ativa',
        'magalu' => 'magalu_automacao_ativa',
        'amazon' => 'amazon_automacao_ativa',
        'shein' => 'shein_automacao_ativa',
        'aliexpress' => 'aliexpress_automacao_ativa',
        'ml_cupons' => 'ml_cupons_automacao_ativa',
    ];

    return $map[$loja] ?? null;
}

/** @param mixed $v */
function lojaAutosaveStr($v): string
{
    if (is_array($v)) {
        return '';
    }
    return trim((string) $v);
}

/**
 * @param mixed $raw
 * @return list<int>
 */
function lojaAutosaveIntArray($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    return array_map('intval', $raw);
}

function lojaAutosaveEspelhoCronMlCupons(array $postLike): void
{
    if (!isset($postLike['cron_painel_presente'])) {
        return;
    }
    $__cronMlCupons = dadosCronLoja('ml_cupons');
    setConfig('ml_cupons_cron_token', $__cronMlCupons['token']);
    setConfig('ml_cupons_hora_inicio', (string) (int) $__cronMlCupons['hora_inicio']);
    setConfig('ml_cupons_hora_fim', (string) (int) $__cronMlCupons['hora_fim']);
    setConfig('ml_cupons_intervalo_minutos', (string) (int) $__cronMlCupons['intervalo_minutos']);
}

/**
 * @param array<string, mixed> $patch
 * @return array{ok: bool, error?: string, cron_extra?: string}
 */
function lojaAutosaveAplicarPatch(string $loja, array $patch): array
{
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '' || !lojaAutosaveLojaValida($loja)) {
        return ['ok' => false, 'error' => 'Loja inválida'];
    }

    $whitelist = array_flip(lojaAutosaveWhitelistPorLoja()[$loja]);
    $filtrado = [];
    foreach ($patch as $k => $v) {
        if (isset($whitelist[$k])) {
            $filtrado[$k] = $v;
        }
    }

    if ($filtrado === []) {
        return ['ok' => true, 'cron_extra' => '', 'cron_modo_global_forcado' => false];
    }

    $autoKey = lojaAutosaveChaveAutomacaoAtiva($loja);
    $autoEraOff = true;
    if ($autoKey !== null) {
        $autoEraOff = getConfig($autoKey, '0') !== '1';
    }

    try {
        if ($loja === 'ml') {
            if (array_key_exists('ml_automacao_ativa', $filtrado)) {
                setConfig('ml_automacao_ativa', $filtrado['ml_automacao_ativa'] === '1' || $filtrado['ml_automacao_ativa'] === 1 || $filtrado['ml_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('ml_tag_afiliado', $filtrado)) {
                setConfig('ml_tag_afiliado', lojaAutosaveStr($filtrado['ml_tag_afiliado']));
            }
            if (array_key_exists('ml_csrf_token', $filtrado)) {
                setConfig('ml_csrf_token', lojaAutosaveStr($filtrado['ml_csrf_token']));
            }
            if (array_key_exists('ml_cookie', $filtrado)) {
                setConfig('ml_cookie', lojaAutosaveStr($filtrado['ml_cookie']));
            }
            if (array_key_exists('ml_openai_model', $filtrado)) {
                setConfig('ml_openai_model', lojaAutosaveStr($filtrado['ml_openai_model']) ?: 'gpt-4.1-mini');
            }
            if (array_key_exists('ml_openai_prompt', $filtrado)) {
                setConfig('ml_openai_prompt', lojaAutosaveStr($filtrado['ml_openai_prompt']));
            }
            if (array_key_exists('ml_link_grupo_whatsapp', $filtrado)) {
                setConfig('ml_link_grupo_whatsapp', lojaAutosaveStr($filtrado['ml_link_grupo_whatsapp']));
            }
            if (array_key_exists('ml_evolution_conta_id', $filtrado)) {
                setConfig('ml_evolution_conta_id', (string) (int) $filtrado['ml_evolution_conta_id']);
            }
            if (array_key_exists('ml_grupos_ids', $filtrado)) {
                $ids = lojaAutosaveIntArray($filtrado['ml_grupos_ids']);
                setConfig('ml_grupos_ids', $ids === [] ? '' : implode(',', $ids));
            }
            if (array_key_exists('ml_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('ml', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['ml_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('ml_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('ml_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('ml_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('ml_produtos_por_execucao', $filtrado)) {
                setConfig('ml_produtos_por_execucao', (string) max(1, min(10, (int) $filtrado['ml_produtos_por_execucao'])));
            }
            if (array_key_exists('ml_delay_entre_envios', $filtrado)) {
                setConfig('ml_delay_entre_envios', (string) max(1, min(120, (int) $filtrado['ml_delay_entre_envios'])));
            }
            if (array_key_exists('ml_dias_evitar_repetir', $filtrado)) {
                setConfig('ml_dias_evitar_repetir', (string) max(0, min(365, (int) $filtrado['ml_dias_evitar_repetir'])));
            }
            if (array_key_exists('ml_site_publicar', $filtrado)) {
                setConfig('ml_site_publicar', $filtrado['ml_site_publicar'] === '1' || $filtrado['ml_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('ml_site_categoria_id', $filtrado)) {
                setConfig('ml_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['ml_site_categoria_id']));
            }
            if (array_key_exists('ml_whatsapp_exigir_foto', $filtrado)) {
                setConfig('ml_whatsapp_exigir_foto', $filtrado['ml_whatsapp_exigir_foto'] === '1' || $filtrado['ml_whatsapp_exigir_foto'] === true ? '1' : '0');
            }
        } elseif ($loja === 'shopee') {
            if (array_key_exists('shopee_automacao_ativa', $filtrado)) {
                setConfig('shopee_automacao_ativa', $filtrado['shopee_automacao_ativa'] === '1' || $filtrado['shopee_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('shopee_app_id', $filtrado)) {
                setConfig('shopee_app_id', lojaAutosaveStr($filtrado['shopee_app_id']));
            }
            if (array_key_exists('shopee_secret', $filtrado)) {
                setConfig('shopee_secret', lojaAutosaveStr($filtrado['shopee_secret']));
            }
            if (array_key_exists('shopee_openai_model', $filtrado)) {
                setConfig('shopee_openai_model', lojaAutosaveStr($filtrado['shopee_openai_model']) ?: 'gpt-4o-mini');
            }
            if (array_key_exists('shopee_openai_prompt', $filtrado)) {
                setConfig('shopee_openai_prompt', lojaAutosaveStr($filtrado['shopee_openai_prompt']));
            }
            if (array_key_exists('shopee_evolution_conta_id', $filtrado)) {
                setConfig('shopee_evolution_conta_id', (string) (int) $filtrado['shopee_evolution_conta_id']);
            }
            if (array_key_exists('shopee_grupos_ids', $filtrado)) {
                $ids = lojaAutosaveIntArray($filtrado['shopee_grupos_ids']);
                setConfig('shopee_grupos_ids', $ids === [] ? '' : implode(',', $ids));
            }
            if (array_key_exists('shopee_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('shopee', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['shopee_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('shopee_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('shopee_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('shopee_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('shopee_produtos_por_execucao', $filtrado)) {
                setConfig('shopee_produtos_por_execucao', (string) max(1, min(10, (int) $filtrado['shopee_produtos_por_execucao'])));
            }
            if (array_key_exists('shopee_delay_entre_envios', $filtrado)) {
                setConfig('shopee_delay_entre_envios', (string) max(1, min(120, (int) $filtrado['shopee_delay_entre_envios'])));
            }
            if (array_key_exists('shopee_dias_evitar_repetir', $filtrado)) {
                setConfig('shopee_dias_evitar_repetir', (string) max(0, min(365, (int) $filtrado['shopee_dias_evitar_repetir'])));
            }
            if (array_key_exists('shopee_site_publicar', $filtrado)) {
                setConfig('shopee_site_publicar', $filtrado['shopee_site_publicar'] === '1' || $filtrado['shopee_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('shopee_site_categoria_id', $filtrado)) {
                setConfig('shopee_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['shopee_site_categoria_id']));
            }
        } elseif ($loja === 'magalu') {
            if (array_key_exists('magalu_automacao_ativa', $filtrado)) {
                setConfig('magalu_automacao_ativa', $filtrado['magalu_automacao_ativa'] === '1' || $filtrado['magalu_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('magalu_loja_url', $filtrado)) {
                setConfig('magalu_loja_url', lojaAutosaveStr($filtrado['magalu_loja_url']));
            }
            if (array_key_exists('magalu_loja_url_alternativa', $filtrado)) {
                setConfig('magalu_loja_url_alternativa', lojaAutosaveStr($filtrado['magalu_loja_url_alternativa']));
            }
            if (array_key_exists('magalu_scraper_api_key', $filtrado)) {
                setConfig('magalu_scraper_api_key', lojaAutosaveStr($filtrado['magalu_scraper_api_key']));
            }
            if (array_key_exists('magalu_openai_model', $filtrado)) {
                setConfig('magalu_openai_model', lojaAutosaveStr($filtrado['magalu_openai_model']) ?: 'gpt-4o-mini');
            }
            if (array_key_exists('magalu_openai_prompt', $filtrado)) {
                setConfig('magalu_openai_prompt', lojaAutosaveStr($filtrado['magalu_openai_prompt']));
            }
            if (array_key_exists('magalu_evolution_conta_id', $filtrado)) {
                setConfig('magalu_evolution_conta_id', (string) (int) $filtrado['magalu_evolution_conta_id']);
            }
            if (array_key_exists('magalu_grupos_ids', $filtrado)) {
                $ids = lojaAutosaveIntArray($filtrado['magalu_grupos_ids']);
                setConfig('magalu_grupos_ids', $ids === [] ? '' : implode(',', $ids));
            }
            if (array_key_exists('magalu_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('magalu', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['magalu_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('magalu_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('magalu_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('magalu_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('magalu_produtos_por_execucao', $filtrado)) {
                setConfig('magalu_produtos_por_execucao', (string) max(1, min(10, (int) $filtrado['magalu_produtos_por_execucao'])));
            }
            if (array_key_exists('magalu_delay_entre_envios', $filtrado)) {
                setConfig('magalu_delay_entre_envios', (string) max(1, min(120, (int) $filtrado['magalu_delay_entre_envios'])));
            }
            if (array_key_exists('magalu_dias_evitar_repetir', $filtrado)) {
                setConfig('magalu_dias_evitar_repetir', (string) max(0, min(365, (int) $filtrado['magalu_dias_evitar_repetir'])));
            }
            if (array_key_exists('magalu_site_publicar', $filtrado)) {
                setConfig('magalu_site_publicar', $filtrado['magalu_site_publicar'] === '1' || $filtrado['magalu_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('magalu_site_categoria_id', $filtrado)) {
                setConfig('magalu_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['magalu_site_categoria_id']));
            }
        } elseif ($loja === 'amazon') {
            if (array_key_exists('amazon_automacao_ativa', $filtrado)) {
                setConfig('amazon_automacao_ativa', $filtrado['amazon_automacao_ativa'] === '1' || $filtrado['amazon_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('amazon_access_key', $filtrado)) {
                setConfig('amazon_access_key', lojaAutosaveStr($filtrado['amazon_access_key']));
            }
            if (array_key_exists('amazon_secret_key', $filtrado)) {
                setConfig('amazon_secret_key', lojaAutosaveStr($filtrado['amazon_secret_key']));
            }
            if (array_key_exists('amazon_associate_tag', $filtrado)) {
                setConfig('amazon_associate_tag', lojaAutosaveStr($filtrado['amazon_associate_tag']));
            }
            if (array_key_exists('amazon_region', $filtrado)) {
                setConfig('amazon_region', lojaAutosaveStr($filtrado['amazon_region']) ?: 'com.br');
            }
            if (array_key_exists('amazon_search_keywords', $filtrado)) {
                setConfig('amazon_search_keywords', lojaAutosaveStr($filtrado['amazon_search_keywords']));
            }
            if (array_key_exists('amazon_openai_model', $filtrado)) {
                setConfig('amazon_openai_model', lojaAutosaveStr($filtrado['amazon_openai_model']) ?: 'gpt-4o-mini');
            }
            if (array_key_exists('amazon_openai_prompt', $filtrado)) {
                setConfig('amazon_openai_prompt', lojaAutosaveStr($filtrado['amazon_openai_prompt']));
            }
            if (array_key_exists('amazon_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('amazon', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['amazon_telegram_chat_ids'])));
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('amazon_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('amazon_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('amazon_site_publicar', $filtrado)) {
                setConfig('amazon_site_publicar', $filtrado['amazon_site_publicar'] === '1' || $filtrado['amazon_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('amazon_site_categoria_id', $filtrado)) {
                setConfig('amazon_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['amazon_site_categoria_id']));
            }
        } elseif ($loja === 'shein') {
            if (array_key_exists('shein_automacao_ativa', $filtrado)) {
                setConfig('shein_automacao_ativa', $filtrado['shein_automacao_ativa'] === '1' || $filtrado['shein_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('shein_api_key', $filtrado)) {
                setConfig('shein_api_key', lojaAutosaveStr($filtrado['shein_api_key']));
            }
            if (array_key_exists('shein_api_secret', $filtrado)) {
                setConfig('shein_api_secret', lojaAutosaveStr($filtrado['shein_api_secret']));
            }
            if (array_key_exists('shein_openai_model', $filtrado)) {
                setConfig('shein_openai_model', lojaAutosaveStr($filtrado['shein_openai_model']) ?: 'gpt-4o-mini');
            }
            if (array_key_exists('shein_openai_prompt', $filtrado)) {
                setConfig('shein_openai_prompt', lojaAutosaveStr($filtrado['shein_openai_prompt']));
            }
            if (array_key_exists('shein_evolution_conta_id', $filtrado)) {
                setConfig('shein_evolution_conta_id', (string) (int) $filtrado['shein_evolution_conta_id']);
            }
            if (array_key_exists('shein_grupos_ids', $filtrado)) {
                $ids = lojaAutosaveIntArray($filtrado['shein_grupos_ids']);
                setConfig('shein_grupos_ids', $ids === [] ? '' : implode(',', $ids));
            }
            if (array_key_exists('shein_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('shein', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['shein_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('shein_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('shein_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('shein_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('shein_produtos_por_execucao', $filtrado)) {
                setConfig('shein_produtos_por_execucao', (string) max(1, min(10, (int) $filtrado['shein_produtos_por_execucao'])));
            }
            if (array_key_exists('shein_delay_entre_envios', $filtrado)) {
                setConfig('shein_delay_entre_envios', (string) max(1, min(120, (int) $filtrado['shein_delay_entre_envios'])));
            }
            if (array_key_exists('shein_dias_evitar_repetir', $filtrado)) {
                setConfig('shein_dias_evitar_repetir', (string) max(0, min(365, (int) $filtrado['shein_dias_evitar_repetir'])));
            }
            if (array_key_exists('shein_site_publicar', $filtrado)) {
                setConfig('shein_site_publicar', $filtrado['shein_site_publicar'] === '1' || $filtrado['shein_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('shein_site_categoria_id', $filtrado)) {
                setConfig('shein_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['shein_site_categoria_id']));
            }
        } elseif ($loja === 'aliexpress') {
            if (array_key_exists('aliexpress_automacao_ativa', $filtrado)) {
                setConfig('aliexpress_automacao_ativa', $filtrado['aliexpress_automacao_ativa'] === '1' || $filtrado['aliexpress_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('aliexpress_app_key', $filtrado)) {
                setConfig('aliexpress_app_key', lojaAutosaveStr($filtrado['aliexpress_app_key']));
            }
            if (array_key_exists('aliexpress_app_secret', $filtrado)) {
                setConfig('aliexpress_app_secret', lojaAutosaveStr($filtrado['aliexpress_app_secret']));
            }
            if (array_key_exists('aliexpress_openai_model', $filtrado)) {
                setConfig('aliexpress_openai_model', lojaAutosaveStr($filtrado['aliexpress_openai_model']) ?: 'gpt-4o-mini');
            }
            if (array_key_exists('aliexpress_openai_prompt', $filtrado)) {
                setConfig('aliexpress_openai_prompt', lojaAutosaveStr($filtrado['aliexpress_openai_prompt']));
            }
            if (array_key_exists('aliexpress_evolution_conta_id', $filtrado)) {
                setConfig('aliexpress_evolution_conta_id', (string) (int) $filtrado['aliexpress_evolution_conta_id']);
            }
            if (array_key_exists('aliexpress_grupos_ids', $filtrado)) {
                $ids = lojaAutosaveIntArray($filtrado['aliexpress_grupos_ids']);
                setConfig('aliexpress_grupos_ids', $ids === [] ? '' : implode(',', $ids));
            }
            if (array_key_exists('aliexpress_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('aliexpress', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['aliexpress_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('aliexpress_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('aliexpress_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('aliexpress_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('aliexpress_site_publicar', $filtrado)) {
                setConfig('aliexpress_site_publicar', $filtrado['aliexpress_site_publicar'] === '1' || $filtrado['aliexpress_site_publicar'] === true ? '1' : '0');
            }
            if (array_key_exists('aliexpress_site_categoria_id', $filtrado)) {
                setConfig('aliexpress_site_categoria_id', lojaAutosaveNormalizarCategoriaFixa($filtrado['aliexpress_site_categoria_id']));
            }
        } elseif ($loja === 'ml_cupons') {
            if (array_key_exists('ml_cupons_cookie', $filtrado)) {
                setConfig('ml_cupons_cookie', lojaAutosaveStr($filtrado['ml_cupons_cookie']));
            }
            if (array_key_exists('ml_cupons_csrf_token', $filtrado)) {
                setConfig('ml_cupons_csrf_token', lojaAutosaveStr($filtrado['ml_cupons_csrf_token']));
            }
            foreach (['ml_api_client_id', 'ml_api_redirect_uri', 'ml_api_access_token', 'ml_api_refresh_token', 'ml_api_user_id'] as $k) {
                if (array_key_exists($k, $filtrado)) {
                    setConfig($k, lojaAutosaveStr($filtrado[$k]));
                }
            }
            if (array_key_exists('ml_api_client_secret', $filtrado) && lojaAutosaveStr($filtrado['ml_api_client_secret']) !== '') {
                setConfig('ml_api_client_secret', lojaAutosaveStr($filtrado['ml_api_client_secret']));
            }
            if (array_key_exists('ml_cupons_automacao_ativa', $filtrado)) {
                setConfig('ml_cupons_automacao_ativa', $filtrado['ml_cupons_automacao_ativa'] === '1' || $filtrado['ml_cupons_automacao_ativa'] === true ? '1' : '0');
            }
            if (array_key_exists('ml_cupons_evolution_conta_id', $filtrado)) {
                $evolutionContaId = (int) $filtrado['ml_cupons_evolution_conta_id'];
                setConfig('ml_cupons_evolution_conta_id', (string) $evolutionContaId);
                if ($evolutionContaId > 0) {
                    try {
                        $pdo = getDB();
                        $stmt = $pdo->prepare('SELECT url_base, instancia, api_key FROM evolution_contas WHERE id = ? AND ativo = 1');
                        $stmt->execute([$evolutionContaId]);
                        $conta = $stmt->fetch();
                        if ($conta) {
                            setConfig('ml_cupons_evolution_url', rtrim($conta['url_base'], '/'));
                            setConfig('ml_cupons_evolution_instancia', $conta['instancia']);
                            setConfig('ml_cupons_evolution_apikey', $conta['api_key']);
                        }
                    } catch (Exception $e) {
                        // Ignorar
                    }
                }
            }
            if (array_key_exists('ml_cupons_grupos', $filtrado)) {
                $gruposSelecionados = lojaAutosaveIntArray($filtrado['ml_cupons_grupos']);
                setConfig('ml_cupons_grupos_ids', json_encode($gruposSelecionados));
                if ($gruposSelecionados !== []) {
                    try {
                        $pdo = getDB();
                        $placeholders = implode(',', array_fill(0, count($gruposSelecionados), '?'));
                        $stmt = $pdo->prepare("SELECT grupo_id FROM grupos_whatsapp WHERE id IN ($placeholders) AND ativo = 1");
                        $stmt->execute($gruposSelecionados);
                        $gruposIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        setConfig('ml_cupons_evolution_grupos', implode("\n", $gruposIds));
                    } catch (Exception $e) {
                        // Ignorar
                    }
                } else {
                    setConfig('ml_cupons_evolution_grupos', '');
                }
            }
            if (array_key_exists('ml_cupons_telegram_chat_ids', $filtrado)) {
                syncLojaTelegramGrupos('ml_cupons', telegramLojaOwnerUserId(), parseTelegramChatIdsMultiline(lojaAutosaveStr($filtrado['ml_cupons_telegram_chat_ids'])));
            }
            if (array_key_exists('whatsapp_status_ativo', $filtrado)) {
                setConfig('ml_cupons_whatsapp_status_ativo', $filtrado['whatsapp_status_ativo'] === '1' || $filtrado['whatsapp_status_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('telegram_envio_ativo', $filtrado)) {
                setConfig('ml_cupons_telegram_envio_ativo', ((string) ($filtrado['telegram_envio_ativo'] ?? '0')) === '1' ? '1' : '0');
            }
            if (array_key_exists('telegram_story_ativo', $filtrado)) {
                setConfig('ml_cupons_telegram_story_ativo', $filtrado['telegram_story_ativo'] === '1' || $filtrado['telegram_story_ativo'] === true ? '1' : '0');
            }
            if (array_key_exists('ml_cupons_link_ativacao', $filtrado)) {
                setConfig('ml_cupons_link_ativacao', lojaAutosaveStr($filtrado['ml_cupons_link_ativacao']));
            }
            if (array_key_exists('ml_cupons_delay_entre_envios', $filtrado)) {
                setConfig('ml_cupons_delay_entre_envios', (string) max(1, min(120, (int) $filtrado['ml_cupons_delay_entre_envios'])));
            }
            if (array_key_exists('ml_cupons_produtos_por_execucao', $filtrado)) {
                setConfig('ml_cupons_produtos_por_execucao', (string) max(1, min(10, (int) $filtrado['ml_cupons_produtos_por_execucao'])));
            }
            if (array_key_exists('ml_cupons_dias_evitar_repetir', $filtrado)) {
                setConfig('ml_cupons_dias_evitar_repetir', (string) max(0, min(365, (int) $filtrado['ml_cupons_dias_evitar_repetir'])));
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Erro ao gravar: ' . $e->getMessage()];
    }

    $cronExtra = '';
    $cronModoGlobalForcado = false;
    if (isset($filtrado['cron_painel_presente'])) {
        if (
            $autoKey !== null
            && array_key_exists($autoKey, $filtrado)
            && $autoEraOff
            && ($filtrado[$autoKey] === '1' || $filtrado[$autoKey] === 1 || $filtrado[$autoKey] === true)
        ) {
            $filtrado['cron_individual_ativo'] = '0';
            $cronModoGlobalForcado = true;
        }
        $cronExtra = painelProcessarCronLojaFromArray($loja, $filtrado);
        if ($loja === 'ml_cupons') {
            lojaAutosaveEspelhoCronMlCupons($filtrado);
        }
    }

    return ['ok' => true, 'cron_extra' => $cronExtra, 'cron_modo_global_forcado' => $cronModoGlobalForcado];
}
