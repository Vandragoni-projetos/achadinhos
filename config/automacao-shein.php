<?php
/**
 * Automação Shein: API Afiliados → filtrar/randomizar → OpenAI (copy) → Evolution (WhatsApp) e site.
 * Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_SHEIN_LOADED')) {
    define('AUTOMACAO_SHEIN_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

function runAutomacaoShein($forcarExecucao = false, $apenasGrupoId = null) {
    $details = [];
    $errors = [];

    $ativa      = $forcarExecucao || (getConfig('shein_automacao_ativa', '0') === '1');
    $apiKey     = trim(getConfig('shein_api_key', ''));
    $apiSecret  = trim(getConfig('shein_api_secret', ''));
    
    // Usar chave OpenAI global, se não houver, usar da loja (compatibilidade)
    $openaiKey = trim(getConfig('openai_api_key', ''));
    if (empty($openaiKey)) {
        $openaiKey = trim(getConfig('shein_openai_api_key', ''));
    }
    $openaiModel = trim(getConfig('shein_openai_model', 'gpt-4o-mini'));
    $openaiPrompt = getConfig('shein_openai_prompt', '');
    
    $qtd        = max(1, min(10, (int)getConfig('shein_produtos_por_execucao', '1')));
    $delay      = max(1, min(120, (int)getConfig('shein_delay_entre_envios', '10')));
    $publicarSite = getConfig('shein_site_publicar', '1') === '1';

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Shein desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($apiKey) || empty($apiSecret)) {
        $errors[] = 'Shein: preencha API Key e Secret.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Shein.', 'details' => $details, 'errors' => $errors];
    }

    // TODO: Implementar chamada à API Shein Affiliate
    // A Shein tem programa de afiliados, mas a documentação da API pode variar
    // Verificar: https://affiliate.shein.com/ ou documentação oficial
    
    $details['produtos_api'] = 0;
    $details['produtos_validos'] = 0;
    $details['produtos_site'] = [];
    $details['produtos_processados'] = 0;
    $details['mensagens_enviadas'] = 0;
    
    return [
        'success' => false,
        'message' => 'Automação Shein ainda não implementada. Configure as credenciais e aguarde a implementação da API.',
        'details' => $details,
        'errors' => ['API Shein ainda não integrada']
    ];
}
