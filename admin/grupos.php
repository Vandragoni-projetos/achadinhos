<?php
$pageTitle = 'Grupos e publicação';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/ml-ofertas-categorias-brasil.php';
require_once __DIR__ . '/../config/shopee-ofertas-categorias-brasil.php';
require_once __DIR__ . '/../config/aliexpress-affiliate-categorias-pt.php';
require_once __DIR__ . '/../config/amazon-ofertas-browse-nodes-br.php';

// AJAX: Buscar grupos da Evolution API — DEVE rodar ANTES do header (que envia HTML)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_grupos_evolution') {
    if (!isLoggedIn()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(api_response_error('Sessão expirada. Faça login novamente.'));
        exit;
    }
    // Libera o lock da sessão antes de chamadas HTTP longas (evita travar outras abas/requisições do admin).
    if (function_exists('session_write_close')) {
        session_write_close();
    }
    $pdo = getDB();
    header('Content-Type: application/json; charset=utf-8');
    $contaId = (int)($_GET['conta_id'] ?? 0);
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    if ($contaId <= 0) {
        echo json_encode(api_response_error('Selecione uma conta.'));
        exit;
    }
    $hasProv = false;
    $hasApiPropria = false;
    try {
        $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
        $hasProv = true;
        $hasApiPropria = true;
    } catch (Exception $e) {
        try {
            $pdo->query('SELECT provedor, uazapi_admin_token FROM evolution_contas LIMIT 1');
            $hasProv = true;
        } catch (Exception $e2) {
            $hasProv = false;
        }
        try {
            $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
            $hasApiPropria = true;
        } catch (Exception $e3) {
            $hasApiPropria = false;
        }
    }
    $cols = 'url_base, instancia, api_key';
    if ($hasProv) {
        $cols .= ', COALESCE(provedor, \'evolution\') AS provedor, uazapi_admin_token';
    }
    if ($hasApiPropria) {
        $cols .= ', COALESCE(api_propria, 0) AS api_propria';
    }
    $stmt = $pdo->prepare("SELECT {$cols} FROM evolution_contas WHERE id = ? AND ativo = 1");
    $stmt->execute([$contaId]);
    $conta = $stmt->fetch();
    if (!$conta) {
        echo json_encode(api_response_error('Conta não encontrada.'));
        exit;
    }
    $provedor = $hasProv ? ($conta['provedor'] ?? 'evolution') : 'evolution';
    if ($provedor === 'uazapi') {
        require_once __DIR__ . '/../config/uazapi_whatsapp.php';
        $list = uazapiListarGrupos(
            (string) ($conta['url_base'] ?? ''),
            (string) ($conta['api_key'] ?? ''),
            uazapiResolverAdminToken($conta),
            false
        );
        if ($list['ok']) {
            echo json_encode(api_response_success('OK', ['grupos' => $list['grupos']]), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(api_response_error($list['message'] ?? 'Falha ao listar grupos na Uazapi.'), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    $evConta = [
        'url_base' => (string) ($conta['url_base'] ?? ''),
        'instancia' => (string) ($conta['instancia'] ?? ''),
        'api_key' => (string) ($conta['api_key'] ?? ''),
    ];
    if ($hasApiPropria) {
        $evConta['api_propria'] = $conta['api_propria'] ?? 0;
    }
    $useFast = isset($_GET['fast']) && ($_GET['fast'] === '1' || $_GET['fast'] === 'true');
    $ev = $useFast ? achadinhosEvolutionFetchGruposPainelRapido($evConta) : achadinhosEvolutionFetchGruposPainel($evConta);
    if ($ev['ok']) {
        echo json_encode(api_response_success('OK', ['grupos' => $ev['grupos']]), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(api_response_error($ev['message'] ?? 'Falha ao listar grupos na Evolution.'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../core/db/SchemaHelper.php';
garantirColunaGruposWhatsappIntervaloMinutos();
garantirColunaGruposWhatsappMlOfertasCategoria();
garantirColunaGruposWhatsappPostHoras();
garantirColunaGruposWhatsappAutomacaoLoja();
garantirColunaGruposWhatsappShopeeOfertasCategoria();
garantirColunaGruposWhatsappAliexpressCategoria();
garantirColunaGruposWhatsappAmazonOfertasCategoria();
garantirColunaGruposWhatsappCronJobOrgId();
garantirColunasGruposWhatsappCronOrgSyncAudit();

$pdo = getDB();

$aliexpress_categorias_form = [];
$aliexpress_categorias_err = '';
$message = '';
$messageType = '';
if (isset($_GET['saved'])) {
    $message = !empty($_GET['bulk']) ? 'Cadastro de grupos concluído.' : 'Grupo cadastrado com sucesso!';
    $messageType = 'success';
    if (function_exists('startSession')) {
        startSession();
    }
    if (!empty($_SESSION['grupo_bulk_flash'])) {
        $message .= ' ' . (string) $_SESSION['grupo_bulk_flash'];
        unset($_SESSION['grupo_bulk_flash']);
    }
    if (!empty($_GET['cron_org_warn'])) {
        if (session_status() !== PHP_SESSION_ACTIVE && function_exists('startSession')) {
            startSession();
        }
        $message .= ' Algumas regras podem ter ficado com sincronização parcial ou aviso na cron-job.org (veja a coluna «Última sync API» na lista e o texto abaixo).';
        if (!empty($_SESSION['grupo_cron_org_flash'])) {
            $message .= ' Detalhe: ' . (string) $_SESSION['grupo_cron_org_flash'];
            unset($_SESSION['grupo_cron_org_flash']);
        } else {
            $message .= ' Confirme em Configurações → Crons: chave API, URL base pública e token de cron para rodar-grupo.php.';
        }
        $flashCode = (string) ($_SESSION['grupo_cron_org_flash_code'] ?? '');
        unset($_SESSION['grupo_cron_org_flash_code']);
        if ($flashCode === 'token_http_official' || $flashCode === 'token_http_url_invalid') {
            $message .= ' Ação: em Configurações → Crons confira o token HTTP principal (cron_token); ao guardar um grupo com a API ativa o sistema pode gerá-lo automaticamente.';
        }
        $messageType = 'warning';
    }
}
if (isset($_GET['all_deleted'])) {
    if (function_exists('startSession')) {
        startSession();
    }
    $n = (int) ($_SESSION['grupos_all_deleted_count'] ?? 0);
    unset($_SESSION['grupos_all_deleted_count']);
    $message = $n === 0
        ? 'Não havia grupos cadastrados.'
        : 'Foram removidos ' . $n . ' grupo(s). Os agendamentos na cron-job.org foram excluídos quando havia job salvo.';
    $messageType = 'success';
}
if (isset($_GET['delete_all_error'])) {
    if (function_exists('startSession')) {
        startSession();
    }
    $err = (string) ($_SESSION['grupos_delete_all_error'] ?? 'Erro desconhecido.');
    unset($_SESSION['grupos_delete_all_error']);
    $message = 'Não foi possível excluir todos os grupos: ' . htmlspecialchars($err);
    $messageType = 'error';
}

$tablesOk = false;
try {
    $pdo->query("SELECT 1 FROM grupos_whatsapp LIMIT 1");
    $pdo->query("SELECT 1 FROM grupos_categorias LIMIT 1");
    $pdo->query("SELECT 1 FROM evolution_contas LIMIT 1");
    $tablesOk = true;
} catch (Exception $e) {
    $message = 'Importe o schema: migrations/achadinhos_install_completo.sql (base vazia).';
    $messageType = 'error';
}

// Excluir todos os grupos (POST — confirmação no navegador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesOk && isset($_POST['grupos_delete_all']) && (string) ($_POST['grupos_delete_all'] ?? '') === '1') {
    try {
        require_once __DIR__ . '/../core/cron/CronJobService.php';
        $ids = $pdo->query('SELECT id FROM grupos_whatsapp')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) {
                cronJobRemoverGrupoWhatsappNaOrg($rid);
            }
        }
        $pdo->exec('DELETE FROM grupos_whatsapp');
        if (function_exists('startSession')) {
            startSession();
        }
        $_SESSION['grupos_all_deleted_count'] = count($ids);
        header('Location: grupos.php?tab=lista&all_deleted=1');
        exit;
    } catch (Exception $e) {
        if (function_exists('startSession')) {
            startSession();
        }
        $_SESSION['grupos_delete_all_error'] = $e->getMessage();
        header('Location: grupos.php?tab=lista&delete_all_error=1');
        exit;
    }
}

// Processar formulário (antes do header: redirect após INSERT não pode ter HTML prévio)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesOk) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    if ($id !== null && $id <= 0) {
        $id = null;
    }

    // Cadastro em massa: vários grupos × várias lojas (aba Adicionar)
    if ($id === null && isset($_POST['novo_grupo_bulk']) && (string) ($_POST['novo_grupo_bulk'] ?? '') === '1') {
        $evolution_conta_id_bulk = isset($_POST['evolution_conta_id']) ? (int) $_POST['evolution_conta_id'] : 0;
        $bulkIds = $_POST['bulk_grupo_id'] ?? [];
        $bulkNomes = $_POST['bulk_grupo_nome'] ?? [];
        if (!is_array($bulkIds)) {
            $bulkIds = [];
        }
        if (!is_array($bulkNomes)) {
            $bulkNomes = [];
        }
        $lojasRaw = $_POST['automacao_lojas'] ?? [];
        if (!is_array($lojasRaw)) {
            $lojasRaw = [];
        }
        $lojasEscolhidas = [];
        $opcoesLoja = gruposAutomacaoLojaOpcoes();
        foreach ($lojasRaw as $lk) {
            $k = gruposNormalizarAutomacaoLoja((string) $lk);
            if ($k !== null && isset($opcoesLoja[$k])) {
                $lojasEscolhidas[$k] = $k;
            }
        }
        $lojasEscolhidas = array_values($lojasEscolhidas);
        $ocultasBulk = gruposAutomacaoLojaOcultasFormularioBulk();
        $lojasEscolhidas = array_values(array_filter($lojasEscolhidas, static function ($k) use ($ocultasBulk) {
            return !in_array($k, $ocultasBulk, true);
        }));

        $ativoB = isset($_POST['ativo']) ? 1 : 0;
        $intervalo_minutosB = isset($_POST['intervalo_minutos']) && $_POST['intervalo_minutos'] !== '' ? (int) $_POST['intervalo_minutos'] : null;
        if ($intervalo_minutosB !== null) {
            $intervalo_minutosB = max(1, min(1440, $intervalo_minutosB));
        }
        $phi = normalizarHoraPostagemGrupo($_POST['post_hora_inicio'] ?? '');
        $phf = normalizarHoraPostagemGrupo($_POST['post_hora_fim'] ?? '');
        if ($phi === null || $phf === null) {
            $post_hora_inicio_dbB = null;
            $post_hora_fim_dbB = null;
        } else {
            $post_hora_inicio_dbB = $phi;
            $post_hora_fim_dbB = $phf;
        }

        if ($evolution_conta_id_bulk <= 0) {
            $message = 'Selecione uma conta WhatsApp!';
            $messageType = 'error';
        } elseif ($bulkIds === []) {
            $message = 'Nenhum grupo selecionado. Marque um ou mais na lista ou use o ID manual.';
            $messageType = 'error';
        } elseif ($lojasEscolhidas === []) {
            $message = 'Marque ao menos uma loja (Mercado Livre, Shopee, etc.) para publicar neste(s) grupo(s).';
            $messageType = 'error';
        } else {
            try {
                require_once __DIR__ . '/../core/cron/CronJobService.php';
                $stmtIns = $pdo->prepare('INSERT INTO grupos_whatsapp (nome, grupo_id, evolution_conta_id, ativo, intervalo_minutos, automacao_loja, ml_ofertas_categoria, shopee_ofertas_categoria, aliexpress_affiliate_category_id, amazon_ofertas_categoria, post_hora_inicio, post_hora_fim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $chkDup = $pdo->prepare('SELECT id FROM grupos_whatsapp WHERE grupo_id = ? AND evolution_conta_id = ? AND automacao_loja = ? LIMIT 1');
                $inseridos = 0;
                $reutilizados = 0;
                $cronOrgWarn = false;
                foreach ($bulkIds as $bi => $gidRaw) {
                    $grupo_id_one = trim((string) $gidRaw);
                    if ($grupo_id_one === '') {
                        continue;
                    }
                    $nome_one = trim((string) ($bulkNomes[$bi] ?? ''));
                    if ($nome_one === '') {
                        $nome_one = 'Grupo WhatsApp';
                    }
                    foreach ($lojasEscolhidas as $aloja) {
                        [$ml_db, $sh_db, $ae_db, $am_db] = gruposMontarCategoriasPorLoja($aloja, $_POST);
                        $chkDup->execute([$grupo_id_one, $evolution_conta_id_bulk, $aloja]);
                        $ex = $chkDup->fetch(PDO::FETCH_ASSOC);
                        if ($ex) {
                            $exId = (int) ($ex['id'] ?? 0);
                            if ($exId > 0) {
                                $cronRes = cronJobSincronizarGrupoWhatsapp($exId);
                                if (!$cronRes['success'] || !empty($cronRes['sync_partial_no_job_id'])) {
                                    $cronOrgWarn = true;
                                    if (function_exists('startSession')) {
                                        startSession();
                                    }
                                    $_SESSION['grupo_cron_org_flash'] = (string) ($cronRes['message'] ?? '');
                                    $_SESSION['grupo_cron_org_flash_code'] = (string) ($cronRes['cron_sync_failure_code'] ?? '');
                                }
                            }
                            $reutilizados++;

                            continue;
                        }
                        $stmtIns->execute([$nome_one, $grupo_id_one, $evolution_conta_id_bulk, $ativoB, $intervalo_minutosB, $aloja, $ml_db, $sh_db, $ae_db, $am_db, $post_hora_inicio_dbB, $post_hora_fim_dbB]);
                        $newId = (int) $pdo->lastInsertId();
                        if ($newId > 0) {
                            $inseridos++;
                            $cronRes = cronJobSincronizarGrupoWhatsapp($newId);
                            if (!$cronRes['success'] || !empty($cronRes['sync_partial_no_job_id'])) {
                                $cronOrgWarn = true;
                                if (function_exists('startSession')) {
                                    startSession();
                                }
                                $_SESSION['grupo_cron_org_flash'] = (string) ($cronRes['message'] ?? '');
                                $_SESSION['grupo_cron_org_flash_code'] = (string) ($cronRes['cron_sync_failure_code'] ?? '');
                            }
                        }
                    }
                }
                if (function_exists('startSession')) {
                    startSession();
                }
                $_SESSION['grupo_bulk_flash'] = 'Novas regras (linhas novas): ' . $inseridos . '. Combinações grupo×loja já existentes — apenas re-sincronização com a cron-job.org: ' . $reutilizados . '.';
                $loc = 'grupos.php?tab=lista&saved=1&bulk=1' . ($cronOrgWarn ? '&cron_org_warn=1' : '');
                header('Location: ' . $loc);
                exit;
            } catch (Exception $e) {
                $message = 'Erro ao salvar: ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    }

    if ($id === null && isset($_POST['novo_grupo_bulk']) && (string) ($_POST['novo_grupo_bulk'] ?? '') === '1') {
        // Fluxo em massa já tratado acima (redirect ou mensagem de erro).
    } else {

    $nome = trim($_POST['nome'] ?? '');
    $grupo_id = trim($_POST['grupo_id'] ?? '');
    $evolution_conta_id = isset($_POST['evolution_conta_id']) ? (int)$_POST['evolution_conta_id'] : null;
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $intervalo_minutos = isset($_POST['intervalo_minutos']) && $_POST['intervalo_minutos'] !== '' ? (int)$_POST['intervalo_minutos'] : null;
    if ($intervalo_minutos !== null) {
        $intervalo_minutos = max(1, min(1440, $intervalo_minutos));
    }
    $automacao_loja = gruposNormalizarAutomacaoLoja((string) ($_POST['automacao_loja'] ?? 'ml'));
    $ml_custom = mercadolivreNormalizarCategoriaOfertas($_POST['ml_ofertas_categoria_custom'] ?? '');
    $ml_sel = mercadolivreNormalizarCategoriaOfertas($_POST['ml_ofertas_categoria'] ?? '');
    $ml_ofertas_categoria = $ml_custom !== '' ? $ml_custom : $ml_sel;
    if ($automacao_loja !== 'ml') {
        $ml_ofertas_categoria_db = null;
    } else {
        $ml_ofertas_categoria_db = $ml_ofertas_categoria === '' ? null : $ml_ofertas_categoria;
    }
    $shopee_custom = shopeeNormalizarCategoriaOfertasGrupo($_POST['shopee_ofertas_categoria_custom'] ?? '');
    $shopee_sel = shopeeNormalizarCategoriaOfertasGrupo($_POST['shopee_ofertas_categoria'] ?? '');
    $shopee_cat_combined = $shopee_custom !== '' ? $shopee_custom : $shopee_sel;
    $shopee_ofertas_categoria_db = ($automacao_loja === 'shopee' && $shopee_cat_combined !== '') ? $shopee_cat_combined : null;
    $aeCatPost = isset($_POST['aliexpress_affiliate_category_id']) ? (int) $_POST['aliexpress_affiliate_category_id'] : 0;
    $aliexpress_affiliate_category_db = $automacao_loja === 'aliexpress' ? max(0, $aeCatPost) : null;
    $amazon_custom = amazonNormalizarBrowseNodeGrupo($_POST['amazon_ofertas_categoria_custom'] ?? '');
    $amazon_sel = amazonNormalizarBrowseNodeGrupo($_POST['amazon_ofertas_categoria'] ?? '');
    $amazon_ofertas_categoria_db = ($automacao_loja === 'amazon')
        ? ($amazon_custom !== '' ? $amazon_custom : $amazon_sel)
        : null;
    if ($amazon_ofertas_categoria_db === '') {
        $amazon_ofertas_categoria_db = null;
    }
    $post_hora_inicio = normalizarHoraPostagemGrupo($_POST['post_hora_inicio'] ?? '');
    $post_hora_fim = normalizarHoraPostagemGrupo($_POST['post_hora_fim'] ?? '');
    if ($post_hora_inicio === null || $post_hora_fim === null) {
        $post_hora_inicio_db = null;
        $post_hora_fim_db = null;
    } else {
        $post_hora_inicio_db = $post_hora_inicio;
        $post_hora_fim_db = $post_hora_fim;
    }

    if (empty($nome) || empty($grupo_id)) {
        $message = 'Nome e ID do grupo são obrigatórios!';
        $messageType = 'error';
    } elseif ($automacao_loja === null) {
        $message = 'Loja de automação inválida. Escolha uma opção da lista (cada opção é uma regra separada por destino).';
        $messageType = 'error';
    } elseif ($evolution_conta_id === null || $evolution_conta_id <= 0) {
        $message = 'Selecione uma conta WhatsApp!';
        $messageType = 'error';
    } else {
        try {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE grupos_whatsapp SET nome = ?, grupo_id = ?, evolution_conta_id = ?, ativo = ?, intervalo_minutos = ?, automacao_loja = ?, ml_ofertas_categoria = ?, shopee_ofertas_categoria = ?, aliexpress_affiliate_category_id = ?, amazon_ofertas_categoria = ?, post_hora_inicio = ?, post_hora_fim = ? WHERE id = ?');
                $stmt->execute([$nome, $grupo_id, $evolution_conta_id, $ativo, $intervalo_minutos, $automacao_loja, $ml_ofertas_categoria_db, $shopee_ofertas_categoria_db, $aliexpress_affiliate_category_db, $amazon_ofertas_categoria_db, $post_hora_inicio_db, $post_hora_fim_db, $id]);
                $message = 'Grupo atualizado com sucesso!';
                $messageType = 'success';
                require_once __DIR__ . '/../core/cron/CronJobService.php';
                $cronRes = cronJobSincronizarGrupoWhatsapp((int) $id);
                if (!$cronRes['success']) {
                    $message .= ' Aviso (agendador cron-job.org): ' . $cronRes['message'];
                    if (($cronRes['cron_sync_failure_code'] ?? '') === 'token_http_official'
                        || ($cronRes['cron_sync_failure_code'] ?? '') === 'token_http_url_invalid') {
                        $message .= ' Verifique o token HTTP (cron_token) em Configurações → Crons ou guarde de novo após ativar a API.';
                    }
                    $messageType = 'warning';
                } elseif (!empty($cronRes['sync_partial_no_job_id'])) {
                    $message .= ' Aviso (cron-job.org): ' . (string) ($cronRes['message'] ?? '');
                    $messageType = 'warning';
                }
            } else {
                $stmt = $pdo->prepare('INSERT INTO grupos_whatsapp (nome, grupo_id, evolution_conta_id, ativo, intervalo_minutos, automacao_loja, ml_ofertas_categoria, shopee_ofertas_categoria, aliexpress_affiliate_category_id, amazon_ofertas_categoria, post_hora_inicio, post_hora_fim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$nome, $grupo_id, $evolution_conta_id, $ativo, $intervalo_minutos, $automacao_loja, $ml_ofertas_categoria_db, $shopee_ofertas_categoria_db, $aliexpress_affiliate_category_db, $amazon_ofertas_categoria_db, $post_hora_inicio_db, $post_hora_fim_db]);
                $newId = (int) $pdo->lastInsertId();
                $message = 'Grupo cadastrado com sucesso!';
                $cronOrgWarn = false;
                if ($newId > 0) {
                    require_once __DIR__ . '/../core/cron/CronJobService.php';
                    $cronRes = cronJobSincronizarGrupoWhatsapp($newId);
                    if (!$cronRes['success']) {
                        $cronOrgWarn = true;
                        if (function_exists('startSession')) {
                            startSession();
                        }
                        $_SESSION['grupo_cron_org_flash'] = (string) ($cronRes['message'] ?? '');
                        $_SESSION['grupo_cron_org_flash_code'] = (string) ($cronRes['cron_sync_failure_code'] ?? '');
                    } elseif (!empty($cronRes['sync_partial_no_job_id'])) {
                        if (function_exists('startSession')) {
                            startSession();
                        }
                        $_SESSION['grupo_cron_org_flash'] = (string) ($cronRes['message'] ?? '');
                        $_SESSION['grupo_cron_org_flash_code'] = (string) ($cronRes['cron_sync_failure_code'] ?? '');
                        $cronOrgWarn = true;
                    }
                }
                $loc = 'grupos.php?tab=lista&saved=1' . ($cronOrgWarn ? '&cron_org_warn=1' : '');
                header('Location: ' . $loc);
                exit;
            }
        } catch (Exception $e) {
            $message = 'Erro ao salvar: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
        }
    }
    }
}

// Deletar grupo
if (isset($_GET['delete']) && $tablesOk) {
    $id = (int)$_GET['delete'];
    try {
        require_once __DIR__ . '/../core/cron/CronJobService.php';
        cronJobRemoverGrupoWhatsappNaOrg($id);
        $pdo->prepare("DELETE FROM grupos_whatsapp WHERE id = ?")->execute([$id]);
        $message = 'Grupo deletado com sucesso!';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erro ao deletar: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

$editGrupo = null;
if (isset($_GET['edit']) && $tablesOk) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM grupos_whatsapp WHERE id = ?');
    $stmt->execute([$id]);
    $editGrupo = $stmt->fetch();
}

$rawEditLoja = '';
$editLojaNorm = null;
$editLoja = 'ml';
$editLojaInvalid = false;
if ($editGrupo) {
    $rawEditLoja = trim((string) ($editGrupo['automacao_loja'] ?? ''));
    $editLojaNorm = gruposNormalizarAutomacaoLoja($rawEditLoja !== '' ? $rawEditLoja : 'ml');
    $editLojaInvalid = ($editLojaNorm === null && $rawEditLoja !== '');
    $editLoja = $editLojaNorm ?? ($rawEditLoja !== '' ? $rawEditLoja : 'ml');
}

$mlOptsForm = mercadolivreOfertasCategoriasBrasilLista();
$shopeeOptsForm = shopeeOfertasCategoriasBrasilLista();
$amazonOptsForm = amazonOfertasBrowseNodesBrasilLista();
if ($editGrupo) {
    $mcur = mercadolivreNormalizarCategoriaOfertas($editGrupo['ml_ofertas_categoria'] ?? '');
    if ($mcur !== '' && !array_key_exists($mcur, $mlOptsForm)) {
        $mlOptsForm[$mcur] = $mcur . ' (código salvo)';
    }
    $scur = shopeeNormalizarCategoriaOfertasGrupo($editGrupo['shopee_ofertas_categoria'] ?? '');
    if ($scur !== '' && !array_key_exists($scur, $shopeeOptsForm)) {
        $shopeeOptsForm[$scur] = $scur . ' (código salvo)';
    }
    $azcur = amazonNormalizarBrowseNodeGrupo($editGrupo['amazon_ofertas_categoria'] ?? '');
    if ($azcur !== '' && !array_key_exists($azcur, $amazonOptsForm)) {
        $amazonOptsForm[$azcur] = 'Browse node ' . $azcur . ' (salvo)';
    }
}

$grupos = [];
$contasEvolution = [];
if ($tablesOk) {
    try {
        try {
            $pdo->query('SELECT provedor, api_propria FROM evolution_contas LIMIT 1');
            $grupos = $pdo->query('
                SELECT g.*, e.nome AS evolution_nome, e.instancia AS evolution_instancia,
                    COALESCE(NULLIF(TRIM(e.nome), \'\'), NULLIF(TRIM(e.instancia), \'\'), \'\') AS evolution_conta_label,
                    COALESCE(e.provedor, \'evolution\') AS evolution_conta_provedor,
                    COALESCE(e.api_propria, 0) AS evolution_conta_api_propria
                FROM grupos_whatsapp g
                LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
                ORDER BY g.nome
            ')->fetchAll();
        } catch (Exception $exG) {
            $grupos = $pdo->query('
                SELECT g.*, e.nome AS evolution_nome, e.instancia AS evolution_instancia,
                    COALESCE(NULLIF(TRIM(e.nome), \'\'), NULLIF(TRIM(e.instancia), \'\'), \'\') AS evolution_conta_label
                FROM grupos_whatsapp g
                LEFT JOIN evolution_contas e ON g.evolution_conta_id = e.id
                ORDER BY g.nome
            ')->fetchAll();
        }
        try {
            $pdo->query('SELECT provedor, api_propria FROM evolution_contas LIMIT 1');
            $contasEvolution = $pdo->query(
                'SELECT id, nome, instancia, COALESCE(provedor, \'evolution\') AS provedor, COALESCE(api_propria, 0) AS api_propria '
                . 'FROM evolution_contas WHERE ativo = 1 ORDER BY nome'
            )->fetchAll();
        } catch (Exception $exConta) {
            try {
                $contasEvolution = $pdo->query(
                    'SELECT id, nome, instancia FROM evolution_contas WHERE ativo = 1 ORDER BY nome'
                )->fetchAll();
            } catch (Exception $exConta2) {
                $contasEvolution = $pdo->query('SELECT id, nome FROM evolution_contas WHERE ativo = 1 ORDER BY nome')->fetchAll();
            }
        }
    } catch (Exception $e) {
        $message = 'Erro ao carregar dados: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

/**
 * Nome exibido da conta (lista e selects): nome cadastrado ou, se vazio, instância.
 *
 * @param array<string, mixed> $c Linha evolution_contas ou equivalente
 */
function gruposNomeContaPreferido(array $c): string {
    $rot = trim((string) ($c['nome'] ?? ''));
    if ($rot === '') {
        $rot = trim((string) ($c['instancia'] ?? ''));
    }
    if ($rot === '') {
        $rot = 'Conta #' . (int) ($c['id'] ?? 0);
    }

    return $rot;
}

/**
 * Rótulo da conta na lista (igual ao select de Adicionar): nome; se vazio, instância; meta provedor/api_propria.
 *
 * @param array<string, mixed> $g
 * @param list<array<string, mixed>> $contasEvolution
 * @return array{0: string, 1: array{provedor: string, api_propria: int}}
 */
function gruposListaResolverContaExibicao(array $g, array $contasEvolution): array {
    $label = trim((string) ($g['evolution_conta_label'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($g['evolution_nome'] ?? ''));
    }
    if ($label === '') {
        $label = trim((string) ($g['evolution_instancia'] ?? ''));
    }
    $cid = (int) ($g['evolution_conta_id'] ?? 0);
    if ($label === '' && $cid > 0) {
        foreach ($contasEvolution as $c) {
            if ((int) ($c['id'] ?? 0) !== $cid) {
                continue;
            }
            $label = gruposNomeContaPreferido($c);

            break;
        }
    }
    $meta = [
        'provedor' => (string) ($g['evolution_conta_provedor'] ?? 'evolution'),
        'api_propria' => (int) ($g['evolution_conta_api_propria'] ?? 0),
    ];
    if ($cid > 0 && !array_key_exists('evolution_conta_provedor', $g)) {
        foreach ($contasEvolution as $c) {
            if ((int) ($c['id'] ?? 0) !== $cid) {
                continue;
            }
            $meta['provedor'] = (string) ($c['provedor'] ?? 'evolution');
            $meta['api_propria'] = (int) ($c['api_propria'] ?? 0);

            break;
        }
    }

    return [$label, $meta];
}

/**
 * Colunas de categoria por loja (mesma regra do POST único).
 *
 * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?string} ml_cat, shopee_cat, ae_id, amazon_cat
 */
function gruposMontarCategoriasPorLoja(string $automacao_loja, array $post): array {
    $automacao_lojaN = gruposNormalizarAutomacaoLoja($automacao_loja);
    if ($automacao_lojaN === null) {
        return [null, null, null, null];
    }
    $automacao_loja = $automacao_lojaN;
    $ml_custom = mercadolivreNormalizarCategoriaOfertas($post['ml_ofertas_categoria_custom'] ?? '');
    $ml_sel = mercadolivreNormalizarCategoriaOfertas($post['ml_ofertas_categoria'] ?? '');
    $ml_ofertas_categoria = $ml_custom !== '' ? $ml_custom : $ml_sel;
    if ($automacao_loja !== 'ml') {
        $ml_ofertas_categoria_db = null;
    } else {
        $ml_ofertas_categoria_db = $ml_ofertas_categoria === '' ? null : $ml_ofertas_categoria;
    }
    $shopee_custom = shopeeNormalizarCategoriaOfertasGrupo($post['shopee_ofertas_categoria_custom'] ?? '');
    $shopee_sel = shopeeNormalizarCategoriaOfertasGrupo($post['shopee_ofertas_categoria'] ?? '');
    $shopee_cat_combined = $shopee_custom !== '' ? $shopee_custom : $shopee_sel;
    $shopee_ofertas_categoria_db = ($automacao_loja === 'shopee' && $shopee_cat_combined !== '') ? $shopee_cat_combined : null;
    $aeCatPost = isset($post['aliexpress_affiliate_category_id']) ? (int) $post['aliexpress_affiliate_category_id'] : 0;
    $aliexpress_affiliate_category_db = $automacao_loja === 'aliexpress' ? max(0, $aeCatPost) : null;
    $amazon_custom = amazonNormalizarBrowseNodeGrupo($post['amazon_ofertas_categoria_custom'] ?? '');
    $amazon_sel = amazonNormalizarBrowseNodeGrupo($post['amazon_ofertas_categoria'] ?? '');
    $amazon_ofertas_categoria_db = ($automacao_loja === 'amazon')
        ? ($amazon_custom !== '' ? $amazon_custom : $amazon_sel)
        : null;
    if ($amazon_ofertas_categoria_db === '') {
        $amazon_ofertas_categoria_db = null;
    }

    return [$ml_ofertas_categoria_db, $shopee_ofertas_categoria_db, $aliexpress_affiliate_category_db, $amazon_ofertas_categoria_db];
}

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'lista';
if (!in_array($activeTab, ['lista', 'adicionar'])) {
    $activeTab = 'lista';
}

$precisaAliexpressCategoriasApi = ($activeTab === 'adicionar')
    || ($editGrupo !== null && $editLoja === 'aliexpress');
if ($precisaAliexpressCategoriasApi) {
    $aeKeyForm = trim(getConfig('aliexpress_app_key', ''));
    $aeSecForm = trim(getConfig('aliexpress_app_secret', ''));
    if ($aeKeyForm !== '' && $aeSecForm !== '') {
        require_once __DIR__ . '/../config/automacao-aliexpress.php';
        $rawAe = aliexpressAffiliateCategoryGet($aeKeyForm, $aeSecForm, $aliexpress_categorias_err);
        foreach ($rawAe as $c) {
            $pt = aliexpressTraduzirTituloCategoria($c['name']);
            $en = $c['name'];
            $cid = (int) $c['id'];
            $aliexpress_categorias_form[] = [
                'id' => $cid,
                'label' => ($pt !== '' && $pt !== $en) ? ($pt . ' — ' . $en . ' [ID ' . $cid . ']') : ($en . ' [ID ' . $cid . ']'),
            ];
        }
    }
}

$aeCatSaved = 0;
$aeCatInList = true;
if ($editGrupo) {
    $aeCatSaved = (int) ($editGrupo['aliexpress_affiliate_category_id'] ?? 0);
    $aeCatInList = $aeCatSaved <= 0;
    if ($aeCatSaved > 0) {
        foreach ($aliexpress_categorias_form as $row) {
            if ((int) $row['id'] === $aeCatSaved) {
                $aeCatInList = true;
                break;
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 min-h-0 overflow-y-auto p-4 lg:p-6 w-full max-w-[1920px] xl:max-w-[100vw] xl:pr-8 mx-auto">
            <h1 class="text-2xl font-bold text-gray-800 mb-1">Grupos e publicação</h1>
            <p class="text-sm text-gray-600 mb-4 max-w-3xl">Cada combinação <strong>destino WhatsApp × loja de automação</strong> é uma <strong>regra operacional</strong> (uma linha na tabela). O agendamento na cron-job.org usa o ID interno da regra, não o JID do grupo.</p>

            <div id="grupo-envio-teste-msg" class="mb-4 hidden p-3 rounded-lg text-sm" role="status" aria-live="polite"></div>

            <?php if ($message): ?>
            <div class="mb-4 p-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700' : ($messageType === 'warning' ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-700'); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Abas -->
            <div class="mb-6 border-b border-gray-200">
                <nav class="-mb-px flex gap-6">
                    <a href="?tab=lista<?php echo $editGrupo ? '&edit=' . $editGrupo['id'] : ''; ?>" 
                       class="py-3 px-1 border-b-2 font-medium text-sm <?php echo $activeTab === 'lista' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        Meus Grupos (<?php echo count($grupos); ?>)
                    </a>
                    <a href="?tab=adicionar" 
                       class="py-3 px-1 border-b-2 font-medium text-sm <?php echo $activeTab === 'adicionar' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        Adicionar regras
                    </a>
                </nav>
            </div>

            <?php if ($activeTab === 'lista'): ?>
            <!-- Aba: Meus Grupos -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Nome</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Conta</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase min-w-[7rem]">Loja</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">ID Grupo</th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase w-32">Postagens</th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase min-w-[8rem]">Filtro ofertas</th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase w-20">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase min-w-[7rem]">Última sync API</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 w-32 align-top">
                                    <div class="flex flex-col items-end gap-1.5">
                                        <span class="uppercase tracking-wide">Ações</span>
                                        <?php if (!empty($grupos)): ?>
                                        <form method="POST" action="grupos.php?tab=lista" class="inline font-normal"
                                              onsubmit="return confirm('Remover TODOS os <?php echo (int) count($grupos); ?> grupo(s) cadastrados?\n\nOs agendamentos na cron-job.org serão apagados quando existir job vinculado. Esta ação não pode ser desfeita.');">
                                            <input type="hidden" name="grupos_delete_all" value="1">
                                            <button type="submit" class="text-red-600 hover:text-red-700 text-[10px] font-medium underline decoration-dotted normal-case tracking-normal">
                                                Excluir todos
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($grupos)): ?>
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-gray-400 text-sm">Nenhum grupo cadastrado. Vá em <a href="?tab=adicionar" class="text-orange-500 hover:underline">Adicionar Grupo</a> para começar.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($grupos as $g): ?>
                            <tr class="hover:bg-gray-50/50">
                                <td class="px-4 py-2.5 font-medium text-gray-900"><?php echo htmlspecialchars($g['nome']); ?></td>
                                <td class="px-4 py-2.5 text-gray-600"><?php
                                    [$nomeContaLista, $metaContaLista] = gruposListaResolverContaExibicao($g, $contasEvolution);
                                    if ($nomeContaLista === '') {
                                        echo '-';
                                    } else {
                                        $suf = achadinhosEvolutionContaTipoSufixo($metaContaLista);
                                        echo '<div class="text-gray-900 font-medium">' . htmlspecialchars($nomeContaLista) . '</div>';
                                        echo '<div class="text-xs text-gray-500 mt-0.5">' . htmlspecialchars(trim($suf)) . '</div>';
                                    }
                                ?></td>
                                <td class="px-4 py-2.5 text-gray-700 text-xs"><?php
                                    $lk = gruposNormalizarAutomacaoLoja((string) ($g['automacao_loja'] ?? ''));
                                    $opL = gruposAutomacaoLojaOpcoes();
                                    if ($lk === null) {
                                        echo '<span class="text-amber-700 font-medium" title="Corrija a loja de automação">Legado inválido</span>';
                                    } else {
                                        echo htmlspecialchars($opL[$lk] ?? $lk);
                                    }
                                ?></td>
                                <td class="px-4 py-2.5 text-gray-500 font-mono text-xs truncate max-w-[140px]" title="<?php echo htmlspecialchars($g['grupo_id']); ?>"><?php echo htmlspecialchars($g['grupo_id']); ?></td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-700">
                                    <?php
                                    $pi = $g['post_hora_inicio'] ?? null;
                                    $pf = $g['post_hora_fim'] ?? null;
                                    if (empty($pi) || empty($pf)) {
                                        echo '<span class="text-gray-400">24h</span>';
                                    } else {
                                        echo htmlspecialchars(substr((string) $pi, 0, 5) . ' – ' . substr((string) $pf, 0, 5));
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-600 font-mono">
                                    <?php
                                    $lk = gruposNormalizarAutomacaoLoja((string) ($g['automacao_loja'] ?? ''));
                                    if ($lk === 'ml' || $lk === 'ml_cupons') {
                                        $mlCatLista = mercadolivreNormalizarCategoriaOfertas($g['ml_ofertas_categoria'] ?? '');
                                        $pfx = $lk === 'ml_cupons' ? 'ML cupons' : 'ML';
                                        echo $mlCatLista === '' ? '<span class="text-gray-400">' . htmlspecialchars($pfx) . ': todas</span>' : htmlspecialchars($pfx) . ': ' . htmlspecialchars($mlCatLista);
                                    } elseif ($lk === 'shopee') {
                                        $sc = shopeeNormalizarCategoriaOfertasGrupo($g['shopee_ofertas_categoria'] ?? '');
                                        if ($sc === '') {
                                            echo '<span class="text-gray-400">Shopee: todas</span>';
                                        } else {
                                            $sl = shopeeOfertasCategoriasBrasilLista();
                                            $slabel = $sl[$sc] ?? $sc;
                                            echo 'Shopee: ' . htmlspecialchars($sc === $slabel ? $sc : ($slabel . ' (' . $sc . ')'));
                                        }
                                    } elseif ($lk === 'aliexpress') {
                                        $ax = (int) ($g['aliexpress_affiliate_category_id'] ?? 0);
                                        echo $ax > 0
                                            ? 'AE: ID ' . htmlspecialchars((string) $ax)
                                            : '<span class="text-gray-500">AE: todas as categorias</span>';
                                    } elseif ($lk === 'amazon') {
                                        $azn = amazonNormalizarBrowseNodeGrupo($g['amazon_ofertas_categoria'] ?? '');
                                        echo $azn === '' ? '<span class="text-gray-400">Amazon: todas</span>' : 'Amz: ' . htmlspecialchars($azn);
                                    } elseif ($lk === null) {
                                        echo '<span class="text-amber-700">—</span>';
                                    } else {
                                        echo '<span class="text-gray-400">—</span>';
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($g['ativo']): ?>
                                    <span class="text-[10px] font-medium px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">Ativo</span>
                                    <?php else: ?>
                                    <span class="text-[10px] font-medium px-2 py-0.5 rounded bg-gray-100 text-gray-500">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-left text-[10px] text-gray-600 leading-snug max-w-[10rem]">
                                    <?php
                                    $synAt = $g['cron_org_sync_at'] ?? null;
                                    if (empty($synAt)) {
                                        echo '<span class="text-gray-400">—</span>';
                                    } else {
                                        $sok = $g['cron_org_sync_ok'] ?? null;
                                        $sp = !empty($g['cron_org_sync_partial_no_job']);
                                        $hc = $g['cron_org_sync_http_code'] ?? null;
                                        $cls = ($sok === 1 || $sok === '1') ? 'text-emerald-700' : (($sok === 0 || $sok === '0') ? 'text-red-700' : 'text-gray-700');
                                        echo '<span class="font-medium ' . $cls . '">' . htmlspecialchars(substr((string) $synAt, 0, 16)) . '</span>';
                                        if ($hc !== null && $hc !== '') {
                                            echo '<br><span class="text-gray-500">HTTP ' . htmlspecialchars((string) $hc) . '</span>';
                                        }
                                        if ($sp) {
                                            echo '<br><span class="text-amber-800 font-medium">Sem ID local</span>';
                                        }
                                        $lop = trim((string) ($g['cron_org_sync_last_op'] ?? ''));
                                        if ($lop !== '') {
                                            echo '<br><span class="text-gray-500" title="' . htmlspecialchars((string) ($g['cron_org_sync_message'] ?? '')) . '">' . htmlspecialchars($lop) . '</span>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <a href="?tab=lista&edit=<?php echo $g['id']; ?>#form-edit" class="text-orange-500 hover:text-orange-600 text-xs font-medium">Editar</a>
                                    <span class="text-gray-300 mx-1">|</span>
                                    <button type="button" class="text-emerald-600 hover:text-emerald-700 text-xs font-medium align-baseline btn-enviar-teste-grupo" data-grupo-id="<?php echo (int) $g['id']; ?>">Enviar</button>
                                    <span class="text-gray-300 mx-1">|</span>
                                    <a href="?delete=<?php echo $g['id']; ?>" onclick="return confirm('Deletar este grupo?')" class="text-red-500 hover:text-red-600 text-xs font-medium">Excluir</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Editar (inline, só aparece quando edit) -->
            <?php if ($editGrupo): ?>
            <div id="form-edit" class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h2 class="text-lg font-bold text-gray-800 mb-4">Editar grupo</h2>
                <form method="POST" action="?tab=lista">
                    <input type="hidden" name="id" value="<?php echo $editGrupo['id']; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nome *</label>
                            <input type="text" name="nome" required value="<?php echo htmlspecialchars($editGrupo['nome']); ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">ID do grupo *</label>
                            <input type="text" name="grupo_id" required value="<?php echo htmlspecialchars($editGrupo['grupo_id']); ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Conta WhatsApp *</label>
                            <select name="evolution_conta_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                                <?php foreach ($contasEvolution as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $editGrupo['evolution_conta_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(gruposNomeContaPreferido($c) . achadinhosEvolutionContaTipoSufixo($c)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Loja de automação *</label>
                            <select name="automacao_loja" id="edit-automacao-loja" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                                <?php if (!empty($editLojaInvalid)): ?>
                                <option value="<?php echo htmlspecialchars($rawEditLoja); ?>" selected><?php echo htmlspecialchars('⚠ Legado inválido: ' . $rawEditLoja); ?></option>
                                <?php endif; ?>
                                <?php foreach (gruposAutomacaoLojaOpcoes() as $k => $lb): ?>
                                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo ($editLoja === $k && !$editLojaInvalid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lb); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-0.5">Cada loja é uma regra separada: o mesmo destino WhatsApp pode ter várias linhas (ex.: ML ofertas + ML cupons).</p>
                            <?php if (!empty($editLojaInvalid)): ?>
                            <p class="text-xs text-amber-800 mt-1">Guarde com uma loja válida da lista; o valor antigo não é suportado no runner.</p>
                            <?php endif; ?>
                        </div>
                        <div id="cfg-edit-aliexpress" class="md:col-span-3 <?php echo $editLoja !== 'aliexpress' ? 'hidden' : ''; ?>">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Categoria de produtos AliExpress (API de afiliados)</label>
                            <select name="aliexpress_affiliate_category_id" id="edit-aliexpress-categoria" class="w-full max-w-2xl px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                                <option value="0" <?php echo $aeCatSaved === 0 ? 'selected' : ''; ?>>Todas as categorias (busca geral + produto aleatório)</option>
                                <?php if ($aeCatSaved > 0 && !$aeCatInList): ?>
                                <option value="<?php echo $aeCatSaved; ?>" selected>Categoria salva (ID <?php echo $aeCatSaved; ?>) — não listada pela API agora</option>
                                <?php endif; ?>
                                <?php foreach ($aliexpress_categorias_form as $acer): ?>
                                <option value="<?php echo (int) $acer['id']; ?>" <?php echo $aeCatSaved > 0 && $aeCatSaved === (int) $acer['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acer['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($aliexpress_categorias_err !== '' && $aliexpress_categorias_form === []): ?>
                            <p class="text-xs text-red-600 mt-1"><?php echo htmlspecialchars($aliexpress_categorias_err); ?> Verifique App Key/Secret em <a href="aliexpress.php" class="text-orange-600 underline">AliExpress</a>.</p>
                            <?php else: ?>
                            <p class="text-xs text-gray-500 mt-1">Nomes em português quando possível. Em «Todas», a API é consultada sem filtro de categoria (palavra-chave e página variam) e um produto com link de afiliado é escolhido ao acaso.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Início das postagens (relógio)</label>
                            <input type="time" name="post_hora_inicio" step="60"
                                   value="<?php
                                   $pi = $editGrupo['post_hora_inicio'] ?? '';
                                   echo $pi !== '' && $pi !== null ? htmlspecialchars(substr((string) $pi, 0, 5)) : '';
                                   ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fim das postagens (relógio)</label>
                            <input type="time" name="post_hora_fim" step="60"
                                   value="<?php
                                   $pf = $editGrupo['post_hora_fim'] ?? '';
                                   echo $pf !== '' && $pf !== null ? htmlspecialchars(substr((string) $pf, 0, 5)) : '';
                                   ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                            <p class="text-xs text-gray-500 mt-0.5">Vazios = 24h. Se fim &lt; início, a janela cruza a meia-noite (fuso do servidor).</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Intervalo entre envios (min)</label>
                            <input type="number" name="intervalo_minutos" min="1" max="1440" placeholder="Padrão da loja"
                                   value="<?php echo isset($editGrupo['intervalo_minutos']) && $editGrupo['intervalo_minutos'] !== null ? (int)$editGrupo['intervalo_minutos'] : ''; ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                            <p class="text-xs text-gray-500 mt-0.5">Vazio = padrão da loja (ex.: Amazon usa <code class="text-[11px] bg-gray-100 px-1 rounded">amazon_delay_entre_envios</code> nas configs). Ex.: 20 = um envio a cada 20 min neste grupo.</p>
                        </div>
                        <div id="cfg-edit-amazon" class="md:col-span-3 <?php echo $editLoja !== 'amazon' ? 'hidden' : ''; ?>">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Categoria Amazon (departamento — PA-API)</label>
                            <select name="amazon_ofertas_categoria" class="w-full max-w-xl px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500">
                                <?php
                                $azVal = amazonNormalizarBrowseNodeGrupo($editGrupo['amazon_ofertas_categoria'] ?? '');
                                foreach ($amazonOptsForm as $nodeId => $azLabel) {
                                    $sel = ($azVal === (string) $nodeId) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars((string) $nodeId) . '"' . $sel . '>' . htmlspecialchars($azLabel . ($nodeId !== '' ? ' — ' . $nodeId : '')) . '</option>';
                                }
                                ?>
                            </select>
                            <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro Browse Node (opcional, só dígitos — <a href="https://www.amazon.com.br" target="_blank" rel="noopener" class="text-orange-600 hover:underline">amazon.com.br</a>)</label>
                            <input type="text" name="amazon_ofertas_categoria_custom" maxlength="20" pattern="[0-9]*" inputmode="numeric" autocomplete="off" placeholder="Ex.: 16209062011"
                                   class="w-full max-w-md px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                            <p class="text-xs text-gray-500 mt-1">«Todas as categorias» pesquisa em todo o catálogo com as palavras-chave da página Amazon. Com departamento, a PA-API filtra pelo nó. Os links usam o <strong>Associate Tag</strong> configurado em Amazon → API.</p>
                        </div>
                        <div id="cfg-edit-ml" class="md:col-span-3 <?php echo ($editLoja !== 'ml' && $editLoja !== 'ml_cupons') ? 'hidden' : ''; ?>">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Categoria nas ofertas do Mercado Livre</label>
                            <select name="ml_ofertas_categoria" class="w-full max-w-xl px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                <?php
                                $mlVal = mercadolivreNormalizarCategoriaOfertas($editGrupo['ml_ofertas_categoria'] ?? '');
                                foreach ($mlOptsForm as $mid => $mlabel) {
                                    $sel = ($mlVal === $mid) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars($mid) . '"' . $sel . '>' . htmlspecialchars($mlabel . ($mid !== '' ? ' — ' . $mid : '')) . '</option>';
                                }
                                ?>
                            </select>
                            <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro código MLB (opcional, sobrescreve a lista)</label>
                            <input type="text" name="ml_ofertas_categoria_custom" maxlength="32" autocomplete="off" placeholder="Ex.: MLB1234"
                                   class="w-full max-w-md px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                            <p class="text-xs text-gray-500 mt-0.5">A automação ML usa <code class="text-[11px] bg-gray-100 px-1 rounded">/ofertas?category=…</code>. <a href="https://www.mercadolivre.com.br/ofertas" class="text-orange-600 hover:underline" target="_blank" rel="noopener">Abrir ofertas do ML</a>.</p>
                        </div>
                        <div id="cfg-edit-shopee" class="md:col-span-3 <?php echo $editLoja !== 'shopee' ? 'hidden' : ''; ?>">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Categoria nas ofertas Shopee</label>
                            <select name="shopee_ofertas_categoria" class="w-full max-w-xl px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                <?php
                                $shopeeVal = shopeeNormalizarCategoriaOfertasGrupo($editGrupo['shopee_ofertas_categoria'] ?? '');
                                foreach ($shopeeOptsForm as $sid => $slabel) {
                                    $sel = ($shopeeVal === $sid) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars($sid) . '"' . $sel . '>' . htmlspecialchars($slabel . ($sid !== '' ? ' — ' . $sid : '')) . '</option>';
                                }
                                ?>
                            </select>
                            <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro ID numérico (opcional, sobrescreve a lista)</label>
                            <input type="text" name="shopee_ofertas_categoria_custom" maxlength="32" autocomplete="off" placeholder="Ex.: 11042813"
                                   class="w-full max-w-md px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono focus:ring-2 focus:ring-orange-500">
                            <p class="text-xs text-gray-500 mt-0.5">A API Brasil usa <code class="text-[11px] bg-gray-100 px-1 rounded">keyword</code> em <code class="text-[11px] bg-gray-100 px-1 rounded">productOfferV2</code> (não aceita mais <code class="text-[11px] bg-gray-100 px-1 rounded">categoryId</code>). Cada ID da lista corresponde a um termo de busca em <code class="text-[11px] bg-gray-100 px-1 rounded">config/shopee-ofertas-categorias-brasil.php</code>; ID “livre” só funciona se existir linha com esse número no arquivo.</p>
                        </div>
                        <div id="cfg-edit-magalu" class="md:col-span-3 <?php echo $editLoja !== 'magalu' ? 'hidden' : ''; ?>">
                            <p class="text-sm font-medium text-gray-800 mb-1">Magazine Luiza (catálogo)</p>
                            <p class="text-xs text-gray-600">Não há filtro de categoria por grupo: ofertas e palavras-chave vêm das <a href="magalu.php" class="text-orange-600 font-medium underline">configurações da loja Magalu</a>.</p>
                        </div>
                        <div id="cfg-edit-shein" class="md:col-span-3 <?php echo $editLoja !== 'shein' ? 'hidden' : ''; ?>">
                            <p class="text-sm font-medium text-gray-800 mb-1">Shein</p>
                            <p class="text-xs text-gray-600">Não há filtro de categoria por grupo: parâmetros da automação estão na <a href="shein.php" class="text-orange-600 font-medium underline">página Shein</a>.</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-4 mt-2">
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="ativo" value="1" <?php echo $editGrupo['ativo'] ? 'checked' : ''; ?> class="rounded text-orange-500">
                            Ativo
                        </label>
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold py-2 px-4 rounded-lg">Salvar</button>
                        <a href="?tab=lista" class="text-gray-500 hover:text-gray-700 text-sm">Cancelar</a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'adicionar'): ?>
            <!-- Aba: Adicionar Grupo -->
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            <div class="xl:col-span-7 space-y-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-lg font-bold text-gray-800 mb-1">Conta e chats</h2>
                    <p class="text-xs text-gray-500 mb-4">Ao <strong>carregar grupos</strong> (Evolution), o painel usa a lista <strong>completa</strong>: <code class="text-[11px] bg-gray-100 px-1 rounded">fetchAllGroups</code> + <code class="text-[11px] bg-gray-100 px-1 rounded">findChats</code>, com identificação de <strong>comunidade / avisos</strong> alinhada à Uazapi. Pode levar mais tempo que antes; resultados ficam em <strong>cache no navegador</strong> por 15&nbsp;min. Marque <strong>vários grupos</strong>; combinações JID+conta+loja já existentes só <strong>re-sincronizam</strong> a cron, sem duplicar linha.</p>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">1. Selecione a conta WhatsApp *</label>
                            <select id="conta-evolution" class="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500">
                                <option value="">— Selecione —</option>
                                <?php foreach ($contasEvolution as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars(gruposNomeContaPreferido($c) . achadinhosEvolutionContaTipoSufixo($c)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($contasEvolution)): ?>
                            <p class="mt-1 text-xs text-red-500">Cadastre contas em Configurações → <a href="configuracoes.php?tab=evolution" class="font-medium text-orange-600 hover:text-orange-700 underline">WhatsApp</a>.</p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">2. Como informar o grupo?</label>
                            <div class="grid gap-4 sm:grid-cols-1">
                                <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4">
                                    <p class="text-xs font-semibold text-gray-700 mb-1">Listar grupos desta conta</p>
                                    <p class="text-[11px] text-gray-500 mb-2">Evolution: fluxo completo na API (fetchAllGroups + findChats — pode demorar). Uazapi: listagem direta. O rótulo não é «rápido»: é a lista fiel ao painel.</p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" id="btn-buscar-grupos" class="bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium py-2 px-4 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                            Carregar lista na API
                                        </button>
                                        <button type="button" id="btn-limpar-cache-grupos" class="text-xs text-gray-600 hover:text-gray-900 underline decoration-dotted" title="Apagar cache local desta conta">Limpar cache local</button>
                                    </div>
                                    <span id="buscar-status" class="mt-2 block text-sm text-gray-500"></span>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4">
                                    <p class="text-xs font-semibold text-gray-700 mb-1">Inserir ID do grupo manualmente</p>
                                    <p class="text-xs text-gray-500 mb-2">Cole o JID do grupo ou do <strong>avisos da comunidade</strong> (ambos usam <code class="text-[11px] bg-white px-1 rounded border">…@g.us</code>). Útil quando a API não listou o chat ou você copiou o ID do WhatsApp.</p>
                                    <div class="flex flex-col sm:flex-row gap-2 sm:items-end">
                                        <div class="flex-1 min-w-0">
                                            <input type="text" id="manual-grupo-jid" maxlength="120" autocomplete="off"
                                                   placeholder="120363123456789012@g.us"
                                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-orange-500">
                                        </div>
                                        <button type="button" id="btn-usar-jid-manual" class="shrink-0 bg-white border border-orange-300 text-orange-700 hover:bg-orange-50 text-sm font-medium py-2 px-4 rounded-lg transition-colors">
                                            Incluir na seleção
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="lista-grupos-evolution" class="hidden">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-2">
                                <span class="text-sm font-medium text-gray-700 whitespace-nowrap">3. Marque um ou mais grupos</span>
                                <span id="resumo-selecao-grupos" class="text-xs text-orange-700 font-medium"></span>
                                <input type="search" id="filtro-grupo-lista" autocomplete="off" spellcheck="false"
                                       aria-label="Filtrar grupos por nome ou ID"
                                       placeholder="Pesquisar…"
                                       class="w-[min(100%,11rem)] sm:w-44 shrink-0 px-2 py-1 text-xs border border-gray-200 rounded-md focus:ring-1 focus:ring-orange-500 focus:border-orange-400">
                                <label class="sr-only" for="filtro-grupo-tipo">Filtrar por tipo de chat</label>
                                <select id="filtro-grupo-tipo" aria-label="Filtrar por tipo de chat"
                                        class="shrink-0 max-w-[11rem] sm:max-w-[13rem] px-2 py-1 text-xs border border-gray-200 rounded-md focus:ring-1 focus:ring-orange-500 focus:border-orange-400 bg-white">
                                    <option value="todos">Todos os tipos</option>
                                    <option value="grupo">Só grupos comuns</option>
                                    <option value="comunidade_avisos">Avisos da comunidade</option>
                                    <option value="comunidade">Grupo vinculado (comunidade)</option>
                                    <option value="comunidade_qualquer">Comunidade (avisos ou vinculado)</option>
                                </select>
                                <button type="button" id="btn-marcar-visiveis" class="text-xs text-orange-600 hover:text-orange-800 font-medium">Marcar visíveis</button>
                                <button type="button" id="btn-desmarcar-todos" class="text-xs text-gray-600 hover:text-gray-900">Desmarcar todos</button>
                            </div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden max-h-[min(70vh,560px)] overflow-y-auto">
                                <table class="w-full text-sm table-fixed">
                                    <thead class="bg-gray-50 sticky top-0 z-10 shadow-sm">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 w-9">
                                                <input type="checkbox" id="chk-grupo-master" class="text-orange-500 rounded" title="Marcar/desmarcar visíveis na lista" aria-label="Selecionar todos visíveis">
                                            </th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 w-[32%]">Nome</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 w-24">Tipo</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">ID</th>
                                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 w-12">Nº</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-grupos-evolution" class="divide-y divide-gray-100"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-5">
                <form id="form-novo-grupo-bulk" method="POST" action="?tab=adicionar" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4 xl:sticky xl:top-4">
                            <p class="text-sm font-bold text-gray-800">4. Lojas e opções</p>
                            <input type="hidden" name="novo_grupo_bulk" value="1">
                            <input type="hidden" name="evolution_conta_id" id="novo-evolution-conta-id" value="">
                            <div id="bulk-hidden-fields"></div>
                            <div>
                                <span class="block text-sm font-medium text-gray-700 mb-2">Publicar ofertas destas lojas neste(s) grupo(s) *</span>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php
                                    $lojasOcultasAdicionar = gruposAutomacaoLojaOcultasFormularioBulk();
                                    foreach (gruposAutomacaoLojaOpcoes() as $k => $lb):
                                        if (in_array($k, $lojasOcultasAdicionar, true)) {
                                            continue;
                                        }
                                        ?>
                                    <label class="flex items-center gap-2 cursor-pointer text-sm rounded-lg border border-gray-100 bg-gray-50/80 px-3 py-2 hover:bg-gray-50">
                                        <input type="checkbox" name="automacao_lojas[]" value="<?php echo htmlspecialchars($k); ?>" class="chk-loja-post rounded text-orange-500" data-loja="<?php echo htmlspecialchars($k); ?>">
                                        <span><?php echo htmlspecialchars($lb); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Cada loja marcada gera <strong>uma regra</strong> (uma linha) por destino selecionado — ex.: 2 grupos × 3 lojas = 6 linhas. Combinação grupo×loja já existente: não duplica; apenas re-sincroniza o job na cron-job.org.</p>
                            </div>
                            <div class="rounded-xl border border-orange-100 bg-orange-50/40 p-4 space-y-4">
                                <p class="text-sm font-semibold text-gray-800">Filtros de categoria / ofertas por loja</p>
                                <p class="text-xs text-gray-600 -mt-2">Marque cada loja acima para exibir os campos correspondentes. Os valores aplicam-se às regras criadas para essas lojas neste envio.</p>
                                <div id="cfg-novo-ml" class="hidden">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Mercado Livre e ML cupons — categoria nas ofertas</label>
                                    <select name="ml_ofertas_categoria" class="w-full max-w-xl px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 bg-white">
                                        <?php foreach (mercadolivreOfertasCategoriasBrasilLista() as $mid => $mlabel): ?>
                                        <option value="<?php echo htmlspecialchars($mid); ?>"><?php echo htmlspecialchars($mlabel . ($mid !== '' ? ' — ' . $mid : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro código MLB (opcional)</label>
                                    <input type="text" name="ml_ofertas_categoria_custom" maxlength="32" autocomplete="off" placeholder="Sobrescreve a lista se preenchido"
                                           class="w-full max-w-md px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-orange-500 bg-white">
                                    <p class="text-xs text-gray-500 mt-1">Usado nas regras <strong>Mercado Livre</strong>. Para <strong>ML cupons</strong> a automação segue a página de cupons; estes campos podem ficar em branco.</p>
                                </div>
                                <div id="cfg-novo-shopee" class="hidden">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Shopee — categoria nas ofertas</label>
                                    <select name="shopee_ofertas_categoria" class="w-full max-w-xl px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 bg-white">
                                        <?php foreach (shopeeOfertasCategoriasBrasilLista() as $sid => $slabel): ?>
                                        <option value="<?php echo htmlspecialchars($sid); ?>"><?php echo htmlspecialchars($slabel . ($sid !== '' ? ' — ' . $sid : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro ID numérico (opcional)</label>
                                    <input type="text" name="shopee_ofertas_categoria_custom" maxlength="32" autocomplete="off" placeholder="Sobrescreve a lista se preenchido"
                                           class="w-full max-w-md px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-orange-500 bg-white">
                                    <p class="text-xs text-gray-500 mt-1">O ID vira <code class="text-[11px] bg-white px-1 rounded border">keyword</code> em <code class="text-[11px] bg-white px-1 rounded border">productOfferV2</code> (ver <code class="text-[11px] bg-white px-1 rounded border">config/shopee-ofertas-categorias-brasil.php</code>).</p>
                                </div>
                                <div id="cfg-novo-amazon" class="hidden">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Amazon — departamento (PA-API / Browse Node)</label>
                                    <select name="amazon_ofertas_categoria" class="w-full max-w-xl px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 bg-white">
                                        <?php foreach (amazonOfertasBrowseNodesBrasilLista() as $nodeId => $azLabel): ?>
                                        <option value="<?php echo htmlspecialchars((string) $nodeId); ?>"><?php echo htmlspecialchars($azLabel . ($nodeId !== '' ? ' — ' . $nodeId : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="block text-xs font-medium text-gray-500 mt-2 mb-0.5">Outro Browse Node (opcional, só dígitos)</label>
                                    <input type="text" name="amazon_ofertas_categoria_custom" maxlength="20" pattern="[0-9]*" inputmode="numeric" autocomplete="off" placeholder="Sobrescreve a lista se preenchido"
                                           class="w-full max-w-md px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-orange-500 bg-white">
                                    <p class="text-xs text-gray-500 mt-1">Associate Tag vem da configuração em <a href="amazon.php" class="text-orange-600 underline">Amazon</a>.</p>
                                </div>
                                <div id="cfg-novo-aliexpress" class="hidden">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">AliExpress — categoria (API de afiliados)</label>
                                    <select name="aliexpress_affiliate_category_id" class="w-full max-w-2xl px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 bg-white">
                                        <option value="0" selected>Todas as categorias (busca geral + produto aleatório)</option>
                                        <?php foreach ($aliexpress_categorias_form as $acer): ?>
                                        <option value="<?php echo (int) $acer['id']; ?>"><?php echo htmlspecialchars($acer['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($aliexpress_categorias_err !== '' && $aliexpress_categorias_form === []): ?>
                                    <p class="text-xs text-red-600 mt-1"><?php echo htmlspecialchars($aliexpress_categorias_err); ?> Configure App Key e App Secret em <a href="aliexpress.php" class="text-orange-600 underline">AliExpress</a> e recarregue esta página.</p>
                                    <?php else: ?>
                                    <p class="text-xs text-gray-500 mt-1">«Todas» usa busca ampla na API. Com chaves inválidas a lista pode vir vazia.</p>
                                    <?php endif; ?>
                                </div>
                                <div id="cfg-novo-magalu" class="hidden">
                                    <p class="text-sm font-medium text-gray-800 mb-1">Magazine Luiza (catálogo)</p>
                                    <p class="text-xs text-gray-600">Não há filtro de categoria por grupo neste cadastro: ofertas e palavras-chave vêm das <a href="magalu.php" class="text-orange-600 font-medium underline">configurações da loja Magalu</a> (Lomadee, prompt OpenAI, etc.).</p>
                                </div>
                                <div id="cfg-novo-shein" class="hidden">
                                    <p class="text-sm font-medium text-gray-800 mb-1">Shein</p>
                                    <p class="text-xs text-gray-600">Não há filtro de categoria por grupo: parâmetros da automação estão na <a href="shein.php" class="text-orange-600 font-medium underline">página Shein</a> (API affiliate, OpenAI, quantidade por execução).</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Início das postagens</label>
                                    <input type="time" name="post_hora_inicio" step="60" class="w-full max-w-[160px] px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Fim das postagens</label>
                                    <input type="time" name="post_hora_fim" step="60" class="w-full max-w-[160px] px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500">
                                    <p class="text-xs text-gray-500 mt-0.5">Vazio nos dois = 24h.</p>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Intervalo entre envios (minutos)</label>
                                <input type="number" name="intervalo_minutos" min="1" max="1440" placeholder="Padrão da loja (vazio)"
                                       class="w-full max-w-[140px] px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-500">
                                <p class="text-xs text-gray-500 mt-0.5">Ex: 20 = 1 produto a cada 20 min neste grupo. Vazio = padrão da loja (Amazon: delay global nas configs).</p>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <input type="checkbox" name="ativo" value="1" checked class="rounded text-orange-500">
                                    Grupo ativo
                                </label>
                            </div>
                            <div class="flex gap-3 flex-wrap">
                                <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-semibold py-2 px-5 rounded-lg text-sm">Cadastrar seleção</button>
                                <button type="button" id="btn-cancelar-novo" class="text-gray-500 hover:text-gray-700 text-sm">Limpar seleção de grupos</button>
                            </div>
                </form>
            </div>
            </div>
            <?php endif; ?>
        </main>

        <script>
        (function() {
            var btnBuscar = document.getElementById('btn-buscar-grupos');
            var btnLimparCache = document.getElementById('btn-limpar-cache-grupos');
            var contaSelect = document.getElementById('conta-evolution');
            var statusEl = document.getElementById('buscar-status');
            var listaDiv = document.getElementById('lista-grupos-evolution');
            var tbody = document.getElementById('tbody-grupos-evolution');
            var formBulk = document.getElementById('form-novo-grupo-bulk');
            var btnCancelar = document.getElementById('btn-cancelar-novo');
            var novoEvolutionContaId = document.getElementById('novo-evolution-conta-id');
            var manualJidInput = document.getElementById('manual-grupo-jid');
            var btnUsarJidManual = document.getElementById('btn-usar-jid-manual');
            var filtroGrupoLista = document.getElementById('filtro-grupo-lista');
            var filtroGrupoTipo = document.getElementById('filtro-grupo-tipo');
            var resumoSel = document.getElementById('resumo-selecao-grupos');
            var chkMaster = document.getElementById('chk-grupo-master');
            var btnMarcarVisiveis = document.getElementById('btn-marcar-visiveis');
            var btnDesmarcarTodos = document.getElementById('btn-desmarcar-todos');
            var bulkHidden = document.getElementById('bulk-hidden-fields');
            var gruposData = [];
            var CACHE_PREFIX = 'ach_ev_grupos_full_v1_';
            var CACHE_TTL_MS = 15 * 60 * 1000;
            var FETCH_MS = 180000;

            function htmlEsc(s) {
                if (s == null || s === '') return '';
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function destinoNormalizado(g) {
                if (!g) return 'grupo';
                var d = String(g.destino != null ? g.destino : '').trim();
                if (d === 'comunidade_avisos' || d === 'comunidade') return d;
                return 'grupo';
            }

            function passaFiltroTipoChat(g, tipoSel) {
                var t = tipoSel || 'todos';
                if (t === 'todos') return true;
                var d = destinoNormalizado(g);
                if (t === 'grupo') return d === 'grupo';
                if (t === 'comunidade_avisos') return d === 'comunidade_avisos';
                if (t === 'comunidade') return d === 'comunidade';
                if (t === 'comunidade_qualquer') return d === 'comunidade_avisos' || d === 'comunidade';
                return true;
            }

            function nomeExibicaoGrupo(g) {
                var n = g.subject != null ? String(g.subject) : '';
                if ((!n || !n.trim()) && g.destino === 'comunidade_avisos') {
                    n = 'Avisos da comunidade';
                } else if ((!n || !n.trim()) && g.destino === 'comunidade') {
                    n = 'Grupo na comunidade';
                }
                if (!n || !n.trim()) {
                    n = 'Grupo WhatsApp';
                }
                return n;
            }

            function cacheKey(contaId) {
                return CACHE_PREFIX + contaId;
            }

            function cacheRead(contaId) {
                try {
                    var raw = sessionStorage.getItem(cacheKey(contaId));
                    if (!raw) return null;
                    var o = JSON.parse(raw);
                    if (!o || !o.t || !Array.isArray(o.grupos)) return null;
                    if (Date.now() - o.t > CACHE_TTL_MS) {
                        sessionStorage.removeItem(cacheKey(contaId));
                        return null;
                    }
                    return o.grupos;
                } catch (e) {
                    return null;
                }
            }

            function cacheWrite(contaId, grupos) {
                try {
                    sessionStorage.setItem(cacheKey(contaId), JSON.stringify({ t: Date.now(), grupos: grupos }));
                } catch (e) { /* ignore quota */ }
            }

            function syncContaHidden() {
                if (novoEvolutionContaId && contaSelect) {
                    novoEvolutionContaId.value = contaSelect.value || '';
                }
            }

            function atualizarResumoSelecao() {
                if (!resumoSel || !tbody) return;
                var n = tbody.querySelectorAll('input.grupo-evo-cb:checked').length;
                resumoSel.textContent = n ? n + ' selecionado(s)' : '';
            }

            function syncChkMaster() {
                if (!chkMaster || !tbody) return;
                var vis = tbody.querySelectorAll('tr[data-grupo-index]:not(.hidden) input.grupo-evo-cb');
                if (vis.length === 0) {
                    chkMaster.checked = false;
                    chkMaster.indeterminate = false;
                    return;
                }
                var checked = 0;
                vis.forEach(function (inp) {
                    if (inp.checked) checked++;
                });
                chkMaster.checked = checked === vis.length;
                chkMaster.indeterminate = checked > 0 && checked < vis.length;
            }

            function renderTabelaGrupos(fromCache) {
                if (!tbody || !listaDiv || !statusEl) return;
                tbody.innerHTML = '';
                if (gruposData.length === 0) {
                    statusEl.textContent = 'Nenhum grupo encontrado nesta conta.';
                    listaDiv.classList.remove('hidden');
                    atualizarResumoSelecao();
                    syncChkMaster();
                    return;
                }
                var suf = fromCache ? ' (cache local — clique de novo na conta ou em «Carregar» para atualizar na API).' : '.';
                statusEl.textContent = gruposData.length + ' chat(s) na lista' + suf;
                gruposData.forEach(function (g, i) {
                    var tr = document.createElement('tr');
                    tr.className = 'hover:bg-gray-50 cursor-pointer';
                    tr.setAttribute('data-grupo-index', String(i));
                    tr.setAttribute('data-grupo-destino', destinoNormalizado(g));
                    var tipoHtml;
                    if (g.destino === 'comunidade_avisos') {
                        tipoHtml = '<span class="inline-flex flex-col leading-tight"><span class="text-[10px] font-semibold text-violet-800">Comunidade</span><span class="text-[9px] text-violet-600">Avisos</span></span>';
                    } else if (g.destino === 'comunidade') {
                        tipoHtml = '<span class="inline-flex flex-col leading-tight"><span class="text-[10px] font-semibold text-violet-800">Comunidade</span><span class="text-[9px] text-violet-600">Grupo vinculado</span></span>';
                    } else {
                        tipoHtml = '<span class="text-xs text-gray-700">Grupo</span>';
                    }
                    var subj = htmlEsc(g.subject || '') || '(sem nome)';
                    var gid = htmlEsc(g.id || '');
                    tr.innerHTML =
                        '<td class="px-2 py-1.5 align-middle"><input type="checkbox" class="grupo-evo-cb text-orange-500 rounded" data-idx="' + i + '"></td>' +
                        '<td class="px-2 py-1.5 font-medium text-xs leading-snug break-words">' + subj + '</td>' +
                        '<td class="px-2 py-1.5">' + tipoHtml + '</td>' +
                        '<td class="px-2 py-1.5 text-gray-500 font-mono text-[11px] break-all" title="' + gid + '">' + gid + '</td>' +
                        '<td class="px-2 py-1.5 text-center text-xs">' + (g.size || 0) + '</td>';
                    tr.addEventListener('click', function (ev) {
                        if (ev.target.closest('input[type="checkbox"]')) return;
                        var cb = tr.querySelector('input.grupo-evo-cb');
                        if (cb) {
                            cb.checked = !cb.checked;
                            cb.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                    tbody.appendChild(tr);
                });
                tbody.querySelectorAll('input.grupo-evo-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        atualizarResumoSelecao();
                        syncChkMaster();
                    });
                });
                aplicarFiltroListaGrupos();
                listaDiv.classList.remove('hidden');
                atualizarResumoSelecao();
                syncChkMaster();
            }

            function aplicarFiltroListaGrupos() {
                if (!tbody) return;
                var q = (filtroGrupoLista && filtroGrupoLista.value ? filtroGrupoLista.value : '').trim().toLowerCase();
                var tipoSel = (filtroGrupoTipo && filtroGrupoTipo.value) ? filtroGrupoTipo.value : 'todos';
                var rows = tbody.querySelectorAll('tr[data-grupo-index]');
                rows.forEach(function (tr) {
                    var i = parseInt(tr.getAttribute('data-grupo-index'), 10);
                    var g = gruposData[i];
                    if (!g) {
                        tr.classList.add('hidden');
                        return;
                    }
                    if (!passaFiltroTipoChat(g, tipoSel)) {
                        tr.classList.add('hidden');
                        return;
                    }
                    if (q === '') {
                        tr.classList.remove('hidden');
                        return;
                    }
                    var subj = String(g.subject || '').toLowerCase();
                    var gid = String(g.id || '').toLowerCase();
                    var dest = String(g.destino || '').toLowerCase();
                    var extra = g.destino === 'comunidade_avisos' ? 'comunidade avisos' : (g.destino === 'comunidade' ? 'comunidade subgrupo grupo vinculado' : 'grupo');
                    var hay = subj + ' ' + gid + ' ' + dest + ' ' + extra;
                    var match = hay.indexOf(q) !== -1;
                    tr.classList.toggle('hidden', !match);
                });
                syncChkMaster();
            }

            function jidJaNaLista(jidNorm) {
                if (!tbody) return false;
                var j = String(jidNorm).toLowerCase();
                var rows = tbody.querySelectorAll('tr[data-grupo-index]');
                for (var r = 0; r < rows.length; r++) {
                    var ix = parseInt(rows[r].getAttribute('data-grupo-index'), 10);
                    var g = gruposData[ix];
                    if (g && String(g.id || '').toLowerCase() === j) return true;
                }
                return false;
            }

            function aplicarJidManual() {
                if (!manualJidInput || !contaSelect) return;
                if (!contaSelect.value) {
                    if (statusEl) statusEl.textContent = 'Primeiro selecione a conta WhatsApp (passo 1).';
                    return;
                }
                var jid = (manualJidInput.value || '').trim();
                if (jid.length < 8 || jid.indexOf('@') < 0) {
                    if (statusEl) statusEl.textContent = 'Informe um ID válido (deve conter @, ex.: …@g.us).';
                    return;
                }
                if (jidJaNaLista(jid)) {
                    if (statusEl) statusEl.textContent = 'Este JID já está na lista — marque o checkbox correspondente.';
                    return;
                }
                var novo = { id: jid, subject: '', destino: '', size: 0 };
                gruposData.push(novo);
                var i = gruposData.length - 1;
                if (!tbody || !listaDiv) return;
                var tr = document.createElement('tr');
                tr.className = 'hover:bg-amber-50/80 cursor-pointer';
                tr.setAttribute('data-grupo-index', String(i));
                tr.setAttribute('data-grupo-destino', 'grupo');
                var subj = htmlEsc(nomeExibicaoGrupo(novo));
                var gid = htmlEsc(jid);
                tr.innerHTML =
                    '<td class="px-2 py-1.5 align-middle"><input type="checkbox" class="grupo-evo-cb text-orange-500 rounded" data-idx="' + i + '" checked></td>' +
                    '<td class="px-2 py-1.5 font-medium text-xs leading-snug break-words">' + subj + ' <span class="text-[10px] text-amber-700 font-normal">(manual)</span></td>' +
                    '<td class="px-2 py-1.5"><span class="text-xs text-gray-600">Manual</span></td>' +
                    '<td class="px-2 py-1.5 text-gray-500 font-mono text-[11px] break-all" title="' + gid + '">' + gid + '</td>' +
                    '<td class="px-2 py-1.5 text-center text-xs">—</td>';
                tr.addEventListener('click', function (ev) {
                    if (ev.target.closest('input[type="checkbox"]')) return;
                    var cb = tr.querySelector('input.grupo-evo-cb');
                    if (cb) {
                        cb.checked = !cb.checked;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                var cb = tr.querySelector('input.grupo-evo-cb');
                cb.addEventListener('change', function () {
                    atualizarResumoSelecao();
                    syncChkMaster();
                });
                tbody.appendChild(tr);
                listaDiv.classList.remove('hidden');
                aplicarFiltroListaGrupos();
                if (statusEl) statusEl.textContent = 'JID adicionado à lista. Marque outros grupos se quiser e cadastre à direita.';
                manualJidInput.value = '';
                atualizarResumoSelecao();
                syncChkMaster();
            }

            if (btnUsarJidManual && manualJidInput && contaSelect) {
                btnUsarJidManual.addEventListener('click', aplicarJidManual);
                manualJidInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        aplicarJidManual();
                    }
                });
            }

            function tentarMostrarCache() {
                if (!contaSelect || !listaDiv || !tbody) return;
                var contaId = contaSelect.value;
                if (!contaId) return;
                var cached = cacheRead(contaId);
                if (!cached || cached.length === 0) return;
                gruposData = cached;
                if (filtroGrupoLista) filtroGrupoLista.value = '';
                if (filtroGrupoTipo) filtroGrupoTipo.value = 'todos';
                renderTabelaGrupos(true);
            }

            function executarBuscaGruposPainel(opts) {
                opts = opts || {};
                var forceNetwork = !!opts.forceNetwork;
                if (!contaSelect || !listaDiv || !tbody) return;
                var contaId = contaSelect.value;
                if (!contaId) {
                    if (statusEl) statusEl.textContent = 'Selecione uma conta.';
                    return;
                }
                if (!forceNetwork) {
                    var cached = cacheRead(contaId);
                    if (cached && cached.length > 0) {
                        gruposData = cached;
                        if (filtroGrupoLista) filtroGrupoLista.value = '';
                        renderTabelaGrupos(true);
                        return;
                    }
                }
                if (btnBuscar) btnBuscar.disabled = true;
                if (statusEl) statusEl.textContent = 'Carregando lista completa (Evolution: todos os grupos + tipo comunidade)…';
                gruposData = [];
                tbody.innerHTML = '';
                if (filtroGrupoLista) filtroGrupoLista.value = '';
                if (filtroGrupoTipo) filtroGrupoTipo.value = 'todos';
                var ac = new AbortController();
                var to = setTimeout(function () {
                    ac.abort();
                }, FETCH_MS);
                var url = 'grupos.php?action=fetch_grupos_evolution&conta_id=' + encodeURIComponent(contaId);
                fetch(url, { signal: ac.signal, credentials: 'same-origin' })
                    .then(function (r) {
                        var ct = (r.headers.get('content-type') || '').toLowerCase();
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status);
                        }
                        if (ct.indexOf('application/json') === -1) {
                            throw new Error('invalid_json');
                        }
                        return r.json();
                    })
                    .then(function (data) {
                        if (!statusEl) return;
                        if (data.success) {
                            gruposData = data.grupos || [];
                            cacheWrite(contaId, gruposData);
                            renderTabelaGrupos(false);
                        } else {
                            statusEl.textContent = data.message || 'Erro ao buscar.';
                        }
                    })
                    .catch(function (err) {
                        if (!statusEl) return;
                        if (err && err.name === 'AbortError') {
                            statusEl.textContent = 'Tempo esgotado. Tente de novo ou verifique a Evolution.';
                        } else {
                            statusEl.textContent = 'Erro de conexão ou resposta inválida do servidor.';
                        }
                    })
                    .finally(function () {
                        clearTimeout(to);
                        if (btnBuscar) btnBuscar.disabled = false;
                    });
            }

            if (btnBuscar && contaSelect && listaDiv && tbody) {
                btnBuscar.addEventListener('click', function () {
                    executarBuscaGruposPainel({ forceNetwork: true });
                });
            }

            if (btnLimparCache && contaSelect) {
                btnLimparCache.addEventListener('click', function () {
                    var id = contaSelect.value;
                    if (!id) {
                        if (statusEl) statusEl.textContent = 'Selecione uma conta para limpar o cache.';
                        return;
                    }
                    try {
                        sessionStorage.removeItem(cacheKey(id));
                    } catch (e) { /* ignore */ }
                    if (statusEl) statusEl.textContent = 'Cache local desta conta removido.';
                });
            }

            if (contaSelect) {
                contaSelect.addEventListener('change', function () {
                    syncContaHidden();
                    gruposData = [];
                    if (tbody) tbody.innerHTML = '';
                    if (listaDiv) listaDiv.classList.add('hidden');
                    if (filtroGrupoLista) filtroGrupoLista.value = '';
                    if (filtroGrupoTipo) filtroGrupoTipo.value = 'todos';
                    if (resumoSel) resumoSel.textContent = '';
                    if (statusEl) statusEl.textContent = '';
                    tentarMostrarCache();
                });
                syncContaHidden();
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', tentarMostrarCache);
                } else {
                    tentarMostrarCache();
                }
            }

            if (chkMaster && tbody) {
                chkMaster.addEventListener('change', function () {
                    var on = chkMaster.checked;
                    tbody.querySelectorAll('tr[data-grupo-index]:not(.hidden) input.grupo-evo-cb').forEach(function (inp) {
                        inp.checked = on;
                    });
                    atualizarResumoSelecao();
                    syncChkMaster();
                });
            }

            if (btnMarcarVisiveis && tbody) {
                btnMarcarVisiveis.addEventListener('click', function () {
                    tbody.querySelectorAll('tr[data-grupo-index]:not(.hidden) input.grupo-evo-cb').forEach(function (inp) {
                        inp.checked = true;
                    });
                    atualizarResumoSelecao();
                    syncChkMaster();
                });
            }

            if (btnDesmarcarTodos && tbody) {
                btnDesmarcarTodos.addEventListener('click', function () {
                    tbody.querySelectorAll('input.grupo-evo-cb').forEach(function (inp) {
                        inp.checked = false;
                    });
                    atualizarResumoSelecao();
                    syncChkMaster();
                });
            }

            if (filtroGrupoLista) {
                filtroGrupoLista.addEventListener('input', aplicarFiltroListaGrupos);
                filtroGrupoLista.addEventListener('search', aplicarFiltroListaGrupos);
            }
            if (filtroGrupoTipo) {
                filtroGrupoTipo.addEventListener('change', aplicarFiltroListaGrupos);
            }

            if (formBulk && bulkHidden && tbody && contaSelect) {
                formBulk.addEventListener('submit', function (ev) {
                    syncContaHidden();
                    if (!contaSelect.value) {
                        ev.preventDefault();
                        alert('Selecione a conta WhatsApp (passo 1).');
                        return;
                    }
                    var lojas = formBulk.querySelectorAll('input[name="automacao_lojas[]"]:checked');
                    if (!lojas.length) {
                        ev.preventDefault();
                        alert('Marque ao menos uma loja à direita.');
                        return;
                    }
                    var selecionados = [];
                    tbody.querySelectorAll('input.grupo-evo-cb:checked').forEach(function (cb) {
                        var ix = parseInt(cb.getAttribute('data-idx'), 10);
                        var g = gruposData[ix];
                        if (g && g.id) {
                            selecionados.push({ id: String(g.id).trim(), nome: nomeExibicaoGrupo(g) });
                        }
                    });
                    if (!selecionados.length) {
                        ev.preventDefault();
                        alert('Marque um ou mais grupos na lista (ou inclua um JID manual).');
                        return;
                    }
                    bulkHidden.innerHTML = '';
                    selecionados.forEach(function (p) {
                        var i1 = document.createElement('input');
                        i1.type = 'hidden';
                        i1.name = 'bulk_grupo_id[]';
                        i1.value = p.id;
                        bulkHidden.appendChild(i1);
                        var i2 = document.createElement('input');
                        i2.type = 'hidden';
                        i2.name = 'bulk_grupo_nome[]';
                        i2.value = p.nome;
                        bulkHidden.appendChild(i2);
                    });
                });
            }

            if (btnCancelar && tbody) {
                btnCancelar.addEventListener('click', function () {
                    tbody.querySelectorAll('input.grupo-evo-cb').forEach(function (inp) {
                        inp.checked = false;
                    });
                    if (manualJidInput) manualJidInput.value = '';
                    atualizarResumoSelecao();
                    syncChkMaster();
                });
            }
        })();

        function visGrupoLojaNovo() {
            function marcada(loja) {
                var el = document.querySelector('.chk-loja-post[data-loja="' + loja + '"]');
                return el && el.checked;
            }
            var ml = document.getElementById('cfg-novo-ml');
            var sh = document.getElementById('cfg-novo-shopee');
            var ae = document.getElementById('cfg-novo-aliexpress');
            var az = document.getElementById('cfg-novo-amazon');
            var mg = document.getElementById('cfg-novo-magalu');
            var shn = document.getElementById('cfg-novo-shein');
            if (ml) ml.classList.toggle('hidden', !marcada('ml') && !marcada('ml_cupons'));
            if (sh) sh.classList.toggle('hidden', !marcada('shopee'));
            if (ae) ae.classList.toggle('hidden', !marcada('aliexpress'));
            if (az) az.classList.toggle('hidden', !marcada('amazon'));
            if (mg) mg.classList.toggle('hidden', !marcada('magalu'));
            if (shn) shn.classList.toggle('hidden', !marcada('shein'));
        }
        function visGrupoLojaEdit() {
            var sel = document.getElementById('edit-automacao-loja');
            if (!sel) return;
            var v = sel.value;
            var ml = document.getElementById('cfg-edit-ml');
            var sh = document.getElementById('cfg-edit-shopee');
            var ae = document.getElementById('cfg-edit-aliexpress');
            var az = document.getElementById('cfg-edit-amazon');
            var mg = document.getElementById('cfg-edit-magalu');
            var shn = document.getElementById('cfg-edit-shein');
            if (ml) ml.classList.toggle('hidden', v !== 'ml' && v !== 'ml_cupons');
            if (sh) sh.classList.toggle('hidden', v !== 'shopee');
            if (ae) ae.classList.toggle('hidden', v !== 'aliexpress');
            if (az) az.classList.toggle('hidden', v !== 'amazon');
            if (mg) mg.classList.toggle('hidden', v !== 'magalu');
            if (shn) shn.classList.toggle('hidden', v !== 'shein');
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.chk-loja-post').forEach(function (cb) {
                cb.addEventListener('change', visGrupoLojaNovo);
            });
            visGrupoLojaNovo();
            var e = document.getElementById('edit-automacao-loja');
            if (e) {
                e.addEventListener('change', visGrupoLojaEdit);
                visGrupoLojaEdit();
            }
        });

        (function () {
            var msgEl = document.getElementById('grupo-envio-teste-msg');
            var body = document.body;
            var FETCH_MS = 360000;
            document.addEventListener('click', function (ev) {
                var t = ev.target;
                var btn = t && t.closest ? t.closest('.btn-enviar-teste-grupo') : null;
                if (!btn) return;
                ev.preventDefault();
                var id = btn.getAttribute('data-grupo-id');
                var token = body.getAttribute('data-admin-autosave-token') || '';
                if (!id || !token) return;
                btn.disabled = true;
                var orig = btn.textContent;
                btn.textContent = '…';
                var ac = new AbortController();
                var to = setTimeout(function () {
                    ac.abort();
                }, FETCH_MS);
                if (msgEl) {
                    msgEl.classList.remove('hidden');
                    msgEl.className = 'mb-4 p-3 rounded-lg text-sm bg-slate-100 text-slate-800';
                    msgEl.textContent =
                        'Processando envio de teste (automação pode levar vários minutos; aguarde até ~6 min).';
                }
                fetch('api/grupo-enviar-teste.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: ac.signal,
                    headers: { 'Content-Type': 'application/json', 'X-Autosave-Token': token },
                    body: JSON.stringify({ token: token, grupo_id: parseInt(id, 10) })
                })
                    .then(function (r) {
                        return r.text().then(function (text) {
                            var j = null;
                            try {
                                j = text ? JSON.parse(text) : null;
                            } catch (e) {
                                j = null;
                            }
                            return { ok: r.ok, status: r.status, j: j, text: text };
                        });
                    })
                    .then(function (res) {
                        if (!msgEl) return;
                        msgEl.classList.remove('hidden');
                        var j = res.j;
                        if (!j || typeof j !== 'object') {
                            msgEl.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 text-red-800';
                            msgEl.textContent =
                                'Resposta inválida do servidor (HTTP ' +
                                res.status +
                                '). Verifique o log do PHP ou aumente max_execution_time.';
                            return;
                        }
                        var s = !!j.success;
                        msgEl.className =
                            'mb-4 p-3 rounded-lg text-sm ' + (s ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800');
                        var m = j.message || (s ? 'Envio concluído.' : 'Falha no envio.');
                        var err = j.errors && j.errors.length ? ' ' + j.errors.join('; ') : '';
                        msgEl.textContent = m + err;
                    })
                    .catch(function (e) {
                        if (!msgEl) return;
                        msgEl.classList.remove('hidden');
                        msgEl.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 text-red-800';
                        if (e && e.name === 'AbortError') {
                            msgEl.textContent =
                                'Tempo esgotado (~6 min). O servidor pode ainda estar processando; verifique se o produto foi publicado ou tente de novo.';
                        } else {
                            msgEl.textContent =
                                e && e.message ? 'Erro: ' + e.message : 'Erro de rede ao enviar teste.';
                        }
                    })
                    .finally(function () {
                        clearTimeout(to);
                        btn.disabled = false;
                        btn.textContent = orig;
                    });
            });
        })();
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
