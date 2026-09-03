<?php

/**
 * Garante colunas mínimas sem depender de migration manual (cron / compatibilidade).
 */

function colunaExiste(string $tabela, string $coluna): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :tabela
          AND COLUMN_NAME = :coluna'
    );
    $stmt->execute([
        'tabela' => $tabela,
        'coluna' => $coluna,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Coluna usada pelo painel e por rodar-loja.php (migration: add_automacoes_cron_individual_ativo.sql).
 */
function garantirColunaAutomacoesCron(): void {
    if (colunaExiste('automacoes_cron', 'cron_individual_ativo')) {
        return;
    }

    $pdo = getDB();
    $sql = 'ALTER TABLE automacoes_cron ADD COLUMN cron_individual_ativo TINYINT(1) NOT NULL DEFAULT 0';

    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        // não quebrar sistema (permissão, tabela inexistente, etc.)
    }
}

/**
 * Histórico de execuções dos crons (monitoramento; criada sob demanda em instalações antigas).
 */
function garantirTabelaCronExecucoes(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = getDB();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cron_execucoes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo ENUM(\'global\',\'loja\') NOT NULL,
                loja VARCHAR(32) NULL DEFAULT NULL,
                status ENUM(\'sucesso\',\'erro\') NOT NULL,
                mensagem TEXT NULL,
                tempo_execucao INT UNSIGNED NOT NULL DEFAULT 0,
                data_execucao DATETIME NOT NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tipo_loja_data (tipo, loja, data_execucao),
                KEY idx_data_exec (data_execucao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Exception $e) {
        error_log('garantirTabelaCronExecucoes: ' . $e->getMessage());
    }
}

/**
 * JSON opcional por execução (trace de decisões, resumo por loja) — monitoramento em produção.
 */
function garantirColunaCronExecucoesDetalhesJson(): void {
    if (!colunaExiste('cron_execucoes', 'detalhes_json')) {
        try {
            $pdo = getDB();
            $pdo->exec(
                'ALTER TABLE cron_execucoes ADD COLUMN detalhes_json LONGTEXT NULL DEFAULT NULL AFTER mensagem'
            );
        } catch (Exception $e) {
            error_log('garantirColunaCronExecucoesDetalhesJson: ' . $e->getMessage());
        }
    }
}

/**
 * Alinha ENUMs com instalações via migration completa: tipo inclui «grupo», status inclui «pulado».
 * Idempotente (verifica SHOW COLUMNS antes do ALTER).
 */
function garantirCronExecucoesTipoGrupoStatusPulado(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = getDB();
        $tbl = $pdo->query("SHOW TABLES LIKE 'cron_execucoes'")->fetch(PDO::FETCH_NUM);
        if (!$tbl) {
            return;
        }
        $colTipo = $pdo->query("SHOW COLUMNS FROM cron_execucoes WHERE Field = 'tipo'")->fetch(PDO::FETCH_ASSOC);
        if ($colTipo && is_string($colTipo['Type'] ?? null) && stripos($colTipo['Type'], 'grupo') === false) {
            $pdo->exec(
                "ALTER TABLE cron_execucoes MODIFY COLUMN tipo ENUM('global','loja','grupo') NOT NULL"
            );
        }
        $colSt = $pdo->query("SHOW COLUMNS FROM cron_execucoes WHERE Field = 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($colSt && is_string($colSt['Type'] ?? null) && stripos($colSt['Type'], 'pulado') === false) {
            $pdo->exec(
                "ALTER TABLE cron_execucoes MODIFY COLUMN status ENUM('sucesso','erro','pulado') NOT NULL"
            );
        }
    } catch (Exception $e) {
        error_log('garantirCronExecucoesTipoGrupoStatusPulado: ' . $e->getMessage());
    }
}

/** FK lógica a grupos_whatsapp.id quando tipo = grupo (facilita auditoria e relatórios). */
function garantirColunaCronExecucoesGrupoWhatsappId(): void {
    if (colunaExiste('cron_execucoes', 'grupo_whatsapp_id')) {
        return;
    }
    try {
        $pdo = getDB();
        $pdo->exec(
            'ALTER TABLE cron_execucoes ADD COLUMN grupo_whatsapp_id INT UNSIGNED NULL DEFAULT NULL ' .
            "COMMENT 'grupos_whatsapp.id quando tipo=grupo' AFTER loja"
        );
    } catch (Exception $e) {
        error_log('garantirColunaCronExecucoesGrupoWhatsappId: ' . $e->getMessage());
    }
}

/** Última tentativa de sync com cron-job.org por linha de grupo (observabilidade operacional). */
function garantirColunasGruposWhatsappCronOrgSyncAudit(): void {
    $defs = [
        'cron_org_sync_at' => "DATETIME NULL DEFAULT NULL COMMENT 'Última tentativa de sync cron-job.org'",
        'cron_org_sync_http_code' => 'SMALLINT NULL DEFAULT NULL',
        'cron_org_sync_ok' => "TINYINT(1) NULL DEFAULT NULL COMMENT '1=ok 0=falha'",
        'cron_org_sync_message' => 'VARCHAR(512) NULL DEFAULT NULL',
        'cron_org_sync_partial_no_job' => "TINYINT(1) NULL DEFAULT NULL COMMENT '1=HTTP ok sem job_id persistido'",
        'cron_org_sync_last_op' => "VARCHAR(32) NULL DEFAULT NULL COMMENT 'put,patch,reconcile,stale404,precheck,...'",
    ];
    foreach ($defs as $col => $sqlType) {
        if (colunaExiste('grupos_whatsapp', $col)) {
            continue;
        }
        try {
            $pdo = getDB();
            $pdo->exec('ALTER TABLE grupos_whatsapp ADD COLUMN `' . $col . '` ' . $sqlType);
        } catch (Exception $e) {
            error_log('garantirColunasGruposWhatsappCronOrgSyncAudit:' . $col . ' ' . $e->getMessage());
        }
    }
}

/**
 * Hierarquia de categorias (ex.: Moda → Masculina / Feminina / Infantil).
 */
function garantirColunaCategoriasParentId(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = getDB();
        $col = $pdo->query("SHOW COLUMNS FROM categorias LIKE 'parent_id'")->fetch();
        if ($col) {
            return;
        }
        $pdo->exec('ALTER TABLE categorias ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL COMMENT \'Categoria pai (opcional)\' AFTER slug');
        $pdo->exec('ALTER TABLE categorias ADD KEY idx_categorias_parent (parent_id)');
        try {
            $pdo->exec('ALTER TABLE categorias ADD CONSTRAINT fk_categorias_parent FOREIGN KEY (parent_id) REFERENCES categorias (id) ON DELETE SET NULL');
        } catch (Exception $e2) {
            // FK opcional (permissão ou versão antiga)
        }
    } catch (Exception $e) {
        error_log('garantirColunaCategoriasParentId: ' . $e->getMessage());
    }
}

/**
 * Intervalo opcional por grupo (painel Grupos + automações). Instalações antigas podem não ter a coluna.
 */
function garantirColunaGruposWhatsappIntervaloMinutos(): void {
    if (colunaExiste('grupos_whatsapp', 'intervalo_minutos')) {
        return;
    }

    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN intervalo_minutos INT NULL DEFAULT NULL ' .
            "COMMENT 'Minutos entre envios; NULL=uso padrão da loja' AFTER ativo"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappIntervaloMinutos: ' . $e->getMessage());
    }
}

