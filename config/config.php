<?php
/**
 * Ambiente da aplicação. Afeta recursos que só devem rodar fora de produção (ex.: dispatch).
 * Em localhost, altere para 'development' se quiser testar dispatch.
 */
if (!defined('APP_ENV')) {
    define('APP_ENV', 'production'); // valores: production | development
}

/** Se true, ativa logs NDJSON de instrumentação (agent-debug, cron audit). Em geral use a config DB achadinhos_debug_instrumentacao = 1. */
if (!defined('ACHADINHOS_DEBUG_INSTRUMENTACAO')) {
    define('ACHADINHOS_DEBUG_INSTRUMENTACAO', false);
}

if (!function_exists('achadinhosIsProduction')) {
    function achadinhosIsProduction(): bool
    {
        return !defined('APP_ENV') || APP_ENV === 'production';
    }
}

/**
 * Exibição de erros: em produção não envia detalhes ao cliente; registra no log do PHP.
 */
if (!function_exists('achadinhosApplyRuntimeErrorHandling')) {
    function achadinhosApplyRuntimeErrorHandling(): void
    {
        error_reporting(E_ALL);
        $prod = !defined('APP_ENV') || APP_ENV === 'production';
        if ($prod) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        } else {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            ini_set('log_errors', '1');
        }
    }
}
achadinhosApplyRuntimeErrorHandling();