/**
 * Filtro opcional na página de ofertas do ML (?category=MLB…) por grupo WhatsApp.
 */
function garantirColunaGruposWhatsappMlOfertasCategoria(): void {
    if (colunaExiste('grupos_whatsapp', 'ml_ofertas_categoria')) {
        return;
    }

    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN ml_ofertas_categoria VARCHAR(32) NULL DEFAULT NULL ' .
            "COMMENT 'Categoria MLB nas ofertas ML (ex. MLB1648); NULL=vazio=todas' AFTER intervalo_minutos"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappMlOfertasCategoria: ' . $e->getMessage());
    }
}

/**
 * Janela diária opcional para envio de posts ao grupo (hora início/fim, timezone do servidor).
 */
function garantirColunaGruposWhatsappPostHoras(): void {
    if (colunaExiste('grupos_whatsapp', 'post_hora_inicio')) {
        return;
    }

    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN post_hora_inicio TIME NULL DEFAULT NULL ' .
            "COMMENT 'Início da janela de postagens; NULL=sem limite' AFTER ml_ofertas_categoria"
        );
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN post_hora_fim TIME NULL DEFAULT NULL ' .
            "COMMENT 'Fim da janela; cruza meia-noite se inicio > fim' AFTER post_hora_inicio"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappPostHoras: ' . $e->getMessage());
    }
}

/**
 * Qual automação da loja este grupo recebe (ML, Shopee, etc.).
 */
function garantirColunaGruposWhatsappAutomacaoLoja(): void {
    if (colunaExiste('grupos_whatsapp', 'automacao_loja')) {
        return;
    }
    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN automacao_loja VARCHAR(32) NOT NULL DEFAULT \'ml\' ' .
            "COMMENT 'ml, shopee, magalu, amazon, aliexpress, shein'"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappAutomacaoLoja: ' . $e->getMessage());
    }
}

/**
 * Chave numérica → rótulo usado como keyword em productOfferV2; vazio = todas.
 */
function garantirColunaGruposWhatsappShopeeOfertasCategoria(): void {
    if (colunaExiste('grupos_whatsapp', 'shopee_ofertas_categoria')) {
        return;
    }
    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN shopee_ofertas_categoria VARCHAR(64) NULL DEFAULT NULL ' .
            "COMMENT 'Chave categoria Shopee (mapeamento keyword); NULL=todas'"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappShopeeOfertasCategoria: ' . $e->getMessage());
    }
}

/**
 * Categoria da API de afiliados AliExpress (category_ids) por grupo WhatsApp.
 */
function garantirColunaGruposWhatsappAliexpressCategoria(): void {
    if (colunaExiste('grupos_whatsapp', 'aliexpress_affiliate_category_id')) {
        return;
    }
    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN aliexpress_affiliate_category_id INT UNSIGNED NULL DEFAULT NULL ' .
            "COMMENT 'ID categoria API affiliate AliExpress; NULL=não definido' AFTER shopee_ofertas_categoria"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappAliexpressCategoria: ' . $e->getMessage());
    }
}

/** ID do job na cron-job.org criado automaticamente para este grupo (envios). */
/**
 * Browse Node Amazon (PA-API) por grupo; vazio = busca em todo o catálogo.
 */
function garantirColunaGruposWhatsappAmazonOfertasCategoria(): void {
    if (colunaExiste('grupos_whatsapp', 'amazon_ofertas_categoria')) {
        return;
    }
    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN amazon_ofertas_categoria VARCHAR(32) NULL DEFAULT NULL ' .
            "COMMENT 'BrowseNodeId Amazon BR; NULL=vazio=todas'"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappAmazonOfertasCategoria: ' . $e->getMessage());
    }
}

function garantirColunaGruposWhatsappCronJobOrgId(): void {
    if (colunaExiste('grupos_whatsapp', 'cron_job_org_job_id')) {
        return;
    }
    $pdo = getDB();
    try {
        $pdo->exec(
            'ALTER TABLE grupos_whatsapp ADD COLUMN cron_job_org_job_id VARCHAR(32) NULL DEFAULT NULL ' .
            "COMMENT 'Job ID cron-job.org (achadinhos-grupo-N)' AFTER aliexpress_affiliate_category_id"
        );
    } catch (Exception $e) {
        error_log('garantirColunaGruposWhatsappCronJobOrgId: ' . $e->getMessage());
    }
}
