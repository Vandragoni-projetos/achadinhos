<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../core/cron/CronPolicy.php';

// Função para fazer upload de imagem
function uploadImagem($file, $pasta = 'uploads/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Verificar se é uma imagem (inclui favicon .ico)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return false;
    }

    // Criar diretório se não existir
    $uploadDir = __DIR__ . '/../' . $pasta;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Gerar nome único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('img_', true) . '.' . $extension;
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return $pasta . $fileName;
    }

    return false;
}

/**
 * Normaliza URL do Mercado Livre para envio seguro ao createLink: remove query (?…) e fragmento (#…).
 */
/**
 * Normaliza ID de categoria da página de ofertas do ML (ex.: MLB1648). Vazio = todas as ofertas.
 */
function mercadolivreNormalizarCategoriaOfertas($raw): string
{
    $s = strtoupper(trim(preg_replace('/\s+/', '', (string) $raw)));
    if ($s === '' || strcasecmp($s, 'TODAS') === 0) {
        return '';
    }
    return preg_match('/^MLB\d+$/', $s) ? $s : '';
}

/**
 * Normaliza hora vinda de input time ou TIME do MySQL (HH:MM ou HH:MM:SS).
 */
function normalizarHoraPostagemGrupo($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
        $h = (int) $m[1];
        $mi = (int) $m[2];
        $s = isset($m[3]) ? (int) $m[3] : 0;
        if ($h >= 0 && $h <= 23 && $mi >= 0 && $mi <= 59 && $s >= 0 && $s <= 59) {
            return sprintf('%02d:%02d:%02d', $h, $mi, $s);
        }
    }
    return null;
}

/**
 * Indica se o horário atual está na janela [início, fim] (timezone do servidor).
 * Se início ou fim for null, não há restrição. Se início > fim, a janela cruza meia-noite.
 */
function grupoEstaNaJanelaPostagem($horaInicio, $horaFim): bool
{
    $hi = normalizarHoraPostagemGrupo($horaInicio);
    $hf = normalizarHoraPostagemGrupo($horaFim);
    if ($hi === null || $hf === null) {
        return true;
    }
    $now = new DateTime('now');
    $base = $now->format('Y-m-d');
    $tIn = strtotime($base . ' ' . $hi);
    $tFi = strtotime($base . ' ' . $hf);
    $cur = $now->getTimestamp();
    if ($tIn === false || $tFi === false) {
        return true;
    }
    if ($tIn <= $tFi) {
        return $cur >= $tIn && $cur <= $tFi;
    }
    return $cur >= $tIn || $cur <= $tFi;
}

/**
 * Horários de postagem salvos no grupo (cache por request).
 *
 * @return array{post_hora_inicio: ?string, post_hora_fim: ?string}
 */
function grupo_whatsapp_horarios_postagem(int $grupoId): array
{
    static $cache = [];
    if (isset($cache[$grupoId])) {
        return $cache[$grupoId];
    }
    try {
        $pdo = getDB();
        $st = $pdo->prepare('SELECT post_hora_inicio, post_hora_fim FROM grupos_whatsapp WHERE id = ? LIMIT 1');
        $st->execute([$grupoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $cache[$grupoId] = ['post_hora_inicio' => null, 'post_hora_fim' => null];
        } else {
            $cache[$grupoId] = [
                'post_hora_inicio' => ($row['post_hora_inicio'] !== null && (string) $row['post_hora_inicio'] !== '')
                    ? (string) $row['post_hora_inicio'] : null,
                'post_hora_fim' => ($row['post_hora_fim'] !== null && (string) $row['post_hora_fim'] !== '')
                    ? (string) $row['post_hora_fim'] : null,
            ];
        }
    } catch (Exception $e) {
        $cache[$grupoId] = ['post_hora_inicio' => null, 'post_hora_fim' => null];
    }
    return $cache[$grupoId];
}

function normalizarUrlMercadoLivre($url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $q = strpos($url, '?');
    if ($q !== false) {
        $url = substr($url, 0, $q);
    }
    $h = strpos($url, '#');
    if ($h !== false) {
        $url = substr($url, 0, $h);
    }
    return rtrim($url, '/');
}

/**
 * Indica se a URL parece página de produto aceita pelo programa de afiliados (createLink).
 * Inclui /p/… e slugs típicos com -p-MLB… / MLB-…
 */
function urlMercadoLivrePermitidaParaCreateLink($url): bool
{
    if (!is_string($url) || $url === '') {
        return false;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }
    if (stripos($path, '/p/') !== false) {
        return true;
    }
    if (preg_match('#-p-ML[BU]\d#i', $path)) {
        return true;
    }
    if (preg_match('#/ML[BU]-\d+#i', $path)) {
        return true;
    }
    // Páginas de item / vitrine que o Link Builder aceita (ofertas ML mudam o formato do href)
    if (preg_match('#/(item|up)/ML[MU]?\d#i', $path)) {
        return true;
    }
    if (preg_match('#ML[BUI]\d{6,}#i', $path)) {
        return true;
    }

    return false;
}

// Função para deletar imagem
function deleteImagem($path) {
    if (!empty($path) && file_exists(__DIR__ . '/../' . $path)) {
        @unlink(__DIR__ . '/../' . $path);
    }
}

/**
 * Garante coluna admins.avatar (instalações antigas sem migração manual).
 */
function ensureAdminAvatarColumn(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = getDB();
        $pdo->query('SELECT avatar FROM admins LIMIT 1');
    } catch (PDOException $e) {
        try {
            getDB()->exec('ALTER TABLE admins ADD COLUMN avatar VARCHAR(255) NULL DEFAULT NULL');
        } catch (PDOException $e2) {
        }
    }
}

/**
 * Caminho relativo à raiz do projeto (ex.: uploads/admin_avatars/...) ou string vazia.
 */
function getAdminAvatarPathById(int $adminId): string
{
    if ($adminId <= 0) {
        return '';
    }
    ensureAdminAvatarColumn();
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT avatar FROM admins WHERE id = ?');
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();
    return !empty($row['avatar']) ? (string) $row['avatar'] : '';
}

// Função para obter configuração
function getConfig($chave, $default = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $stmt->execute([$chave]);
    $result = $stmt->fetch();
    return $result ? $result['valor'] : $default;
}

/**
 * Provedor de IA para classificar produtos nas categorias/subcategorias do site (aba IA nas configurações).
 *
 * @return 'none'|'openai'|'gemini'
 */
function iaCategoriaProvedorAtual(): string {
    $p = trim((string) getConfig('ia_categoria_provedor', ''));
    if ($p === 'openai' || $p === 'gemini') {
        return $p;
    }
    if (getConfig('ia_categoria_usar_gemini', '0') === '1') {
        return 'gemini';
    }

    return 'none';
}

/**
 * URL absoluta da raiz pública do projeto (pasta que contém admin/, cron/, etc.).
 * Usa cron_public_base_url se estiver configurada; caso contrário, monta a partir do pedido atual
 * (HTTPS, host, X-Forwarded-*, subpasta em SCRIPT_NAME — ex.: http://localhost/achadinhos).
 */
function sitePublicRootUrl(): string {
    $cfg = rtrim(trim((string) getConfig('cron_public_base_url', '')), '/');
    if ($cfg !== '') {
        return $cfg;
    }
    if (PHP_SAPI === 'cli') {
        return '';
    }
    require_once __DIR__ . '/../core/cron/CronJobService.php';
    $origin = cronPublicBaseUrlFromRequest();
    if ($origin === '') {
        return '';
    }
    $sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($sn === '') {
        return $origin;
    }
    $parentOfAdminFolder = dirname(dirname($sn));
    if ($parentOfAdminFolder === '/' || $parentOfAdminFolder === '\\' || $parentOfAdminFolder === '.') {
        return $origin;
    }

    return $origin . rtrim($parentOfAdminFolder, '/');
}

/**
 * URL absoluta do endpoint de download da extensão ML no painel (ou relativo se não houver como inferir).
 */
function adminBaixarExtensaoMlUrl(): string {
    $root = sitePublicRootUrl();
    if ($root === '') {
        return 'baixar-extensao-ml.php';
    }

    return $root . '/admin/baixar-extensao-ml.php';
}

/**
 * Selo visual OK ou Teste (mesmo estilo do menu Lojas na sidebar).
 */
function adminFeatureBadgeHtml(string $kind, string $variant = ''): string {
    $kind = strtolower(trim($kind));
    if ($kind === 'ok') {
        $label = 'OK';
        if ($variant === 'sidebar') {
            $cls = 'bg-emerald-950/80 text-emerald-400 ring-1 ring-emerald-500/35';
        } else {
            $cls = 'bg-emerald-500/25 text-emerald-400 ring-1 ring-emerald-500/30';
        }
    } elseif ($kind === 'teste') {
        $label = 'Teste';
        if ($variant === 'sidebar') {
            $cls = 'bg-amber-950/70 text-amber-400 ring-1 ring-amber-600/35';
        } else {
            $cls = 'bg-amber-500/25 text-amber-400 ring-1 ring-amber-500/30';
        }
    } else {
        return '';
    }

    return '<span class="shrink-0 text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded ' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Selo OK / Teste ao lado do nome da loja no menu admin (sidebar).
 * Padrão por arquivo PHP; override opcional em configuracoes: chave nav_badge_<slug>
 * (slug = nome do arquivo sem .php, hífen → underscore), valores: ok, teste, none ou - para ocultar.
 */
function adminNavLojaBadgeHtml(string $pageFile): string {
    static $defaults = [
        'mercadolivre.php' => 'ok',
        'shopee.php' => 'ok',
        'magalu.php' => 'ok',
        'aliexpress.php' => 'ok',
        'mercadolivre-api.php' => 'teste',
        'amazon.php' => 'ok',
        'shein.php' => 'teste',
    ];
    $base = basename($pageFile);
    if (!isset($defaults[$base])) {
        return '';
    }
    $slug = str_replace('-', '_', preg_replace('/\.php$/i', '', $base));
    $raw = strtolower(trim((string) getConfig('nav_badge_' . $slug, '')));
    if ($raw === '-' || $raw === 'none' || $raw === 'off') {
        return '';
    }
    if ($raw === 'ok' || $raw === 'teste') {
        $kind = $raw;
    } elseif ($raw === '') {
        $kind = $defaults[$base];
    } else {
        return '';
    }

    return adminFeatureBadgeHtml($kind, 'sidebar');
}

/**
 * Lojas de automação disponíveis para vincular um grupo WhatsApp.
 * Cada chave gera uma regra separada (uma linha em grupos_whatsapp por grupo WhatsApp × loja).
 * «Magalu minha loja» (magalu_loja) não é oferecida aqui: não há runner por grupo (usa cron da loja / Magalu catálogo).
 *
 * @return array<string, string> chave interna => rótulo
 */
function gruposAutomacaoLojaOpcoes(): array {
    return [
        'ml' => 'Mercado Livre',
        'ml_cupons' => 'Mercado Livre (cupons)',
        'shopee' => 'Shopee',
        'magalu' => 'Magazine Luiza (catálogo)',
        'amazon' => 'Amazon',
        'aliexpress' => 'AliExpress',
        'shein' => 'Shein',
    ];
}

/**
 * Lojas omitidas no passo «4. Lojas e opções» da aba Adicionar grupo (cadastro em massa).
 * A edição de grupo individual continua listando todas as opções de gruposAutomacaoLojaOpcoes().
 *
 * @return list<string>
 */
function gruposAutomacaoLojaOcultasFormularioBulk(): array {
    return ['shein', 'ml_cupons', 'magalu'];
}

/**
 * Chaves aceites em cadastro/edição de grupos (rodar-grupo.php suporta as mesmas, exceto magalu_loja).
 *
 * @return list<string>
 */
function gruposAutomacaoLojaChavesPermitidasCadastro(): array {
    return array_keys(gruposAutomacaoLojaOpcoes());
}

/**
 * Normaliza o código da loja salvo em grupos_whatsapp.automacao_loja.
 * Valor desconhecido (ex.: legado magalu_loja) devolve null — não há fallback silencioso para ml.
 */
function gruposNormalizarAutomacaoLoja(string $raw): ?string {
    $raw = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($raw)));
    if ($raw === '') {
        return null;
    }
    $opts = gruposAutomacaoLojaChavesPermitidasCadastro();

    return in_array($raw, $opts, true) ? $raw : null;
}

/**
 * Para cálculo de intervalo/agenda (cron-job.org) quando o valor no BD é legado inválido: usa ML só aqui.
 */
function gruposNormalizarAutomacaoLojaParaAgenda(array $grupo): string {
    $n = gruposNormalizarAutomacaoLoja((string) ($grupo['automacao_loja'] ?? ''));

    return $n ?? 'ml';
}

/**
 * Apenas dígitos — chave em shopee-ofertas-categorias-brasil.php (mapeada para keyword em productOfferV2).
 */
function shopeeNormalizarCategoriaOfertasGrupo(string $s): string {
    return preg_replace('/[^0-9]/', '', trim($s));
}

/**
 * IDs dos grupos ativos cuja loja de automação no cadastro (Grupos) coincide com $prefix.
 * Usado quando {$prefix}_grupos_ids está vazio na página da loja (ex.: Shopee sem aba WhatsApp).
 * Não aplicar a 'ml': o padrão do banco é ml e geraria lista enorme indevida.
 *
 * @return list<int>
 */
/**
 * IDs dos grupos ML ativos com conta Evolution/Uazapi válida (url + api_key).
 * Usado quando ml_grupos_ids está vazio para não deixar automação sem destinos.
 *
 * @return list<int>
 */
function achadinhosMlIdsGruposComContaAtiva(): array {
    try {
        $pdo = getDB();
        $st = $pdo->query(
            "SELECT g.id FROM grupos_whatsapp g " .
            "INNER JOIN evolution_contas e ON g.evolution_conta_id = e.id " .
            "WHERE g.ativo = 1 AND e.ativo = 1 AND COALESCE(g.automacao_loja, 'ml') = 'ml' " .
            "AND TRIM(COALESCE(e.url_base, '')) <> '' AND TRIM(COALESCE(e.api_key, '')) <> '' " .
            'ORDER BY g.nome'
        );
        $col = $st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        return array_values(array_filter(array_map('intval', is_array($col) ? $col : [])));
    } catch (Exception $e) {
        return [];
    }
}

function getIdsGruposWhatsappPorAutomacaoLoja(string $prefix): array {
    $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix));
    if ($prefix === '' || $prefix === 'ml') {
        return [];
    }
    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT id FROM grupos_whatsapp WHERE ativo = 1 AND COALESCE(automacao_loja, \'ml\') = ? ORDER BY nome'
        );
        $st->execute([$prefix]);
        $col = $st->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_values(array_filter(array_map('intval', is_array($col) ? $col : [])));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Retorna os grupos WhatsApp configurados para uma loja (conta + grupos da página da loja).
 * Se a loja tiver grupos selecionados, retorna somente esses grupos (nunca outros).
 * Se {$prefix}_grupos_ids estiver vazio (exceto loja ml), usa todos os grupos ativos com automacao_loja = prefixo (cadastro em Grupos).
 * Se houver conta selecionada na loja, usa ela para todos; senão, usa a conta de cada grupo (evolution_conta_id).
 * Loja Amazon: ignora {prefix}_evolution_conta_id — sempre a conta cadastrada em cada grupo.
 * Só entram grupos cuja automacao_loja no cadastro coincide com o prefixo da loja (ex.: shopee).
 * O envio é tentado para cada JID cadastrado; grupos abertos aceitam membro comum, grupos só-admins exigem admin na instância.
 * Uso: getGruposFixosPorLoja('aliexpress'), getGruposFixosPorLoja('shopee'), etc.
 * @param list<int>|null $idsSomente Se não nulo, usa estes IDs em vez de {$prefix}_grupos_ids (ex.: teste de envio para um grupo).
 * Retorna array de [ 'id'=>int, 'nome'=>string, 'grupo_id'=>string, 'evolution_conta_id'=>int, 'evolution'=>[...], 'ml_ofertas_categoria'?, 'shopee_ofertas_categoria'?, 'aliexpress_affiliate_category_id'?, 'amazon_ofertas_categoria'? ].
 */
function getGruposFixosPorLoja($prefix, ?array $idsSomente = null) {
    $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix));
    if ($prefix === '') return [];
    $schemaPath = __DIR__ . '/../core/db/SchemaHelper.php';
    if (is_file($schemaPath)) {
        require_once $schemaPath;
        if (function_exists('garantirColunaGruposWhatsappMlOfertasCategoria')) {
            garantirColunaGruposWhatsappMlOfertasCategoria();
        }
        if (function_exists('garantirColunaGruposWhatsappPostHoras')) {
            garantirColunaGruposWhatsappPostHoras();
        }
        if (function_exists('garantirColunaGruposWhatsappAutomacaoLoja')) {
            garantirColunaGruposWhatsappAutomacaoLoja();
        }
        if (function_exists('garantirColunaGruposWhatsappShopeeOfertasCategoria')) {
            garantirColunaGruposWhatsappShopeeOfertasCategoria();
        }
        if (function_exists('garantirColunaGruposWhatsappAliexpressCategoria')) {
            garantirColunaGruposWhatsappAliexpressCategoria();
        }
        if (function_exists('garantirColunaGruposWhatsappAmazonOfertasCategoria')) {
            garantirColunaGruposWhatsappAmazonOfertasCategoria();
        }
    }
    $amf = __DIR__ . '/amazon-ofertas-browse-nodes-br.php';
    if (is_file($amf) && !function_exists('amazonNormalizarBrowseNodeGrupo')) {
        require_once $amf;
    }
    $contaId = (int) getConfig($prefix . '_evolution_conta_id', '0');
    if ($prefix === 'amazon') {
        $contaId = 0;
    }
    if ($idsSomente !== null) {
        $ids = array_values(array_filter(array_map('intval', $idsSomente)));
    } else {
        $gruposIdsConfig = getConfig($prefix . '_grupos_ids', '');
        if (trim($gruposIdsConfig) === '') {
            $ids = getIdsGruposWhatsappPorAutomacaoLoja($prefix);
        } else {
            $ids = array_values(array_filter(array_map('intval', explode(',', $gruposIdsConfig))));
        }
    }
    if ($ids === []) {
        return [];
    }
    $pdo = getDB();
    static $sqlEvoConta = null;
    if ($sqlEvoConta === null) {
        $hasProv = false;
        $hasAp = false;
        try {
            $pdo->query('SELECT provedor, uazapi_admin_token, api_propria FROM evolution_contas LIMIT 1');
            $hasProv = true;
            $hasAp = true;
        } catch (Exception $e) {
            try {
                $pdo->query('SELECT provedor, uazapi_admin_token FROM evolution_contas LIMIT 1');
                $hasProv = true;
            } catch (Exception $e2) {
            }
            try {
                $pdo->query('SELECT api_propria FROM evolution_contas LIMIT 1');
                $hasAp = true;
            } catch (Exception $e3) {
            }
        }
        if ($hasProv) {
            $sqlEvoConta = "SELECT url_base, instancia, api_key, COALESCE(provedor, 'evolution') AS provedor, uazapi_admin_token"
                . ($hasAp ? ', COALESCE(api_propria, 0) AS api_propria' : '')
                . ' FROM evolution_contas WHERE id = ? AND ativo = 1';
        } else {
            $sqlEvoConta = 'SELECT url_base, instancia, api_key FROM evolution_contas WHERE id = ? AND ativo = 1';
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtGr = $pdo->prepare(
        "SELECT g.id, g.nome, g.grupo_id, g.evolution_conta_id, g.intervalo_minutos, " .
        "COALESCE(g.ml_ofertas_categoria, '') AS ml_ofertas_categoria, " .
        "COALESCE(g.shopee_ofertas_categoria, '') AS shopee_ofertas_categoria, " .
        "COALESCE(g.aliexpress_affiliate_category_id, 0) AS aliexpress_affiliate_category_id, " .
        "COALESCE(g.amazon_ofertas_categoria, '') AS amazon_ofertas_categoria, " .
        "COALESCE(g.automacao_loja, 'ml') AS automacao_loja, " .
        'g.post_hora_inicio, g.post_hora_fim FROM grupos_whatsapp g ' .
        "WHERE g.id IN ($placeholders) AND g.ativo = 1 AND COALESCE(g.automacao_loja, 'ml') = ?"
    );
    $stmtGr->execute(array_merge($ids, [$prefix]));
    $contaRow = null;
    if ($contaId > 0) {
        $stmtConta = $pdo->prepare($sqlEvoConta);
        $stmtConta->execute([$contaId]);
        $contaRow = $stmtConta->fetch();
    }
    $out = [];
    while ($row = $stmtGr->fetch()) {
        $evo = null;
        if ($contaRow) {
            $evo = [
                'url_base' => rtrim($contaRow['url_base'], '/'),
                'instancia' => $contaRow['instancia'],
                'api_key' => $contaRow['api_key'],
                'provedor' => $contaRow['provedor'] ?? 'evolution',
                'uazapi_admin_token' => (string) ($contaRow['uazapi_admin_token'] ?? ''),
                'api_propria' => (int) ($contaRow['api_propria'] ?? 0),
            ];
        } else {
            $ecId = (int) $row['evolution_conta_id'];
            if ($ecId > 0) {
                $stmtE = $pdo->prepare($sqlEvoConta);
                $stmtE->execute([$ecId]);
                $er = $stmtE->fetch();
                if ($er) {
                    $evo = [
                        'url_base' => rtrim($er['url_base'], '/'),
                        'instancia' => $er['instancia'],
                        'api_key' => $er['api_key'],
                        'provedor' => $er['provedor'] ?? 'evolution',
                        'uazapi_admin_token' => (string) ($er['uazapi_admin_token'] ?? ''),
                        'api_propria' => (int) ($er['api_propria'] ?? 0),
                    ];
                }
            }
        }
        if ($evo) {
            $out[] = [
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'grupo_id' => $row['grupo_id'],
                'evolution_conta_id' => (int) $row['evolution_conta_id'],
                'evolution' => $evo,
                'intervalo_minutos' => isset($row['intervalo_minutos']) ? (int) $row['intervalo_minutos'] : null,
                'automacao_loja' => (string) ($row['automacao_loja'] ?? 'ml'),
                'ml_ofertas_categoria' => $prefix === 'ml'
                    ? mercadolivreNormalizarCategoriaOfertas($row['ml_ofertas_categoria'] ?? '')
                    : '',
                'shopee_ofertas_categoria' => $prefix === 'shopee'
                    ? shopeeNormalizarCategoriaOfertasGrupo((string) ($row['shopee_ofertas_categoria'] ?? ''))
                    : '',
                'aliexpress_affiliate_category_id' => $prefix === 'aliexpress'
                    ? (int) ($row['aliexpress_affiliate_category_id'] ?? 0)
                    : 0,
                'amazon_ofertas_categoria' => $prefix === 'amazon' && function_exists('amazonNormalizarBrowseNodeGrupo')
                    ? amazonNormalizarBrowseNodeGrupo((string) ($row['amazon_ofertas_categoria'] ?? ''))
                    : '',
                'post_hora_inicio' => $row['post_hora_inicio'] ?? null,
                'post_hora_fim' => $row['post_hora_fim'] ?? null,
            ];
        }
    }
    return $out;
}

/**
 * Admin dono dos destinos Telegram por loja (alinha com cron/dispatches: dispatch_admin_id).
 */
function telegramLojaOwnerUserId(): int {
    return max(1, (int) getConfig('dispatch_admin_id', '1'));
}

/**
 * Cabeçalho de diagnóstico para histórico cron-job.org / proxies (não altera códigos HTTP).
 */
function achadinhosCronHttpDiagnosticHeader(string $code): void {
    if (php_sapi_name() === 'cli' || headers_sent()) {
        return;
    }
    $code = preg_replace('/[^a-z0-9_]/', '', strtolower($code));
    if ($code === '') {
        return;
    }
    header('X-Achadinhos-Cron-Error: ' . $code);
}

/**
 * Lê o token de cron na requisição HTTP: ?token= (prioridade) ou cabeçalho X-Cron-Token.
 * Vários stacks (nginx+php-fpm, Apache, proxies) expõem o cabeçalho em chaves diferentes;
 * getallheaders() cobre casos em que $_SERVER['HTTP_X_CRON_TOKEN'] vem vazio.
 *
 * @return array{value: string, source: string} source: query|header:*|none
 */
function achadinhosCronLerTokenDaRequisicao(): array {
    if (isset($_GET['token'])) {
        $q = trim((string) $_GET['token']);
        if ($q !== '') {
            return ['value' => $q, 'source' => 'query'];
        }
    }
    $serverKeys = [
        'HTTP_X_CRON_TOKEN',
        'REDIRECT_HTTP_X_CRON_TOKEN',
        'HTTP_X_HTTP_X_CRON_TOKEN',
    ];
    foreach ($serverKeys as $sk) {
        if (!empty($_SERVER[$sk]) && is_string($_SERVER[$sk])) {
            $v = trim($_SERVER[$sk]);
            if ($v !== '') {
                return ['value' => $v, 'source' => 'header:' . $sk];
            }
        }
    }
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $hk => $hv) {
                if (strcasecmp((string) $hk, 'X-Cron-Token') !== 0) {
                    continue;
                }
                $v = trim((string) $hv);
                if ($v !== '') {
                    return ['value' => $v, 'source' => 'header:getallheaders'];
                }
            }
        }
    }

    return ['value' => '', 'source' => 'none'];
}

/**
 * Lê um cabeçalho HTTP custom (ex.: X-Cron-Loja) com fallbacks para nginx/Apache/proxy.
 */
function achadinhosCronLerHeaderHttp(string $nomeCanonico): string {
    $nomeCanonico = trim($nomeCanonico);
    if ($nomeCanonico === '') {
        return '';
    }
    $suf = strtoupper(str_replace('-', '_', $nomeCanonico));
    $keys = [
        'HTTP_' . $suf,
        'REDIRECT_HTTP_' . $suf,
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k]) && is_string($_SERVER[$k])) {
            $v = trim($_SERVER[$k]);
            if ($v !== '') {
                return $v;
            }
        }
    }
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $hk => $hv) {
                if (strcasecmp((string) $hk, $nomeCanonico) === 0) {
                    return trim((string) $hv);
                }
            }
        }
    }

    return '';
}

/**
 * Ambiente “desenvolvimento” para auth de cron HTTP (localhost, IP privado, .test, APP_ENV=development).
 * Evita exigir token quando ainda não há nada configurado em Laragon/local.
 */
function achadinhosCronRequestIsDevEnvironment(): bool {
    if (defined('APP_ENV') && APP_ENV === 'development') {
        return true;
    }
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'], 2)[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string) $_SERVER['HTTP_HOST'];
    }
    $host = strtolower(trim($host));
    if ($host !== '' && strpos($host, ':') !== false) {
        $host = explode(':', $host, 2)[0];
    }
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -5) === '.test' || substr($host, -8) === '.invalid') {
        return true;
    }
    if ($host === '0.0.0.0' || $host === '::1') {
        return true;
    }
    if ($host !== '' && preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
        return true;
    }

    return false;
}

/**
 * Log de falha de auth cron (sem token, comprimentos apenas). Ficheiro: debug-cron-auth.log na raiz do projeto.
 *
 * @param array<string, mixed> $meta
 */
function achadinhosCronAuthLog(string $event, array $meta = []): void {
    $root = dirname(__DIR__);
    $path = $root . DIRECTORY_SEPARATOR . 'debug-cron-auth.log';
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $pathOnly = $uri !== '' ? (string) (parse_url($uri, PHP_URL_PATH) ?: $uri) : '';
    $grupoMeta = $meta['grupo_id'] ?? null;
    if ($grupoMeta === null && isset($_GET['grupo'])) {
        $grupoMeta = (int) $_GET['grupo'];
    }
    $grupoMeta = is_int($grupoMeta) || (is_string($grupoMeta) && $grupoMeta !== '') ? (int) $grupoMeta : null;
    if ($grupoMeta !== null && $grupoMeta <= 0) {
        $grupoMeta = null;
    }
    $line = json_encode([
        't' => (int) round(microtime(true) * 1000),
        'event' => $event,
        'script' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        'path' => $pathOnly,
        'context_script' => (string) ($meta['context_script'] ?? ''),
        'grupo_id' => $grupoMeta,
        'source' => (string) ($meta['source'] ?? ''),
        'get_token_param' => isset($_GET['token']),
        'expected_len' => (int) ($meta['expected_len'] ?? 0),
        'received_len' => (int) ($meta['received_len'] ?? 0),
        'reject' => (string) ($meta['reject'] ?? ''),
        'http_status' => isset($meta['http_status']) ? (int) $meta['http_status'] : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Flags JSON para respostas de cron e persistência: evita falha silenciosa com UTF-8 inválido vindos de APIs externas.
 */
function achadinhosCronJsonFlags(): int {
    $f = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $f |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
        $f |= JSON_PARTIAL_OUTPUT_ON_ERROR;
    }

    return $f;
}

/**
 * Serializa dados para corpo HTTP ou coluna JSON; nunca devolve string vazia por falha de encode.
 *
 * @param mixed $data
 */
function achadinhosCronJsonEncode($data, int $extraFlags = 0): string {
    $json = json_encode($data, achadinhosCronJsonFlags() | $extraFlags);
    if ($json !== false) {
        return $json;
    }
    $fallback = ['success' => false, 'message' => 'Falha ao serializar resposta (UTF-8 ou tipos inválidos).'];
    $json = json_encode($fallback, achadinhosCronJsonFlags());

    return $json !== false ? $json : '{"success":false,"message":"json_encode falhou"}';
}

/**
 * Protege endpoints em cron/ via HTTP: exige ?token= ou cabeçalho X-Cron-Token igual à chave em configuracoes. CLI não valida.
 * 403 com mensagem em texto ou JSON.
 */
function achadinhosCronHttpExigirToken(string $chaveConfiguracao, bool $responderJson): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    $esperado = trim((string) getConfig($chaveConfiguracao, ''));
    if ($esperado === '') {
        if (achadinhosCronRequestIsDevEnvironment()) {
            header('X-Achadinhos-Cron-Dev: auth-bypass-empty-token');

            return;
        }
        http_response_code(403);
        achadinhosCronHttpDiagnosticHeader('token_not_configured');
        achadinhosCronAuthLog('cron_auth_fail', [
            'source' => 'n/a',
            'expected_len' => 0,
            'received_len' => 0,
            'reject' => 'token_not_configured',
            'context_script' => $chaveConfiguracao,
            'http_status' => 403,
        ]);
        if ($responderJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token não configurado'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Token não configurado';
        }
        exit;
    }
    $tokInfo = achadinhosCronLerTokenDaRequisicao();
    $recebido = $tokInfo['value'];
    if ($recebido === '') {
        http_response_code(403);
        achadinhosCronHttpDiagnosticHeader('token_missing');
        achadinhosCronAuthLog('cron_auth_fail', [
            'source' => $tokInfo['source'],
            'expected_len' => strlen($esperado),
            'received_len' => 0,
            'reject' => 'token_missing',
            'context_script' => $chaveConfiguracao,
            'http_status' => 403,
        ]);
        if ($responderJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token ausente: use ?token=… na URL ou o cabeçalho HTTP X-Cron-Token (como na cron-job.org).'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Token ausente: use ?token= na URL ou o cabeçalho X-Cron-Token.';
        }
        exit;
    }
    if (!hash_equals($esperado, $recebido)) {
        http_response_code(403);
        achadinhosCronHttpDiagnosticHeader('token_invalid');
        achadinhosCronAuthLog('cron_auth_fail', [
            'source' => $tokInfo['source'],
            'expected_len' => strlen($esperado),
            'received_len' => strlen($recebido),
            'reject' => 'token_mismatch',
            'context_script' => $chaveConfiguracao,
            'http_status' => 403,
        ]);
        if ($responderJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Token inválido';
        }
        exit;
    }
}

/**
 * Token HTTP oficial da aplicação (config `cron_token`). Única fonte para montar ?token= em URLs públicas de cron global e por grupo.
 */
function achadinhosCronTokenHttpOficialLer(): string {
    return trim((string) getConfig('cron_token', ''));
}

/**
 * Tokens de loja em `automacoes_cron`, ordem estável (primeiro MIN(id) por valor distinto).
 *
 * @return list<string>
 */
function achadinhosCronTokensAutomacoesCronOrdenados(): array {
    $out = [];
    try {
        $pdo = getDB();
        $st = $pdo->query(
            'SELECT TRIM(token) AS t FROM automacoes_cron WHERE TRIM(COALESCE(token, \'\')) <> \'\' ' .
            'GROUP BY TRIM(token) ORDER BY MIN(id) ASC'
        );
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $t = trim((string) ($row['t'] ?? ''));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
    } catch (Exception $e) {
        // ignora
    }

    return $out;
}

/**
 * Tokens aceites na autenticação HTTP dos scripts partilhados (rodar-tudo, rodar-grupo): oficial primeiro, depois fallbacks de loja (sem duplicar).
 *
 * @return list<string>
 */
function achadinhosCronTokensHttpParaAutenticacao(): array {
    $official = achadinhosCronTokenHttpOficialLer();
    $out = [];
    if ($official !== '') {
        $out[] = $official;
    }
    foreach (achadinhosCronTokensAutomacoesCronOrdenados() as $t) {
        if ($t !== '' && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }

    return $out;
}

/**
 * @deprecated Use {@see achadinhosCronTokensHttpParaAutenticacao()} para auth ou {@see achadinhosCronTokenHttpOficialLer()} para URLs.
 *
 * @return list<string>
 */
function achadinhosCronColetarTokensHttpValidos(): array {
    return achadinhosCronTokensHttpParaAutenticacao();
}

/**
 * Token HTTP usado em URLs públicas (cron-job.org): apenas `cron_token` global. Não usar tokens de loja aqui (ordem imprevisível / ambiguidade).
 */
function achadinhosCronPrimeiroTokenHttp(): string {
    return achadinhosCronTokenHttpOficialLer();
}

/**
 * Garante `cron_token` não vazio antes de sincronizar jobs externos (gera e grava um valor forte se necessário).
 *
 * @return array{ok: bool, message: string, generated: bool}
 */
function achadinhosCronGarantirTokenHttpOficialParaSync(): array {
    $cur = achadinhosCronTokenHttpOficialLer();
    if ($cur !== '') {
        return ['ok' => true, 'message' => '', 'generated' => false];
    }
    try {
        $new = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Não foi possível gerar cron_token (entropia). Defina manualmente o token HTTP nas configurações da loja ou em configuracoes.',
            'generated' => false,
        ];
    }
    if (!setConfig('cron_token', $new)) {
        return [
            'ok' => false,
            'message' => 'Não foi possível gravar cron_token na base de dados. Verifique a tabela configuracoes e permissões MySQL.',
            'generated' => false,
        ];
    }
    achadinhosCronAuthLog('cron_official_token_auto_generated', [
        'source' => 'n/a',
        'expected_len' => strlen($new),
        'received_len' => 0,
        'reject' => 'n/a',
        'context_script' => 'achadinhosCronGarantirTokenHttpOficialParaSync',
        'http_status' => null,
    ]);

    return [
        'ok' => true,
        'message' => 'Token HTTP principal (cron_token) gerado e guardado.',
        'generated' => true,
    ];
}

/**
 * Máscara segura para UI (nunca expõe o token completo).
 */
function achadinhosCronMascarTokenAdmin(string $token): string {
    $t = trim($token);
    $len = strlen($t);
    if ($len < 6) {
        return $len > 0 ? '•••• (definido)' : '— (ausente)';
    }

    return '••••' . substr($t, -4);
}

/**
 * Valida token HTTP para cron (rodar-grupo, rodar-tudo): aceita cron_token oficial e, como fallback controlado, tokens distintos de automacoes_cron (ordem estável).
 */
function achadinhosCronHttpExigirTokenFlexivel(bool $responderJson, string $contextoScript = 'cron'): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    $validos = achadinhosCronTokensHttpParaAutenticacao();
    $grupoQ = isset($_GET['grupo']) ? (int) $_GET['grupo'] : null;
    if ($grupoQ !== null && $grupoQ <= 0) {
        $grupoQ = null;
    }
    if ($validos === []) {
        if (achadinhosCronRequestIsDevEnvironment()) {
            header('X-Achadinhos-Cron-Dev: auth-bypass-no-tokens');

            return;
        }
        if (function_exists('achadinhos_agent_debug_ndjson')) {
            achadinhos_agent_debug_ndjson(
                'cron:token_auth',
                'token esperado vazio',
                [
                    'script' => $contextoScript,
                    'caminho_escrita' => function_exists('achadinhos_agent_debug_log_path_df3052') ? achadinhos_agent_debug_log_path_df3052() : '',
                    'tokens_configurados' => 0,
                ],
                'CRON-T'
            );
        }
        http_response_code(403);
        achadinhosCronHttpDiagnosticHeader('token_not_configured');
        achadinhosCronAuthLog('cron_auth_fail', [
            'source' => 'n/a',
            'expected_len' => 0,
            'received_len' => 0,
            'reject' => 'token_not_configured',
            'context_script' => $contextoScript,
            'grupo_id' => $grupoQ,
            'http_status' => 403,
        ]);
        if ($responderJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Token HTTP não configurado: defina cron_token (recomendado) ou um token nas crons por loja (automacoes_cron). Sem isso, a sincronização com cron-job.org gera o cron_token automaticamente ao guardar um grupo.',
                'cron_auth' => ['code' => 'token_not_configured', 'http' => 403, 'hint' => 'Configure cron_token ou sincronize um grupo pelo admin para gerar o token oficial.'],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Token não configurado (cron_token ou token de loja em automacoes_cron).';
        }
        exit;
    }
    $tokInfo = achadinhosCronLerTokenDaRequisicao();
    $recebido = $tokInfo['value'];
    if ($recebido === '') {
        if (function_exists('achadinhos_agent_debug_ndjson')) {
            achadinhos_agent_debug_ndjson(
                'cron:token_auth',
                'token recebido ausente',
                [
                    'script' => $contextoScript,
                    'source' => $tokInfo['source'] ?? 'none',
                    'tokens_configurados' => count($validos),
                ],
                'CRON-T'
            );
        }
        http_response_code(403);
        achadinhosCronHttpDiagnosticHeader('token_missing');
        achadinhosCronAuthLog('cron_auth_fail', [
            'source' => $tokInfo['source'],
            'expected_len' => strlen($validos[0]),
            'received_len' => 0,
            'reject' => 'token_missing',
            'context_script' => $contextoScript,
            'grupo_id' => $grupoQ,
            'http_status' => 403,
        ]);
        if ($responderJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Token ausente na requisição: inclua ?token=… na URL (como na cron-job.org) ou o cabeçalho X-Cron-Token (deve coincidir com cron_token ou token de loja).',
                'cron_auth' => ['code' => 'token_missing', 'http' => 403, 'token_source_expected' => 'query_or_X-Cron-Token'],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Token ausente: use ?token= na URL ou o cabeçalho X-Cron-Token.';
        }
        exit;
    }
    foreach ($validos as $esperado) {
        if (hash_equals($esperado, $recebido)) {
            if (function_exists('achadinhos_agent_debug_ndjson')) {
                achadinhos_agent_debug_ndjson(
                    'cron:token_auth',
                    'autenticação ok',
                    [
                        'script' => $contextoScript,
                        'source' => $tokInfo['source'] ?? '',
                        'tokens_configurados' => count($validos),
                    ],
                    'CRON-T'
                );
            }

            return;
        }
    }
    http_response_code(403);
    achadinhosCronHttpDiagnosticHeader('token_invalid');
    achadinhosCronAuthLog('cron_auth_fail', [
        'source' => $tokInfo['source'],
        'expected_len' => strlen($validos[0]),
        'received_len' => strlen($recebido),
        'reject' => 'token_mismatch',
        'context_script' => $contextoScript,
        'grupo_id' => $grupoQ,
        'http_status' => 403,
    ]);
    if ($responderJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Token inválido: o valor enviado não coincide com cron_token nem com tokens de loja registados.',
            'cron_auth' => ['code' => 'token_mismatch', 'http' => 403],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Token inválido';
    }
    exit;
}

/**
 * Remove produtos mais antigos que N dias (imagens incluídas). Usado por rodar-tudo e rodar-loja.
 *
 * @return array{success: bool, message: string, deletados: int}
 */
function cronExecutarLimpezaProdutosAntigos(int $diasExpiracao): array {
    $diasExpiracao = max(1, min(365, $diasExpiracao));
    try {
        $pdo = getDB();
        $sql = 'SELECT id, imagem FROM produtos WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$diasExpiracao]);
        $antigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $deletados = 0;
        foreach ($antigos as $p) {
            if (!empty($p['imagem']) && function_exists('deleteImagem')) {
                deleteImagem($p['imagem']);
            }
            $del = $pdo->prepare('DELETE FROM produtos WHERE id = ?');
            $del->execute([(int) $p['id']]);
            if ($del->rowCount()) {
                $deletados++;
            }
        }

        return [
            'success' => true,
            'message' => $deletados . ' produto(s) removido(s) (mais antigos que ' . $diasExpiracao . ' dias).',
            'deletados' => $deletados,
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage(), 'deletados' => 0];
    }
}

/**
 * Config de cron externo por loja (automacoes_cron).
 *
 * @return array{token: string, intervalo_minutos: int, hora_inicio: int, hora_fim: int, dias_remocao: int, cron_job_id: string, cron_individual_ativo: int}
 */
function dadosCronLoja(string $loja): array {
    $defaults = [
        'token' => '',
        'intervalo_minutos' => 5,
        'hora_inicio' => 0,
        'hora_fim' => 23,
        'dias_remocao' => 30,
        'cron_job_id' => '',
        'cron_individual_ativo' => 0,
    ];
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return $defaults;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT token, intervalo_minutos, hora_inicio, hora_fim, dias_remocao, cron_job_id, cron_individual_ativo
             FROM automacoes_cron WHERE loja = ? LIMIT 1'
        );
        $stmt->execute([$loja]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        return [
            'token' => trim((string) ($row['token'] ?? '')),
            'intervalo_minutos' => CronPolicy::normalizeInterval((int) ($row['intervalo_minutos'] ?? 5)),
            'hora_inicio' => max(0, min(23, (int) ($row['hora_inicio'] ?? 0))),
            'hora_fim' => max(0, min(23, (int) ($row['hora_fim'] ?? 23))),
            'dias_remocao' => max(1, min(365, (int) ($row['dias_remocao'] ?? 30))),
            'cron_job_id' => trim((string) ($row['cron_job_id'] ?? '')),
            'cron_individual_ativo' => !empty($row['cron_individual_ativo']) ? 1 : 0,
        ];
    } catch (Exception $e) {
        return $defaults;
    }
}

/** Se a loja usa cron-job.org / rodar-loja.php (quando desligado, entra em rodar-tudo.php). */
function cronLojaUsaAgendamentoIndividual(string $loja): bool {
    return !empty(dadosCronLoja($loja)['cron_individual_ativo']);
}

/**
 * Persiste apenas campos de cron externo (upsert por loja).
 *
 * @param array<string, mixed> $d token, intervalo_minutos, hora_inicio, hora_fim, dias_remocao, cron_job_id, cron_individual_ativo (0|1)
 */
function salvarCronExternoLoja(string $loja, array $d): void {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return;
    }
    $token = trim((string) ($d['token'] ?? ''));
    $iv = CronPolicy::normalizeInterval((int) ($d['intervalo_minutos'] ?? 5));
    $h1 = max(0, min(23, (int) ($d['hora_inicio'] ?? 0)));
    $h2 = max(0, min(23, (int) ($d['hora_fim'] ?? 23)));
    $dias = max(1, min(365, (int) ($d['dias_remocao'] ?? 30)));
    $indiv = !empty($d['cron_individual_ativo']) ? 1 : 0;
    $jid = array_key_exists('cron_job_id', $d)
        ? (trim((string) $d['cron_job_id']) === '' ? null : trim((string) $d['cron_job_id']))
        : null;

    try {
        $pdo = getDB();
        $sql = 'INSERT INTO automacoes_cron (loja, token, intervalo_minutos, hora_inicio, hora_fim, dias_remocao, cron_job_id, cron_individual_ativo)
            VALUES (:loja, :token, :iv, :h1, :h2, :dias, :jid, :indiv)
            ON DUPLICATE KEY UPDATE
                token = VALUES(token),
                intervalo_minutos = VALUES(intervalo_minutos),
                hora_inicio = VALUES(hora_inicio),
                hora_fim = VALUES(hora_fim),
                dias_remocao = VALUES(dias_remocao),
                cron_job_id = VALUES(cron_job_id),
                cron_individual_ativo = VALUES(cron_individual_ativo)';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('loja', $loja, PDO::PARAM_STR);
        $stmt->bindValue('token', $token, PDO::PARAM_STR);
        $stmt->bindValue('iv', $iv, PDO::PARAM_INT);
        $stmt->bindValue('h1', $h1, PDO::PARAM_INT);
        $stmt->bindValue('h2', $h2, PDO::PARAM_INT);
        $stmt->bindValue('dias', $dias, PDO::PARAM_INT);
        $stmt->bindValue('indiv', $indiv, PDO::PARAM_INT);
        if ($jid === null) {
            $stmt->bindValue('jid', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue('jid', $jid, PDO::PARAM_STR);
        }
        $stmt->execute();
    } catch (Exception $e) {
        error_log('salvarCronExternoLoja: ' . $e->getMessage());
    }
}

/**
 * Processa aba Crons a partir de um array tipo POST (usado pelo POST normal e pelo autosave JSON).
 */
function painelProcessarCronLojaFromArray(string $loja, array $postLike): string {
    if (!isset($postLike['cron_painel_presente'])) {
        return '';
    }

    require_once __DIR__ . '/../core/cron/CronJobService.php';

    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return '';
    }

    $cfgAntes = dadosCronLoja($loja);
    if (!array_key_exists('cron_individual_ativo', $postLike)) {
        $postLike['cron_individual_ativo'] = !empty($cfgAntes['cron_individual_ativo']) ? '1' : '0';
    }
    $rawInd = $postLike['cron_individual_ativo'] ?? null;
    $individual = $rawInd === true || $rawInd === 1 || $rawInd === '1';
    $token = trim((string) ($postLike['cron_token'] ?? ''));
    if (array_key_exists('cron_intervalo_minutos', $postLike)) {
        $iv = CronPolicy::normalizeInterval((int) $postLike['cron_intervalo_minutos']);
    } else {
        $iv = CronPolicy::normalizeInterval((int) ($postLike['cron_intervalo'] ?? 5));
    }
    if (array_key_exists('cron_hora_inicio', $postLike)) {
        $h1 = max(0, min(23, (int) $postLike['cron_hora_inicio']));
    } else {
        $h1 = max(0, min(23, (int) ($postLike['cron_inicio'] ?? 0)));
    }
    if (array_key_exists('cron_hora_fim', $postLike)) {
        $h2 = max(0, min(23, (int) $postLike['cron_hora_fim']));
    } else {
        $h2 = max(0, min(23, (int) ($postLike['cron_fim'] ?? 23)));
    }
    if (array_key_exists('cron_dias_remocao', $postLike)) {
        $dias = max(1, min(365, (int) $postLike['cron_dias_remocao']));
    } else {
        $dias = max(1, min(365, (int) ($postLike['cron_remocao'] ?? 30)));
    }
    $jobPosted = trim((string) ($postLike['cron_job_id'] ?? ''));

    $avisos = [];
    $apiKey = trim((string) getConfig('cron_job_org_api_key', ''));

    if (!$individual) {
        if ($apiKey !== '' && $cfgAntes['cron_job_id'] !== '') {
            $del = cronJobDelete($cfgAntes['cron_job_id']);
            if (!$del['success']) {
                $avisos[] = 'cron-job.org (remover job): ' . $del['message'];
            }
        }
        $jobPosted = '';
        // Modo global na loja: mantém token/intervalo/horas/dias em automacoes_cron (útil se reativar exclusiva); só limpa job na cron-job.org e cron_job_id.
        salvarCronExternoLoja($loja, [
            'cron_individual_ativo' => 0,
            'token' => $cfgAntes['token'],
            'intervalo_minutos' => $cfgAntes['intervalo_minutos'],
            'hora_inicio' => $cfgAntes['hora_inicio'],
            'hora_fim' => $cfgAntes['hora_fim'],
            'dias_remocao' => $cfgAntes['dias_remocao'],
            'cron_job_id' => '',
        ]);
    } else {
        salvarCronExternoLoja($loja, [
            'cron_individual_ativo' => 1,
            'token' => $token,
            'intervalo_minutos' => $iv,
            'hora_inicio' => $h1,
            'hora_fim' => $h2,
            'dias_remocao' => $dias,
            'cron_job_id' => $jobPosted,
        ]);
    }

    $syncNow = isset($postLike['cron_sync_now']) && (string) $postLike['cron_sync_now'] === '1';
    if ($apiKey === '' || !$individual || !$syncNow) {
        return implode(' ', $avisos);
    }

    $cfg = dadosCronLoja($loja);
    $sync = cronJobSincronizarLoja($loja, $cfg);
    if (!$sync['success']) {
        $avisos[] = 'cron-job.org: ' . $sync['message'];
    } elseif (!empty($sync['job_id'])) {
        salvarCronExternoLoja($loja, array_merge($cfg, ['cron_job_id' => (string) $sync['job_id']]));
    }

    return implode(' ', $avisos);
}

/**
 * Status visual de sincronização da cron-job.org.
 *
 * @return array{status: string, texto: string, cor: string}
 */
function cronStatusTexto(?string $jobId): array
{
    $jid = trim((string) $jobId);
    if ($jid === '') {
        return [
            'status' => 'inativo',
            'texto' => '⚠️ Cron ainda não sincronizada',
            'cor' => 'yellow',
        ];
    }

    return [
        'status' => 'ativo',
        'texto' => '🟢 Cron sincronizada com sucesso',
        'cor' => 'green',
    ];
}

// -----------------------------------------------------------------------------
// Monitoramento de crons (histórico, atraso, lock, alertas)
// -----------------------------------------------------------------------------

/**
 * Diretório para locks de execução (evita rodar o mesmo cron em paralelo).
 */
function cronMonitorLocksDir(): string {
    return __DIR__ . '/../storage/cron_locks';
}

/**
 * Regista shutdown uma vez: em erro fatal o bloco finally pode não correr; libertamos locks pendentes.
 */
function cronMonitorRegistrarShutdownLiberacaoLocks(): void {
    if (!empty($GLOBALS['__achadinhos_cron_lock_shutdown_registered'])) {
        return;
    }
    $GLOBALS['__achadinhos_cron_lock_shutdown_registered'] = true;
    register_shutdown_function(static function (): void {
        $handles = $GLOBALS['__achadinhos_cron_lock_handles'] ?? null;
        if (!is_array($handles) || $handles === []) {
            return;
        }
        foreach ($handles as $fh) {
            if (is_resource($fh)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
        }
        $GLOBALS['__achadinhos_cron_lock_handles'] = [];
    });
}

/**
 * @param resource $fh
 */
function cronMonitorRemoverHandleDoShutdownLock($fh): void {
    if (!is_resource($fh)) {
        return;
    }
    $list = &$GLOBALS['__achadinhos_cron_lock_handles'];
    if (!isset($list) || !is_array($list)) {
        return;
    }
    $key = array_search($fh, $list, true);
    if ($key !== false) {
        unset($list[$key]);
    }
}

/**
 * @return array{ok: bool, fh: resource|null, path: string}
 */
function cronMonitorAdquirirLock(string $chave): array {
    $dir = cronMonitorLocksDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/[^a-z0-9_\-]/', '', strtolower($chave));
    if ($safe === '') {
        $safe = 'default';
    }
    $path = $dir . '/' . $safe . '.lock';
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return ['ok' => false, 'fh' => null, 'path' => $path];
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return ['ok' => false, 'fh' => null, 'path' => $path];
    }

    if (!isset($GLOBALS['__achadinhos_cron_lock_handles']) || !is_array($GLOBALS['__achadinhos_cron_lock_handles'])) {
        $GLOBALS['__achadinhos_cron_lock_handles'] = [];
    }
    $GLOBALS['__achadinhos_cron_lock_handles'][] = $fh;
    cronMonitorRegistrarShutdownLiberacaoLocks();

    return ['ok' => true, 'fh' => $fh, 'path' => $path];
}

/**
 * @param resource|null $fh
 */
function cronMonitorLiberarLock($fh): void {
    if (!is_resource($fh)) {
        return;
    }
    cronMonitorRemoverHandleDoShutdownLock($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Intervalo esperado (minutos) para checagem de atraso — alinhado às configs do painel.
 */
function cronMonitorIntervaloEsperadoMinutos(string $tipo, ?string $loja): int {
    if ($tipo === 'loja' && $loja !== null && $loja !== '') {
        $lojaN = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
        if ($lojaN !== '' && !empty(dadosCronLoja($lojaN)['cron_individual_ativo'])) {
            return CronPolicy::normalizeInterval((int) dadosCronLoja($lojaN)['intervalo_minutos']);
        }
    }

    return CronPolicy::normalizeInterval((int) getConfig('cron_intervalo_minutos', '5'));
}

/**
 * Mantém apenas os últimos N registros na tabela de monitoramento.
 */
function cronMonitorPodarExecucoes(int $maxLinhas = 1000): void {
    if ($maxLinhas < 100) {
        $maxLinhas = 100;
    }
    try {
        $pdo = getDB();
        $n = (int) $pdo->query('SELECT COUNT(*) FROM cron_execucoes')->fetchColumn();
        if ($n <= $maxLinhas) {
            return;
        }
        $excesso = $n - $maxLinhas;
        $pdo->exec('DELETE FROM cron_execucoes ORDER BY id ASC LIMIT ' . (int) $excesso);
    } catch (Exception $e) {
        error_log('cronMonitorPodarExecucoes: ' . $e->getMessage());
    }
}

/**
 * Reserva futura: envio Telegram quando configurado (cron_monitor_telegram_* em configuracoes).
 */
function cronMonitorNotificarErroSeConfigurado(string $contexto, string $mensagemCurta): void {
    $hook = trim((string) getConfig('cron_monitor_telegram_webhook', ''));
    if ($hook === '') {
        return;
    }
    // Implementação opcional: POST no webhook ou usar enviarTelegram existente quando chat_id global existir.
}

/**
 * Diretório de logs NDJSON do cron (gitignored em storage/*).
 */
function cronRunLogsDir(): string {
    $d = __DIR__ . '/../storage/logs';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }

    return $d;
}

/**
 * Uma linha NDJSON por evento (auditoria; rotação por nome do arquivo = dia).
 *
 * @param array<string, mixed> $payload
 */
function cronRunAppendNdjsonLog(string $evento, array $payload): void {
    $payload['_event'] = $evento;
    $payload['_ts'] = gmdate('c');
    $line = json_encode($payload, achadinhosCronJsonFlags() | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = achadinhosCronJsonEncode(['_event' => $evento, '_ts' => $payload['_ts'], 'log_truncado' => true], JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";
    $path = cronRunLogsDir() . '/cron-runs-' . gmdate('Y-m-d') . '.ndjson';
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Se a última execução global OK estiver atrasada vs. intervalo esperado (×3, mín. 5 min), regista alerta em ficheiro (no máx. 1×/h).
 * Chamado por health.php e opcionalmente no painel — não bloqueia crons.
 */
function cronMonitorRegistrarStaleSeAtrasoGlobal(): array {
    $ivMin = cronMonitorIntervaloEsperadoMinutos('global', null);
    $thresholdSec = max(300, $ivMin * 60 * 3);
    $tsOk = cronMonitorUltimoSucessoGlobalTs();
    $tsAny = cronMonitorUltimaExecucaoGlobalQualquerTs();
    $ref = $tsOk ?? $tsAny;
    $now = time();
    $segundos = $ref !== null ? ($now - $ref) : null;
    $stale = $ref !== null && $segundos !== null && $segundos > $thresholdSec;

    if ($stale) {
        $lastHour = (int) trim((string) getConfig('cron_stale_last_log_unix', '0'));
        if ($lastHour <= 0 || ($now - $lastHour) >= 3600) {
            setConfig('cron_stale_last_log_unix', (string) $now);
            cronRunAppendNdjsonLog('stale_alert', [
                'threshold_sec' => $thresholdSec,
                'intervalo_esperado_min' => $ivMin,
                'segundos_desde_ref' => $segundos,
                'ultimo_sucesso_unix' => $tsOk,
                'ultima_exec_qualquer_unix' => $tsAny,
            ]);
            error_log(sprintf(
                'achadinhos cron STALE: sem sucesso global há %ds (limite %ds, intervalo cfg %d min)',
                $segundos,
                $thresholdSec,
                $ivMin
            ));
        }
    }

    return [
        'stale' => $stale,
        'threshold_seconds' => $thresholdSec,
        'intervalo_esperado_minutos' => $ivMin,
        'segundos_desde_ultimo_sucesso' => $tsOk !== null ? ($now - $tsOk) : null,
        'segundos_desde_ultima_execucao' => $tsAny !== null ? ($now - $tsAny) : null,
    ];
}

/**
 * Payload JSON para GET /cron/health.php (monitorização externa).
 *
 * @return array<string, mixed>
 */
function cronHealthPayload(): array {
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirTabelaCronExecucoes();
    garantirColunaCronExecucoesDetalhesJson();

    $staleInfo = cronMonitorRegistrarStaleSeAtrasoGlobal();
    $tsOk = cronMonitorUltimoSucessoGlobalTs();
    $tsAny = cronMonitorUltimaExecucaoGlobalQualquerTs();
    $now = time();

    $publicBase = '';
    if (function_exists('cronPublicBaseUrl')) {
        require_once __DIR__ . '/../core/cron/CronJobService.php';
        $publicBase = cronPublicBaseUrl();
    }

    $cronTokenSet = trim((string) getConfig('cron_token', '')) !== '';
    $locksDir = cronMonitorLocksDir();
    $locksOk = is_dir($locksDir) && is_writable($locksDir);

    $alertaCfg = getConfig('cron_monitor_alerta_global', '0') === '1';
    $heartbeatOk = !$staleInfo['stale'];

    return [
        'ok' => $heartbeatOk && !$alertaCfg,
        'heartbeat_ok' => $heartbeatOk,
        'time_utc' => gmdate('c'),
        'php_timezone' => date_default_timezone_get(),
        'cron_public_base_url_configured' => $publicBase !== '',
        'cron_public_base_url_preview' => $publicBase !== '' ? $publicBase : null,
        'cron_token_configured' => $cronTokenSet,
        'cron_job_org_integracao' => function_exists('cronMonitorIntegracaoCronJobOrgAtiva') ? cronMonitorIntegracaoCronJobOrgAtiva() : false,
        'ultimo_sucesso_global_unix' => $tsOk,
        'ultima_execucao_global_qualquer_unix' => $tsAny,
        'segundos_desde_ultimo_sucesso' => $tsOk !== null ? ($now - $tsOk) : null,
        'stale' => $staleInfo['stale'],
        'stale_threshold_seconds' => $staleInfo['threshold_seconds'],
        'alerta_global_config' => getConfig('cron_monitor_alerta_global', '0') === '1',
        'alerta_global_msg' => trim((string) getConfig('cron_monitor_alerta_global_msg', '')),
        'locks_dir_writable' => $locksOk,
        'dispatch_ativo_producao' => function_exists('dispatch_habilitado') ? (getConfig('dispatch_ativo_producao', '0') === '1') : null,
    ];
}

/**
 * Registra uma execução de cron global, por loja ou por grupo WhatsApp e atualiza flags de alerta quando aplicável.
 *
 * @param array{tipo: string, loja?: ?string, grupo_whatsapp_id?: ?int, status: string, mensagem?: string, tempo_execucao?: int, detalhes?: ?array} $dados
 */
function registrarExecucaoCron(array $dados): void {
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirTabelaCronExecucoes();
    garantirColunaCronExecucoesDetalhesJson();
    garantirCronExecucoesTipoGrupoStatusPulado();
    garantirColunaCronExecucoesGrupoWhatsappId();

    $tipoRaw = strtolower(trim((string) ($dados['tipo'] ?? 'global')));
    $tipo = ($tipoRaw === 'loja' || $tipoRaw === 'grupo') ? $tipoRaw : 'global';
    $loja = null;
    if ($tipo === 'loja' && isset($dados['loja']) && (string) $dados['loja'] !== '') {
        $loja = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $dados['loja']));
        if ($loja === '') {
            $loja = null;
        }
    }
    $grupoWhatsappId = null;
    if ($tipo === 'grupo') {
        if (isset($dados['grupo_whatsapp_id']) && (int) $dados['grupo_whatsapp_id'] > 0) {
            $grupoWhatsappId = (int) $dados['grupo_whatsapp_id'];
        } elseif (!empty($dados['detalhes']) && is_array($dados['detalhes']) && isset($dados['detalhes']['grupo_id']) && (int) $dados['detalhes']['grupo_id'] > 0) {
            $grupoWhatsappId = (int) $dados['detalhes']['grupo_id'];
        }
    }
    $statusRaw = strtolower(trim((string) ($dados['status'] ?? 'sucesso')));
    if ($statusRaw === 'erro') {
        $status = 'erro';
    } elseif ($statusRaw === 'pulado' || $statusRaw === 'skipped') {
        $status = 'pulado';
    } else {
        $status = 'sucesso';
    }
    $msg = isset($dados['mensagem']) ? (string) $dados['mensagem'] : '';
    if (function_exists('mb_strlen') && mb_strlen($msg, 'UTF-8') > 65000) {
        $msg = mb_substr($msg, 0, 64990, 'UTF-8') . '…';
    } elseif (strlen($msg) > 65000) {
        $msg = substr($msg, 0, 64990) . '…';
    }
    $ms = max(0, (int) ($dados['tempo_execucao'] ?? 0));

    $detJson = null;
    if (!empty($dados['detalhes']) && is_array($dados['detalhes'])) {
        $detJson = json_encode($dados['detalhes'], achadinhosCronJsonFlags() | JSON_UNESCAPED_SLASHES);
        if ($detJson === false) {
            $detJson = achadinhosCronJsonEncode(['truncado' => true, 'nota' => 'detalhes não serializáveis (UTF-8/tipos)'], JSON_UNESCAPED_SLASHES);
        }
        if (strlen($detJson) > 16777000) {
            $detJson = achadinhosCronJsonEncode(['truncado' => true, 'nota' => 'detalhes demasiado grandes'], JSON_UNESCAPED_SLASHES);
        }
    }

    try {
        $pdo = getDB();
        $hasGwCol = colunaExiste('cron_execucoes', 'grupo_whatsapp_id');
        $gwVal = ($tipo === 'grupo' && $grupoWhatsappId !== null && $grupoWhatsappId > 0) ? $grupoWhatsappId : null;
        if ($detJson !== null && $detJson !== '' && colunaExiste('cron_execucoes', 'detalhes_json')) {
            if ($hasGwCol) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cron_execucoes (tipo, loja, grupo_whatsapp_id, status, mensagem, detalhes_json, tempo_execucao, data_execucao)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([$tipo, $loja, $gwVal, $status, $msg, $detJson, $ms]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO cron_execucoes (tipo, loja, status, mensagem, detalhes_json, tempo_execucao, data_execucao)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([$tipo, $loja, $status, $msg, $detJson, $ms]);
            }
        } else {
            if ($hasGwCol) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cron_execucoes (tipo, loja, grupo_whatsapp_id, status, mensagem, tempo_execucao, data_execucao)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([$tipo, $loja, $gwVal, $status, $msg, $ms]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO cron_execucoes (tipo, loja, status, mensagem, tempo_execucao, data_execucao)
                     VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([$tipo, $loja, $status, $msg, $ms]);
            }
        }
    } catch (Exception $e) {
        error_log('registrarExecucaoCron: ' . $e->getMessage());
        return;
    }

    cronRunAppendNdjsonLog('execucao_cron', [
        'tipo' => $tipo,
        'loja' => $loja,
        'grupo_whatsapp_id' => $grupoWhatsappId,
        'status' => $status,
        'tempo_ms' => $ms,
        'mensagem_resumo' => substr($msg, 0, 400),
    ]);

    if ($tipo === 'global') {
        $ts = (string) time();
        setConfig('cron_global_last_run_unix', $ts);
        if ($status === 'sucesso') {
            setConfig('cron_global_last_success_unix', $ts);
        }
        if ($status === 'erro') {
            setConfig('cron_monitor_alerta_global', '1');
            setConfig('cron_monitor_alerta_global_msg', substr($msg, 0, 500));
            cronMonitorNotificarErroSeConfigurado('global', substr($msg, 0, 300));
        } else {
            setConfig('cron_monitor_alerta_global', '0');
            setConfig('cron_monitor_alerta_global_msg', '');
        }
    } elseif ($tipo === 'loja' && $loja !== null) {
        $pref = 'cron_monitor_alerta_loja_' . $loja;
        if ($status === 'erro') {
            setConfig($pref, '1');
            setConfig($pref . '_msg', substr($msg, 0, 500));
            cronMonitorNotificarErroSeConfigurado('loja:' . $loja, substr($msg, 0, 300));
        } else {
            setConfig($pref, '0');
            setConfig($pref . '_msg', '');
        }
    }

    cronMonitorPodarExecucoes(1000);
}

/**
 * Integração cron-job.org considerada ligada: basta a chave API guardada.
 * Os jobs são criados por grupo (cron/rodar-grupo.php); não exige cron_global_job_id.
 */
function cronMonitorIntegracaoCronJobOrgAtiva(): bool {
    return trim((string) getConfig('cron_job_org_api_key', '')) !== '';
}

/**
 * Timestamp UNIX da última execução global com status sucesso (heartbeat “saudável”).
 */
function cronMonitorUltimoSucessoGlobalTs(): ?int {
    $u = trim((string) getConfig('cron_global_last_success_unix', ''));
    if ($u !== '' && ctype_digit($u)) {
        $t = (int) $u;
        if ($t > 0) {
            return $t;
        }
    }
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirTabelaCronExecucoes();
    try {
        $pdo = getDB();
        $stmt = $pdo->query(
            "SELECT UNIX_TIMESTAMP(data_execucao) AS ts FROM cron_execucoes WHERE tipo = 'global' AND status = 'sucesso' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row['ts']) && (int) $row['ts'] > 0) {
            return (int) $row['ts'];
        }
    } catch (Exception $e) {
    }

    return null;
}

/**
 * Última tentativa de execução global (sucesso ou erro).
 */
function cronMonitorUltimaExecucaoGlobalQualquerTs(): ?int {
    $u = trim((string) getConfig('cron_global_last_run_unix', ''));
    if ($u !== '' && ctype_digit($u)) {
        $t = (int) $u;
        if ($t > 0) {
            return $t;
        }
    }
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirTabelaCronExecucoes();
    try {
        $pdo = getDB();
        $stmt = $pdo->query(
            "SELECT UNIX_TIMESTAMP(data_execucao) AS ts FROM cron_execucoes WHERE tipo = 'global' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row['ts']) && (int) $row['ts'] > 0) {
            return (int) $row['ts'];
        }
    } catch (Exception $e) {
    }

    return null;
}

function cronMonitorHumanizarAtrasPt(?int $ts): string {
    if ($ts === null || $ts <= 0) {
        return 'nunca registrada';
    }
    $sec = time() - $ts;
    if ($sec < 45) {
        return 'há instantes';
    }
    if ($sec < 3600) {
        $m = max(1, (int) floor($sec / 60));

        return 'há ' . $m . ' min';
    }
    if ($sec < 86400) {
        $h = max(1, (int) floor($sec / 3600));

        return 'há ' . $h . ' h';
    }
    $d = max(1, (int) floor($sec / 86400));

    return 'há ' . $d . ' dia(s)';
}

/**
 * Verifica se a última execução excede 2× o intervalo esperado (apenas lojas — lógica legada).
 */
function cronEstaAtrasado(string $tipo, ?string $loja): bool {
    if ($tipo !== 'loja') {
        return false;
    }
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirTabelaCronExecucoes();

    $lojaNorm = null;
    if ($loja !== null && $loja !== '') {
        $lojaNorm = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
        if ($lojaNorm === '') {
            return false;
        }
    } else {
        return false;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT data_execucao FROM cron_execucoes WHERE tipo = 'loja' AND loja = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$lojaNorm]);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row || empty($row['data_execucao'])) {
            return false;
        }
        $last = strtotime((string) $row['data_execucao']);
        if ($last === false) {
            return false;
        }
        $ivMin = cronMonitorIntervaloEsperadoMinutos('loja', $lojaNorm);
        $limiteSeg = 2 * max(60, $ivMin * 60);

        return (time() - $last) > $limiteSeg;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Resumo para o painel (global: execução real + cron-job.org; lojas: atraso legado).
 *
 * @return array<string, mixed>
 */
function cronMonitorPainelStatus(string $tipo, ?string $loja): array {
    if ($tipo === 'global' || $loja === null || $tipo !== 'loja') {
        return cronMonitorPainelStatusGlobal();
    }

    $atrasado = cronEstaAtrasado('loja', $loja);
    $flagErro = false;
    $detalhe = '';
    $lx = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($lx !== '') {
        $flagErro = getConfig('cron_monitor_alerta_loja_' . $lx, '0') === '1';
        $detalhe = trim((string) getConfig('cron_monitor_alerta_loja_' . $lx . '_msg', ''));
    }

    $semHistorico = false;
    try {
        require_once __DIR__ . '/../core/db/SchemaHelper.php';
        garantirTabelaCronExecucoes();
        $pdo = getDB();
        $st = $pdo->prepare('SELECT 1 FROM cron_execucoes WHERE tipo = \'loja\' AND loja = ? LIMIT 1');
        $st->execute([$lx]);
        $semHistorico = $st->fetchColumn() === false;
    } catch (Exception $e) {
        $semHistorico = true;
    }

    if ($semHistorico && !$flagErro) {
        $out = [
            'ok' => true,
            'atrasado' => false,
            'flag_erro' => false,
            'nivel' => 'unknown',
            'texto' => '🟡 Sem registro de execução ainda',
            'status_global_linha' => '🟡 Sem registro de execução ainda',
        ];
        if ($detalhe !== '') {
            $out['detalhe'] = $detalhe;
        }

        return $out;
    }

    $ok = !$atrasado && !$flagErro;
    $texto = $ok ? '🟢 Funcionando normalmente' : ($flagErro ? '🔴 Última execução com erro' : '🟡 Execução possivelmente atrasada');

    $out = [
        'ok' => $ok,
        'atrasado' => $atrasado,
        'flag_erro' => $flagErro,
        'nivel' => $ok ? 'ok' : ($flagErro ? 'bad' : 'warn'),
        'texto' => $texto,
        'status_global_linha' => $texto,
    ];
    if ($detalhe !== '') {
        $out['detalhe'] = $detalhe;
    }

    return $out;
}

/**
 * Painel global: última execução bem-sucedida, probe cron-job.org, sem “parado” sem evidência.
 *
 * @return array<string, mixed>
 */
function cronMonitorPainelStatusGlobal(): array {
    $flagErro = getConfig('cron_monitor_alerta_global', '0') === '1';
    $detalhe = trim((string) getConfig('cron_monitor_alerta_global_msg', ''));
    $integracao = cronMonitorIntegracaoCronJobOrgAtiva();
    $jobId = trim((string) getConfig('cron_global_job_id', ''));

    $tsOk = cronMonitorUltimoSucessoGlobalTs();
    $tsAny = cronMonitorUltimaExecucaoGlobalQualquerTs();
    $ivMin = cronMonitorIntervaloEsperadoMinutos('global', null);
    $limOkSeg = max(600, $ivMin * 60);
    $limWarnSeg = max(1800, 3 * $ivMin * 60);

    $orgOk = true;
    $orgHabilitado = true;
    $orgMsg = '';
    $urlPublicRisk = false;
    if ($integracao) {
        require_once __DIR__ . '/../core/cron/CronJobService.php';
        $ju = cronJobUrlRodarTudoComQueryToken();
        if ($ju === '') {
            $ju = cronJobUrlRodarTudo();
        }
        $urlPublicRisk = ($ju !== '' && !isCronDevEnvironment() && cronJobOrgValidateJobUrlForExternalCron($ju) !== null);
        if ($jobId === '') {
            $orgOk = true;
            $orgHabilitado = true;
            $orgMsg = '';
        } else {
            $rem = cronJobEstadoRemotoJobArmazenado($jobId);
            $orgOk = $rem['ok'];
            $orgHabilitado = $rem['habilitado'];
            $orgMsg = (string) ($rem['mensagem'] ?? '');
        }
    }

    $ageOk = $tsOk !== null ? (time() - $tsOk) : null;

    $nivel = 'off';
    $statusLinha = '🔴 Desativado ou sem integração';
    $texto = $statusLinha;

    if (!$integracao) {
        $nivel = 'off';
        $statusLinha = '🔴 Desativado ou sem integração na cron-job.org';
        $texto = $statusLinha;
    } elseif ($integracao && trim((string) getConfig('cron_global_job_id', '')) === '') {
        $nivel = 'unknown';
        $statusLinha = '🟡 Chave API ativa — agendamentos por grupo (sem job global rodar-tudo)';
        $texto = $statusLinha;
    } elseif (!$orgOk) {
        $nivel = 'org_err';
        $statusLinha = '🔴 Erro ao consultar job na cron-job.org';
        $texto = $statusLinha . ($orgMsg !== '' ? ': ' . $orgMsg : '');
    } elseif (!$orgHabilitado) {
        $nivel = 'warn';
        $statusLinha = '🟡 Job existe na cron-job.org, mas está pausado lá';
        $texto = $statusLinha;
    } elseif ($ageOk === null) {
        $nivel = 'unknown';
        $statusLinha = '🟡 Integração ativa — aguardando primeira execução registrada no site';
        $texto = $statusLinha;
    } elseif ($flagErro && $tsAny !== null && $tsOk !== null && $tsAny > $tsOk) {
        $nivel = 'warn';
        $statusLinha = '🟡 Integração ativa — última rodada registrou erro';
        $texto = $statusLinha;
    } elseif ($ageOk <= $limOkSeg) {
        $nivel = 'ok';
        $statusLinha = '🟢 Ativado e em execução';
        $texto = $statusLinha;
    } elseif ($ageOk <= $limWarnSeg) {
        $nivel = 'warn';
        $statusLinha = '🟡 Ativado, mas instável (último sucesso há mais tempo que o esperado)';
        $texto = $statusLinha;
    } else {
        $nivel = 'bad';
        $statusLinha = '🔴 Sem execução recente de sucesso — verifique o agendador';
        $texto = $statusLinha;
    }

    if ($integracao && $orgOk && $orgHabilitado && $nivel === 'bad') {
        $statusLinha .= ' (job ainda ativo na cron-job.org — confira URL/resposta do servidor)';
        $texto = $statusLinha;
    }

    if ($integracao && $urlPublicRisk) {
        if ($nivel === 'ok') {
            $nivel = 'warn';
            $statusLinha = '🟡 API e job na cron-job.org parecem OK, mas o host da URL do cron é local ou não público — execuções externas podem falhar (DNS).';
            $texto = $statusLinha;
        } elseif ($nivel === 'unknown' || $nivel === 'warn') {
            if (strpos($statusLinha, 'DNS') === false && strpos($statusLinha, 'não público') === false) {
                $statusLinha .= ' Nota: o host da URL configurada parece não resolver na Internet pública.';
                $texto = $statusLinha;
            }
        } elseif ($nivel === 'bad') {
            if (strpos($statusLinha, 'URL base') === false) {
                $statusLinha .= ' Confirme também a URL base pública (domínio real, não .test/localhost).';
                $texto = $statusLinha;
            }
        }
    }

    $ok = ($nivel === 'ok');

    $out = [
        'ok' => $ok,
        'atrasado' => $nivel === 'bad',
        'flag_erro' => $flagErro,
        'nivel' => $nivel,
        'texto' => $texto,
        'status_global_linha' => $statusLinha,
        'integracao_org' => $integracao,
        'url_public_dns_risk' => $urlPublicRisk,
        'job_id' => $jobId,
        'org_api_ok' => $orgOk,
        'org_job_habilitado' => $orgHabilitado,
        'org_mensagem' => $orgMsg,
        'ultima_execucao_ts' => $tsAny,
        'ultima_execucao_ok_ts' => $tsOk,
        'ultima_execucao_human' => cronMonitorHumanizarAtrasPt($tsAny),
        'ultima_execucao_ok_human' => cronMonitorHumanizarAtrasPt($tsOk),
    ];
    if ($detalhe !== '') {
        $out['detalhe'] = $detalhe;
    }

    return $out;
}

/**
 * Processa aba Crons no POST (salva automacoes_cron + sincroniza cron-job.org se houver API key).
 */
function painelProcessarCronLojaNoPost(string $loja): string {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return '';
    }
    if (!isset($_POST['cron_painel_presente'])) {
        return '';
    }
    return painelProcessarCronLojaFromArray($loja, $_POST);
}

/**
 * Consulta estado da instância na Evolution API (somente leitura).
 *
 * @return array{ok: bool, connected: bool, state: string, instancia: string}
 */
function evolutionObterEstadoInstancia(?string $urlBase, ?string $instancia, ?string $apiKey): array {
    $out = ['ok' => false, 'connected' => false, 'state' => '', 'instancia' => (string) ($instancia ?? '')];
    if ($urlBase === null || $instancia === null || $apiKey === null) {
        return $out;
    }
    $urlBase = rtrim(trim($urlBase), '/');
    $instancia = trim($instancia);
    $apiKey = trim($apiKey);
    if ($urlBase === '' || $instancia === '' || $apiKey === '') {
        return $out;
    }
    $url = $urlBase . '/instance/connectionState/' . rawurlencode($instancia);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) {
        return $out;
    }
    $j = json_decode($raw, true);
    $out['ok'] = true;
    $state = '';
    if (is_array($j)) {
        if (isset($j['instance']['state'])) {
            $state = (string) $j['instance']['state'];
        } elseif (isset($j['state'])) {
            $state = (string) $j['state'];
        } elseif (isset($j['instance']['connectionStatus'])) {
            $state = (string) $j['instance']['connectionStatus'];
        }
    }
    $out['state'] = $state;
    $out['connected'] = $state !== '' && stripos($state, 'open') !== false;

    return $out;
}

/**
 * Estado da conexão WhatsApp (Evolution ou Uazapi).
 *
 * @param array $conta linha evolution_contas
 * @return array{ok: bool, connected: bool, state: string, instancia: string}
 */
function whatsAppObterEstadoConta(array $conta): array {
    $provedor = $conta['provedor'] ?? 'evolution';
    if ($provedor === 'uazapi') {
        require_once __DIR__ . '/uazapi_whatsapp.php';
        $r = uazapiObterEstadoInstancia(
            (string) ($conta['url_base'] ?? ''),
            (string) ($conta['api_key'] ?? ''),
            uazapiResolverAdminToken($conta)
        );

        return [
            'ok' => $r['ok'],
            'connected' => $r['connected'],
            'state' => $r['state'],
            'instancia' => (string) ($conta['instancia'] ?? ''),
        ];
    }

    return evolutionObterEstadoInstancia(
        $conta['url_base'] ?? null,
        $conta['instancia'] ?? null,
        $conta['api_key'] ?? null
    );
}

/**
 * Sufixo para selects/listas: " (Evolution · API própria)", " (Uazapi · Provedor externo)", etc.
 *
 * @param array<string, mixed> $conta Linha ou trecho com provedor e api_propria
 */
function achadinhosEvolutionContaTipoSufixo(array $conta): string
{
    $isUaz = (($conta['provedor'] ?? 'evolution') === 'uazapi');
    $apiPropria = !empty($conta['api_propria'] ?? 0);
    $prov = $isUaz ? 'Uazapi' : 'Evolution';
    $modo = $apiPropria ? 'API própria' : 'Provedor externo';

    return ' (' . $prov . ' · ' . $modo . ')';
}

/**
 * HTML dos dois chips (API + modo) na coluna Nome das contas WhatsApp.
 *
 * @param array<string, mixed> $conta Linha evolution_contas
 */
function achadinhosEvolutionContaBadgesHtml(array $conta): string
{
    $isUaz = (($conta['provedor'] ?? 'evolution') === 'uazapi');
    $apiPropria = !empty($conta['api_propria'] ?? 0);
    $modo = $apiPropria ? 'API própria' : 'Provedor externo';
    $modoH = htmlspecialchars($modo, ENT_QUOTES, 'UTF-8');
    $modoClass = $apiPropria
        ? 'ml-2 inline-flex px-2 py-0.5 text-xs rounded-md bg-amber-50 text-amber-900 border border-amber-100'
        : 'ml-2 inline-flex px-2 py-0.5 text-xs rounded-md bg-sky-50 text-sky-900 border border-sky-100';
    $provHtml = $isUaz
        ? '<span class="ml-2 inline-flex px-2 py-0.5 text-xs rounded-md bg-violet-50 text-violet-800 border border-violet-100">Uazapi</span>'
        : '<span class="ml-2 inline-flex px-2 py-0.5 text-xs rounded-md bg-slate-50 text-slate-600 border border-slate-100">Evolution</span>';

    return $provHtml . '<span class="' . $modoClass . '">' . $modoH . '</span>';
}

/**
 * @param string|null $raw Texto multilinha (ex.: campo de formulário)
 * @return list<string>
 */
function parseTelegramChatIdsMultiline($raw): array {
    if ($raw === null || trim((string) $raw) === '') {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $out = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Chat IDs Telegram por loja (tabela loja_telegram_grupos).
 *
 * @return list<string>
 */
function getTelegramChatIdsPorLoja(string $loja, ?int $userId = null): array {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '') {
        return [];
    }
    if ($userId === null || $userId < 1) {
        $userId = telegramLojaOwnerUserId();
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT chat_id FROM loja_telegram_grupos WHERE loja = ? AND user_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$loja, $userId]);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = trim((string) ($row['chat_id'] ?? ''));
            if ($cid !== '') {
                $ids[] = $cid;
            }
        }
        return $ids;
    } catch (Exception $e) {
        return [];
    }
}

function syncLojaTelegramGrupos(string $loja, int $userId, array $chatIds): void {
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($loja));
    if ($loja === '' || $userId < 1) {
        return;
    }
    try {
        $pdo = getDB();
        $pdo->prepare('DELETE FROM loja_telegram_grupos WHERE loja = ? AND user_id = ?')->execute([$loja, $userId]);
        $ins = $pdo->prepare('INSERT INTO loja_telegram_grupos (loja, chat_id, user_id) VALUES (?, ?, ?)');
        foreach ($chatIds as $cid) {
            $cid = trim((string) $cid);
            if ($cid === '') {
                continue;
            }
            if (strlen($cid) > 128) {
                $cid = substr($cid, 0, 128);
            }
            $ins->execute([$loja, $cid, $userId]);
        }
    } catch (Exception $e) {
        error_log('syncLojaTelegramGrupos: ' . $e->getMessage());
    }
}

/**
 * Telegram por loja: envia para cada chat_id cadastrado; se não houver, mantém fallback do chat global.
 *
 * @param string|null $imagemUrl
 * @param string|null $imagemBase64 Mesma imagem já baixada para o WhatsApp (upload no Telegram).
 */
function enviarTelegramFluxoLoja(string $loja, string $mensagem, $imagemUrl, array &$errosAcumulado, $imagemBase64 = null): void {
    require_once __DIR__ . '/../core/telegram/TelegramService.php';

    telegramEnviarPorLoja($loja, $mensagem, $errosAcumulado, $imagemUrl, $imagemBase64);
    telegramStoryPorLoja($loja, $mensagem, $imagemUrl, $errosAcumulado);
}

/**
 * Verifica se um grupo pode receber envio (respeitando intervalo por grupo).
 * @param int $grupoId ID do grupo
 * @param string $lojaPrefix Prefixo da loja (ml, shopee, magalu, etc.)
 * @param int|null $intervaloGrupo Minutos do grupo (null = usar padrão)
 * @param int $intervaloPadrao Minutos padrão da loja
 * @return bool True se pode enviar
 */
function grupoPodeReceberEnvio($grupoId, $lojaPrefix, $intervaloGrupo, $intervaloPadrao = 10) {
    $intervalo = ($intervaloGrupo !== null && $intervaloGrupo > 0) ? (int) $intervaloGrupo : (int) $intervaloPadrao;
    $intervalo = max(1, min(1440, $intervalo)); // 1 min a 24h
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT ultimo_envio_em FROM grupo_ultimo_envio WHERE grupo_id = ? AND loja_prefix = ?");
        $stmt->execute([(int) $grupoId, $lojaPrefix]);
        $row = $stmt->fetch();
        if (!$row) return true; // nunca enviou
        $ultimo = strtotime($row['ultimo_envio_em']);
        return (time() - $ultimo) >= ($intervalo * 60);
    } catch (Exception $e) {
        return true; // tabela pode não existir
    }
}

/**
 * Registra que um grupo recebeu envio (para respeitar intervalo).
 */
function registrarEnvioGrupo($grupoId, $lojaPrefix) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO grupo_ultimo_envio (grupo_id, loja_prefix, ultimo_envio_em)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE ultimo_envio_em = NOW()
        ");
        $stmt->execute([(int) $grupoId, $lojaPrefix]);
    } catch (Exception $e) { /* ignorar */ }
}

/**
 * Migração única: se o toggle global antigo estava ativo, ativa Status em todas as lojas conhecidas.
 */
function migrarWhatsappStatusGlobalParaLojas(): void {
    if (getConfig('whatsapp_status_legacy_migrated', '0') === '1') {
        return;
    }
    if (getConfig('whatsapp_status_publicar', '0') === '1') {
        foreach (['ml', 'shopee', 'magalu', 'aliexpress', 'amazon', 'shein', 'ml_cupons'] as $p) {
            setConfig($p . '_whatsapp_status_ativo', '1');
        }
    }
    setConfig('whatsapp_status_legacy_migrated', '1');
}

/**
 * Retorna a Evolution a usar para publicar no Status do WhatsApp (Stories).
 * Requer {loja}_whatsapp_status_ativo = 1; usa a Evolution já resolvida pelo fluxo de grupos ($fallbackEvo).
 *
 * @param array|null $fallbackEvo ['url_base','instancia','api_key']
 * @param string $lojaPrefix ml, shopee, magalu, aliexpress, amazon, shein, ml_cupons
 * @return array|null
 */
function getEvolutionParaStatus($fallbackEvo = null, string $lojaPrefix = '') {
    migrarWhatsappStatusGlobalParaLojas();
    $loja = preg_replace('/[^a-z0-9_]/', '', strtolower($lojaPrefix));
    if ($loja === '') {
        return null;
    }
    if (getConfig($loja . '_whatsapp_status_ativo', '0') !== '1') {
        return null;
    }
    if ($fallbackEvo === null || !is_array($fallbackEvo)) {
        return null;
    }
    $url = trim((string) ($fallbackEvo['url_base'] ?? ''));
    $inst = trim((string) ($fallbackEvo['instancia'] ?? ''));
    $key = trim((string) ($fallbackEvo['api_key'] ?? ''));
    if ($url === '' || $inst === '' || $key === '') {
        return null;
    }

    return [
        'url_base' => rtrim($url, '/'),
        'instancia' => $inst,
        'api_key' => $key,
        'provedor' => $fallbackEvo['provedor'] ?? 'evolution',
        'uazapi_admin_token' => (string) ($fallbackEvo['uazapi_admin_token'] ?? ''),
    ];
}

/**
 * Envia mensagem (e opcionalmente imagem) para o grupo do Telegram configurado.
 * Usa telegram_bot_token e telegram_chat_id das configurações.
 * @param string $mensagem Texto da mensagem
 * @param string|null $imagemUrl URL da imagem (opcional). Se informada, envia como foto com caption.
 * @param string &$err Variável para retornar mensagem de erro
 * @param string|null $chatIdOverride Se informado, usa como chat_id (modo dispatch); senão usa telegram_chat_id da config
 * @param string|null $imagemBase64 Base64 puro ou data URI (opcional). Se informado, envia foto por upload (melhor que URL para CDNs que bloqueiam o Telegram).
 * @return bool True se enviou com sucesso
 */
function enviarTelegram($mensagem, $imagemUrl = null, &$err = '', $chatIdOverride = null, $imagemBase64 = null) {
    $err = '';
    $token = trim(getConfig('telegram_bot_token', ''));
    $chatId = $chatIdOverride !== null && $chatIdOverride !== ''
        ? trim((string) $chatIdOverride)
        : trim(getConfig('telegram_chat_id', ''));
    if (getConfig('telegram_ativo', '0') !== '1') return false;
    if (empty($token) || empty($chatId)) return false;

    $apiBase = 'https://api.telegram.org/bot' . $token;
    $mensagem = mb_substr($mensagem, 0, 1024);

    $binFoto = null;
    $tmpFoto = null;
    if ($imagemBase64 !== null && $imagemBase64 !== '') {
        $raw = trim((string) $imagemBase64);
        if (strpos($raw, 'data:') === 0) {
            $parts = explode(',', $raw, 2);
            $raw = isset($parts[1]) ? $parts[1] : '';
        }
        $binFoto = base64_decode($raw, true);
        if ($binFoto !== false && strlen($binFoto) > 200) {
            $tmpFoto = @tempnam(sys_get_temp_dir(), 'tgph');
            if ($tmpFoto !== false) {
                file_put_contents($tmpFoto, $binFoto);
            } else {
                $binFoto = null;
            }
        } else {
            $binFoto = null;
        }
    }

    if ($tmpFoto !== null && is_file($tmpFoto)) {
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = finfo_file($fi, $tmpFoto);
                if (is_string($det) && strpos($det, 'image/') === 0) {
                    $mime = $det;
                }
                finfo_close($fi);
            }
        }
        $ext = 'jpg';
        if (strpos($mime, 'png') !== false) {
            $ext = 'png';
        } elseif (strpos($mime, 'webp') !== false) {
            $ext = 'webp';
        } elseif (strpos($mime, 'gif') !== false) {
            $ext = 'gif';
        }
        $url = $apiBase . '/sendPhoto';
        $cf = new CURLFile($tmpFoto, $mime, 'photo.' . $ext);
        $params = ['chat_id' => $chatId, 'photo' => $cf, 'caption' => $mensagem];
    } elseif (!empty($imagemUrl) && preg_match('#^https?://#i', $imagemUrl)) {
        $url = $apiBase . '/sendPhoto';
        $params = ['chat_id' => $chatId, 'photo' => $imagemUrl, 'caption' => $mensagem];
    } else {
        $url = $apiBase . '/sendMessage';
        $params = ['chat_id' => $chatId, 'text' => $mensagem];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $tmpFoto ? 45 : 15,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($tmpFoto !== null && is_file($tmpFoto)) {
        @unlink($tmpFoto);
    }

    if ($code !== 200) {
        $err = 'Telegram HTTP ' . $code;
        return false;
    }
    $j = @json_decode($res, true);
    if (!$j || !isset($j['ok']) || !$j['ok']) {
        $err = $j['description'] ?? 'Telegram API error';
        return false;
    }
    return true;
}

// Função para salvar configuração
function setConfig($chave, $valor) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO configuracoes (chave, valor) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE valor = ?
        ");
        $result = $stmt->execute([$chave, $valor, $valor]);
        
        if (!$result) {
            error_log("Erro ao executar query para configuração {$chave}");
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Erro ao salvar configuração {$chave}: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Erro ao salvar configuração {$chave}: " . $e->getMessage());
        return false;
    }
}

// Função para formatar valor monetário
function formatMoney($valor) {
    return number_format($valor, 2, '.', '');
}

// Função para calcular desconto
function calcularDesconto($precoOriginal, $precoAtual) {
    if ($precoOriginal <= 0 || $precoAtual >= $precoOriginal) {
        return 0;
    }
    return round((($precoOriginal - $precoAtual) / $precoOriginal) * 100);
}

/**
 * Converte string de preço (BR ou EN) em float.
 * - BR: "1.504,31" (ponto=milhar, vírgula=decimal) → 1504.31
 * - EN: "504.31" (ponto=decimal) → 504.31 — NÃO remove o ponto para não virar 50431
 * - "504" → 504
 * Retorna float ou null se inválido.
 */
function parsePrecoBr($str) {
    if ($str === null || $str === '') return null;
    $s = trim(preg_replace('/\s+/', '', (string)$str));
    if ($s === '') return null;
    $s = preg_replace('/^R\$\s*/iu', '', $s);
    if ($s === '') return null;
    
    // Se tem vírgula, é formato BR
    if (strpos($s, ',') !== false) {
        // Formato BR: 1.504,31 ou 747,38 — remove pontos (milhar), troca vírgula por ponto
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        $f = filter_var($s, FILTER_VALIDATE_FLOAT);
        return ($f !== false && $f >= 0) ? (float)$f : null;
    }
    
    // Só tem ponto - precisa decidir se é decimal ou milhar
    if (strpos($s, '.') !== false) {
        // Contar quantos pontos tem
        $numPontos = substr_count($s, '.');
        
        // Se tem mais de 1 ponto, são milhares (ex: 1.504.321)
        if ($numPontos > 1) {
            $s = str_replace('.', '', $s);
            $f = filter_var($s, FILTER_VALIDATE_FLOAT);
            return ($f !== false && $f >= 0) ? (float)$f : null;
        }
        
        // Se tem 1 ponto, verificar posição e quantidade de dígitos após
        if (preg_match('/^(\d+)\.(\d+)$/', $s, $m)) {
            $antesDecimal = $m[1];
            $depoisDecimal = $m[2];
            
            // Se depois do ponto tem 1 ou 2 dígitos, É DECIMAL (ex: 747.38, 504.5)
            if (strlen($depoisDecimal) <= 2) {
                $f = filter_var($s, FILTER_VALIDATE_FLOAT);
                return ($f !== false && $f >= 0) ? (float)$f : null;
            }
            
            // Se depois do ponto tem 3 dígitos exatos E antes tem 1-2 dígitos, é milhar BR (ex: 1.504, 74.738)
            if (strlen($depoisDecimal) == 3 && strlen($antesDecimal) <= 2) {
                $s = str_replace('.', '', $s);
                $f = filter_var($s, FILTER_VALIDATE_FLOAT);
                return ($f !== false && $f >= 0) ? (float)$f : null;
            }
            
            // Outros casos: tratar como decimal (ex: 747.388 seria 747.388)
            $f = filter_var($s, FILTER_VALIDATE_FLOAT);
            return ($f !== false && $f >= 0) ? (float)$f : null;
        }
    }
    
    // Sem ponto nem vírgula - número inteiro
    $f = filter_var($s, FILTER_VALIDATE_FLOAT);
    return ($f !== false && $f >= 0) ? (float)$f : null;
}

/**
 * Se preco_original for manifestamente inválido (ex.: 10x maior que preco), zera.
 * Também corrige casos onde o preço original parece ter sido parseado errado.
 * Retorna [preco_original, desconto] já ajustados.
 */
function sanearPrecoOriginal($preco, $preco_original, $desconto) {
    $po = $preco_original;
    $desc = (int)$desconto;
    
    // Se preco_original > 10x preco, é claramente erro de parsing
    if ($preco > 0 && $po > 0 && $po > 10 * $preco) {
        // Tentar corrigir: talvez seja 74738 em vez de 747.38
        $poCorrigido = $po / 100;
        if ($poCorrigido > $preco && $poCorrigido < 10 * $preco) {
            $po = $poCorrigido;
        } else {
            $po = null;
            $desc = 0;
        }
    }
    
    // Recalcular desconto se tivermos valores válidos
    if ($po !== null && $preco > 0 && $po > 0 && $preco < $po && function_exists('calcularDesconto')) {
        $desc = calcularDesconto($po, $preco);
    }
    
    // Se desconto > 95%, provavelmente é erro
    if ($desc > 95) {
        $po = null;
        $desc = 0;
    }
    
    return [$po, $desc];
}

/**
 * Garante que preco (total) não seja o valor da parcela por engano.
 * Se preco <= preco_parcela e temos parcelas, retorna preco_parcela * parcelas.
 * Retorna o preco (corrigido ou original).
 */
function corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela) {
    if (empty($parcelas) || empty($preco_parcela) || $preco_parcela <= 0) {
        return $preco;
    }
    $total = round($preco_parcela * (int)$parcelas, 2);
    // Se o "preço" salvo é menor ou igual ao valor de 1 parcela, estava errado
    if ($preco <= $preco_parcela) {
        return $total;
    }
    return $preco;
}

/**
 * Extrai informações de parcelas de uma string de preço.
 * Ex: "em 10x de R$ 74,70" ou "10x R$ 74,70" ou "12x de 46,43"
 * Retorna [parcelas, preco_parcela] ou [null, null] se não encontrar.
 */
function extrairParcelas($str) {
    if (empty($str)) return [null, null];
    
    // Padrões: "10x de R$ 74,70", "em 10x R$ 74,70", "12x 46,43"
    if (preg_match('/(\d{1,2})\s*x\s*(?:de\s*)?R?\$?\s*([\d.,]+)/iu', $str, $m)) {
        $parcelas = (int)$m[1];
        $precoParcela = parsePrecoBr($m[2]);
        if ($parcelas >= 2 && $parcelas <= 48 && $precoParcela > 0) {
            return [$parcelas, $precoParcela];
        }
    }
    
    return [null, null];
}

/**
 * Normaliza URL de imagem de produto (ofertas / afiliados).
 * Rejeita data:, URLs relativas sem host (exceto caminhos típicos do CDN ML).
 */
function achadinhosNormalizarUrlImagemProduto(string $url): string {
    $u = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($u === '') {
        return '';
    }
    if (stripos($u, 'data:') === 0) {
        return '';
    }
    if (preg_match('#^//#', $u)) {
        $u = 'https:' . $u;
    } elseif (isset($u[0]) && $u[0] === '/' && preg_match('#\.(jpe?g|png|gif|webp)(\?|#|$)#i', $u)) {
        $u = 'https://http2.mlstatic.com' . $u;
    }
    if (!preg_match('#^https?://#i', $u)) {
        return '';
    }

    return $u;
}

/** Primeira URL absoluta em um atributo srcset (fallback quando src/data-src vazio). */
function achadinhosExtrairPrimeiraUrlSrcset(string $srcset): string {
    $srcset = trim($srcset);
    if ($srcset === '') {
        return '';
    }
    if (preg_match('#\b(https?://[^\s,]+)#i', $srcset, $m)) {
        return trim($m[1]);
    }

    return '';
}

/**
 * URLs alternativas para download (CDN ML: tamanho -O vs -F, query opcional).
 *
 * @return list<string>
 */
function achadinhosCandidatosUrlImagemDownload(string $url): array {
    $url = achadinhosNormalizarUrlImagemProduto($url);
    if ($url === '') {
        return [];
    }
    $out = [$url];
    $host = parse_url($url, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    $ehMl = ($host !== '' && (strpos($host, 'mlstatic') !== false || strpos($host, 'mercadolivre') !== false || strpos($host, 'mercadolibre') !== false));
    if ($ehMl && preg_match('#-O\.(jpe?g|png|webp)#i', $url)) {
        $alt = preg_replace('#-O\.#i', '-F.', $url);
        if ($alt !== $url) {
            $out[] = $alt;
        }
    }
    if ($ehMl) {
        $noQ = preg_replace('#\?.*$#', '', $url);
        if ($noQ !== $url && $noQ !== '') {
            $out[] = $noQ;
        }
    }

    return array_values(array_unique($out));
}

/**
 * GET binário (imagem) com cURL: segue redirect, Referer e User-Agent de navegador.
 * CDNs do Mercado Livre costumam bloquear requisições sem Referer adequado.
 */
function achadinhosHttpGetBinaryUrl(string $url, string $refererPreferido = '', int $timeout = 35): ?string {
    if (!function_exists('curl_init')) {
        return null;
    }
    $url = achadinhosNormalizarUrlImagemProduto($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $referers = [];
    foreach ([$refererPreferido, 'https://www.mercadolivre.com.br/', 'https://lista.mercadolivre.com.br/', 'https://www.mercadolivre.com/'] as $r) {
        $r = trim((string) $r);
        if ($r !== '' && !in_array($r, $referers, true)) {
            $referers[] = $r;
        }
    }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    foreach ($referers as $ref) {
        $ch = curl_init($url);
        if (!$ch) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 12,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9',
                'Referer: ' . $ref,
            ],
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code >= 200 && $code < 400 && strlen($data) >= 32) {
            return $data;
        }
    }

    return null;
}

/**
 * Converte bytes de imagem (JPEG/PNG/GIF/WebP se GD suportar) em JPEG e retorna base64.
 */
function achadinhosConverterBinarioImagemParaJpegBase64(string $binary): ?string {
    if (strlen($binary) < 16) {
        return null;
    }
    $img = @imagecreatefromstring($binary);
    if ($img === false) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $max = 1920;
    if ($w > 1 && $h > 1 && ($w > $max || $h > $max)) {
        $ratio = min($max / $w, $max / $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($img);

            return null;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    ob_start();
    $ok = @imagejpeg($img, null, 88);
    $jpeg = ob_get_clean();
    imagedestroy($img);
    if (!$ok || $jpeg === false || $jpeg === '') {
        return null;
    }

    return base64_encode($jpeg);
}

/**
 * Baixa imagem da URL e retorna base64 JPEG (Evolution sendMedia).
 */
function achadinhosBaixarImagemUrlComoJpegBase64(string $url, string $refererPaginaProduto = ''): ?string {
    $cands = achadinhosCandidatosUrlImagemDownload($url);
    if ($cands === []) {
        return null;
    }
    foreach ($cands as $u) {
        $binary = achadinhosHttpGetBinaryUrl($u, $refererPaginaProduto);
        if ($binary === null) {
            continue;
        }
        $b64 = achadinhosConverterBinarioImagemParaJpegBase64($binary);
        if ($b64 !== null) {
            return $b64;
        }
    }

    return null;
}

/**
 * Baixa imagem de URL e salva em uploads (ex.: uploads/produtos/).
 * Retorna o caminho relativo (ex.: uploads/produtos/arquivo.jpg) ou null.
 */
function downloadImageFromUrl($url, $pasta = 'uploads/produtos/') {
    $url = achadinhosNormalizarUrlImagemProduto((string) $url);
    if ($url === '') {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        if (file_exists(__DIR__ . '/../' . $url)) {
            return $url;
        }

        return null;
    }
    try {
        $uploadDir = __DIR__ . '/../' . $pasta;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $imageData = null;
        foreach (achadinhosCandidatosUrlImagemDownload($url) as $tryUrl) {
            $imageData = achadinhosHttpGetBinaryUrl($tryUrl, '');
            if ($imageData !== null) {
                break;
            }
        }
        if ($imageData === null) {
            $imageData = @file_get_contents($url);
        }
        if ($imageData === false || strlen($imageData) < 10) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_buffer($finfo, $imageData) : '';
        if ($finfo) finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($mimeType && !in_array(strtolower($mimeType), $allowed)) return null;
        $ext = 'jpg';
        if (strpos($mimeType, 'png') !== false) $ext = 'png';
        elseif (strpos($mimeType, 'gif') !== false) $ext = 'gif';
        elseif (strpos($mimeType, 'webp') !== false) $ext = 'webp';
        $nome = uniqid('ml_', true) . '.' . $ext;
        $path = $uploadDir . $nome;
        if (file_put_contents($path, $imageData) !== false) return $pasta . $nome;
        return null;
    } catch (Exception $e) {
        error_log('downloadImageFromUrl: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resposta JSON padronizada (sucesso): message + dados extras no mesmo nível.
 *
 * @param array<string, mixed> $data
 * @return array{success: true, message: string}&array<string, mixed>
 */
function api_response_success(string $message = 'OK', array $data = []): array {
    return array_merge(['success' => true, 'message' => $message], $data);
}

/**
 * Resposta JSON padronizada (erro).
 *
 * @return array{success: false, message: string}
 */
function api_response_error(string $message): array {
    return ['success' => false, 'message' => $message];
}

/**
 * Dispatches ativos do usuário (admin), agrupados: canal → grupo_id → conta_id → lista de linhas.
 * Sempre retorna chaves 'whatsapp' e 'telegram'. Ordenação global: prioridade ASC, id ASC.
 *
 * @return array{
 *   whatsapp: array<string, array<string, list<array<string, mixed>>>>,
 *   telegram: array<string, array<string, list<array<string, mixed>>>>
 * }
 */
function get_active_dispatches(int $user_id): array {
    $empty = ['whatsapp' => [], 'telegram' => []];
    if (function_exists('dispatch_habilitado') && !dispatch_habilitado()) {
        return $empty;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT * FROM dispatches WHERE user_id = ? AND ativo = 1 ORDER BY prioridade ASC, id ASC'
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('get_active_dispatches: ' . $e->getMessage());
        return $empty;
    }

    $out = $empty;
    foreach ($rows as $row) {
        $canal = $row['canal'] ?? '';
        if ($canal !== 'whatsapp' && $canal !== 'telegram') {
            continue;
        }
        if (array_key_exists('metadata', $row) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['metadata'] = $decoded;
            }
        }
        $g = (string)($row['grupo_id'] ?? '');
        $c = (string)($row['conta_id'] ?? '');
        if (!isset($out[$canal][$g])) {
            $out[$canal][$g] = [];
        }
        if (!isset($out[$canal][$g][$c])) {
            $out[$canal][$g][$c] = [];
        }
        $out[$canal][$g][$c][] = $row;
    }
    return $out;
}

/**
 * Slug com acentos removidos (alinhado a categorias salvas pelo admin).
 */
function achadinhosSlugifyTexto(string $s): string {
    $a = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
    $low = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($low, $a);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Chave de igualdade para nome de categoria (minúsculas, espaços colapsados).
 */
function achadinhosChaveNomeCategoria(string $nome): string {
    $n = preg_replace('/\s+/u', ' ', trim($nome));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($n, 'UTF-8');
    }
    return strtolower($n);
}

/**
 * Normaliza slugs legados / variantes para o slug canónico dos nichos (IA + heurística).
 */
function mapearCategoriaParaSlugCanonico(string $slug): string {
    $slug = trim(strtolower($slug));
    if ($slug === '') {
        return '';
    }
    $mapExact = [
        'suplementos-alimentares' => 'beleza-cuidados-saude',
        'suplementos-nutricionais' => 'beleza-cuidados-saude',
        'suplementos-fitness' => 'beleza-cuidados-saude',
        'beleza-e-cuidados' => 'beleza-cuidados-saude',
        'beleza-e-cuidados-pessoais' => 'beleza-cuidados-saude',
        'beleza-e-cuidados-e3f7' => 'beleza-cuidados-saude',
        'beleza-cuidados-pessoais' => 'beleza-cuidados-saude',
        'saude-e-cuidados' => 'beleza-cuidados-saude',
        'saude-e-fitness' => 'estilo-vida-hobbies',
        'esportes-e-fitness' => 'estilo-vida-hobbies',
        'esportes-e-lazer' => 'estilo-vida-hobbies',
        'informatica' => 'tecnologia-eletronicos',
        'casa-cozinha' => 'casa-cozinha-decoracao',
        'casa-e-cozinha' => 'casa-cozinha-decoracao',
        'casa-e-jardim' => 'casa-cozinha-decoracao',
        'casa-e-decoracao' => 'casa-cozinha-decoracao',
        'decoracao-casa' => 'casa-cozinha-decoracao',
    ];
    if (isset($mapExact[$slug])) {
        return $mapExact[$slug];
    }
    if (preg_match('/^suplementos(-|$)/', $slug) === 1 || strncmp($slug, 'suplemento', 10) === 0) {
        return 'beleza-cuidados-saude';
    }
    if ($slug === 'beleza-cuidados-saude' || $slug === 'beleza-cosmeticos') {
        return $slug;
    }
    if (preg_match('/^beleza-e-/', $slug) === 1) {
        return 'beleza-cuidados-saude';
    }
    if (strncmp($slug, 'beleza-cuidados-', 18) === 0) {
        return 'beleza-cuidados-saude';
    }
    if (preg_match('/^informatica/', $slug) === 1) {
        return 'tecnologia-eletronicos';
    }
    if (preg_match('/^eletronicos-/', $slug) === 1) {
        return 'tecnologia-eletronicos';
    }
    if ($slug === 'games' || preg_match('/^games-/', $slug) === 1) {
        return 'tecnologia-eletronicos';
    }
    return $slug;
}

/**
 * Garante um pai (parent_id NULL) e filhos com parent_id coerente. Não altera nome de filhos já existentes.
 *
 * @param array<int, array{slug:string,nome:string,ordem:int}> $filhos
 */
function achadinhosGarantirNoPaiEFilhos(PDO $pdo, string $parentSlug, string $parentNome, int $ordemPai, array $filhos): void {
    require_once __DIR__ . '/../core/db/SchemaHelper.php';
    garantirColunaCategoriasParentId();
    $hasParentCol = (bool) $pdo->query("SHOW COLUMNS FROM categorias LIKE 'parent_id'")->fetch();

    $stFind = $pdo->prepare('SELECT id FROM categorias WHERE slug = ? LIMIT 1');
    $stFind->execute([$parentSlug]);
    $parentRow = $stFind->fetch(PDO::FETCH_ASSOC);
    if (!$parentRow) {
        if ($hasParentCol) {
            $pdo->prepare('INSERT INTO categorias (nome, slug, ordem, ativo, parent_id) VALUES (?, ?, ?, 1, NULL)')
                ->execute([$parentNome, $parentSlug, $ordemPai]);
        } else {
            $pdo->prepare('INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, 1)')
                ->execute([$parentNome, $parentSlug, $ordemPai]);
        }
        $parentId = (int) $pdo->lastInsertId();
    } else {
        $parentId = (int) $parentRow['id'];
        if ($hasParentCol) {
            $pdo->prepare('UPDATE categorias SET nome = ?, parent_id = NULL, ativo = 1 WHERE id = ?')
                ->execute([$parentNome, $parentId]);
        } else {
            $pdo->prepare('UPDATE categorias SET nome = ?, ativo = 1 WHERE id = ?')->execute([$parentNome, $parentId]);
        }
    }

    foreach ($filhos as $f) {
        $cs = $f['slug'];
        $cn = $f['nome'];
        $co = (int) $f['ordem'];
        $stFind->execute([$cs]);
        $ch = $stFind->fetch(PDO::FETCH_ASSOC);
        if ($ch) {
            $cid = (int) $ch['id'];
            if ($hasParentCol) {
                $pdo->prepare('UPDATE categorias SET parent_id = ?, ativo = 1 WHERE id = ?')->execute([$parentId, $cid]);
            }
        } elseif ($hasParentCol) {
            $pdo->prepare('INSERT INTO categorias (nome, slug, ordem, ativo, parent_id) VALUES (?, ?, ?, 1, ?)')
                ->execute([$cn, $cs, $co, $parentId]);
        } else {
            $pdo->prepare('INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, 1)')
                ->execute([$cn, $cs, $co]);
        }
    }
}

/**
 * Coluna parent_id + árvore Moda → Masculina / Feminina / Infantil.
 */
function achadinhosGarantirHierarquiaModa(PDO $pdo): void {
    achadinhosGarantirNoPaiEFilhos($pdo, 'moda', 'Moda', 6, [
        ['slug' => 'moda-masculina', 'nome' => 'Moda Masculina', 'ordem' => 10],
        ['slug' => 'moda-feminina', 'nome' => 'Moda Feminina', 'ordem' => 11],
        ['slug' => 'moda-infantil', 'nome' => 'Moda Infantil', 'ordem' => 12],
    ]);
}

/**
 * Hierarquia padrão: Moda + Beleza + Casa + Eletrônicos + Esportes (alinhado aos nichos / Gemini).
 */
function achadinhosGarantirHierarquiaPadrao(PDO $pdo): void {
    achadinhosGarantirHierarquiaModa($pdo);
    achadinhosGarantirNoPaiEFilhos($pdo, 'beleza', 'Beleza', 20, [
        ['slug' => 'beleza-cuidados-saude', 'nome' => 'Cuidados pessoais e saúde', 'ordem' => 21],
        ['slug' => 'beleza-cosmeticos', 'nome' => 'Cosméticos', 'ordem' => 22],
    ]);
    achadinhosGarantirNoPaiEFilhos($pdo, 'casa', 'Casa', 30, [
        ['slug' => 'casa-cozinha-decoracao', 'nome' => 'Casa, cozinha e decoração', 'ordem' => 31],
    ]);
    achadinhosGarantirNoPaiEFilhos($pdo, 'eletronicos', 'Eletrônicos', 40, [
        ['slug' => 'tecnologia-eletronicos', 'nome' => 'Tecnologia e eletrônicos', 'ordem' => 41],
    ]);
    achadinhosGarantirNoPaiEFilhos($pdo, 'esportes', 'Esportes', 50, [
        ['slug' => 'estilo-vida-hobbies', 'nome' => 'Estilo de vida e hobbies', 'ordem' => 51],
    ]);
}

/**
 * Garante toda a hierarquia base (entrada pública para bootstrap).
 */
function garantirHierarquiaCategoriasBase(): void {
    try {
        $pdo = getDB();
        achadinhosGarantirHierarquiaPadrao($pdo);
    } catch (Throwable $e) {
        error_log('garantirHierarquiaCategoriasBase: ' . $e->getMessage());
    }
}

/**
 * Lista categorias ativas ordenadas em árvore para &lt;select&gt; de loja (com _tree_depth).
 *
 * @return array<int, array<string, mixed>>
 */
function achadinhosListarCategoriasParaSelectLoja(PDO $pdo): array {
    if (function_exists('garantirHierarquiaCategoriasBase')) {
        garantirHierarquiaCategoriasBase();
    }
    $rows = $pdo->query(
        'SELECT id, nome, slug, parent_id, ordem FROM categorias WHERE ativo = 1 ORDER BY ordem ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('achadinhosOrdenarCategoriasArvore')) {
        return achadinhosOrdenarCategoriasArvore($rows);
    }
    return $rows;
}

/**
 * Resolve valor gravado em *_site_categoria_id: null/vazio = automático (Gemini/heurística).
 * -1 Todos (automático). 0 legado → mesmo que -1. -2 → categoria slug mais-vendidos se existir.
 */
function achadinhosResolverCategoriaFixaLoja(PDO $pdo, $valorConfig): ?int {
    if ($valorConfig === null || $valorConfig === '') {
        return null;
    }
    $v = is_numeric($valorConfig) ? (int) $valorConfig : 0;
    if ($v === 0 || $v === -1) {
        return null;
    }
    if ($v === -2) {
        $st = $pdo->prepare('SELECT id FROM categorias WHERE slug = ? AND ativo = 1 LIMIT 1');
        $st->execute(['mais-vendidos']);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ? (int) $r['id'] : null;
    }
    if ($v < 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM categorias WHERE id = ? AND ativo = 1 LIMIT 1');
    $st->execute([$v]);

    return $st->fetch() ? $v : null;
}

/**
 * Busca id ativo por slug aplicando mapa canónico.
 */
function achadinhosBuscarCategoriaIdPorSlugCanonico(PDO $pdo, string $slug): ?int {
    $slug = trim(strtolower($slug));
    if ($slug === '') {
        return null;
    }
    $canon = function_exists('mapearCategoriaParaSlugCanonico') ? mapearCategoriaParaSlugCanonico($slug) : $slug;
    $st = $pdo->prepare('SELECT id FROM categorias WHERE slug = ? AND ativo = 1 LIMIT 1');
    foreach (array_unique([$canon, $slug]) as $try) {
        if ($try === '') {
            continue;
        }
        $st->execute([$try]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int) $row['id'];
        }
    }

    return null;
}

/**
 * Verifica se $descendenteId está na subárvore de $ancestralId (ou é igual).
 */
function achadinhosCategoriaEDescendenteDe(PDO $pdo, int $descendenteId, int $ancestralId): bool {
    if ($descendenteId === $ancestralId) {
        return true;
    }
    if ($ancestralId <= 0 || $descendenteId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT parent_id FROM categorias WHERE id = ? LIMIT 1');
    $cur = $descendenteId;
    for ($i = 0; $i < 64; $i++) {
        $st->execute([$cur]);
        $pid = $st->fetchColumn();
        if ($pid === false || $pid === null || (int) $pid === 0) {
            return false;
        }
        $pid = (int) $pid;
        if ($pid === $ancestralId) {
            return true;
        }
        $cur = $pid;
    }

    return false;
}

/**
 * Indica se a categoria tem filhos ativos (para lógica «pai» vs «folha» na categoria fixa).
 */
function achadinhosCategoriaTemFilhosAtivos(PDO $pdo, int $categoriaId): bool {
    if ($categoriaId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM categorias WHERE parent_id = ? AND ativo = 1 LIMIT 1');
    $st->execute([$categoriaId]);

    return (bool) $st->fetchColumn();
}

/**
 * Publicação / filtros: combina produto.categoria_id com valor fixo escolhido na loja.
 * Folha (sem filhos): só match direto. Pai (com subcategorias): próprio id ou qualquer descendente.
 */
function achadinhosProdutoCombinaComCategoriaFixa(PDO $pdo, int $produtoCategoriaId, int $fixaCategoriaId): bool {
    if ($produtoCategoriaId <= 0 || $fixaCategoriaId <= 0) {
        return false;
    }
    if (!achadinhosCategoriaTemFilhosAtivos($pdo, $fixaCategoriaId)) {
        return $produtoCategoriaId === $fixaCategoriaId;
    }

    return achadinhosCategoriaEDescendenteDe($pdo, $produtoCategoriaId, $fixaCategoriaId);
}

/**
 * Antes de INSERT manual: reutiliza id se slug ou nome normalizado já existir.
 */
function achadinhosReutilizarCategoriaExistente(PDO $pdo, string $nome, string $slug): ?int {
    $slug = trim(strtolower($slug));
    if ($slug !== '') {
        $st = $pdo->prepare('SELECT id FROM categorias WHERE LOWER(TRIM(slug)) = ? LIMIT 1');
        $st->execute([$slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return (int) $r['id'];
        }
    }
    $chave = function_exists('achadinhosChaveNomeCategoria') ? achadinhosChaveNomeCategoria($nome) : strtolower(trim($nome));
    if ($chave === '') {
        return null;
    }
    $st = $pdo->query('SELECT id, nome FROM categorias');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (achadinhosChaveNomeCategoria((string) ($row['nome'] ?? '')) === $chave) {
            return (int) $row['id'];
        }
    }

    return null;
}

/**
 * Pré-ordem em árvore para listagem (pai antes dos filhos).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function achadinhosOrdenarCategoriasArvore(array $rows): array {
    $hasParent = false;
    foreach ($rows as $r) {
        if (!empty($r['parent_id'])) {
            $hasParent = true;
            break;
        }
    }
    if (!$hasParent) {
        return $rows;
    }
    $byParent = [];
    foreach ($rows as $r) {
        $pid = $r['parent_id'] ?? null;
        $pid = ($pid === null || $pid === '' || (int)$pid === 0) ? 0 : (int)$pid;
        if (!isset($byParent[$pid])) {
            $byParent[$pid] = [];
        }
        $byParent[$pid][] = $r;
    }
    $cmp = static function ($a, $b) {
        $oa = (int)($a['ordem'] ?? 0);
        $ob = (int)($b['ordem'] ?? 0);
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
    };
    foreach ($byParent as &$list) {
        usort($list, $cmp);
    }
    unset($list);
    $out = [];
    $visit = null;
    $visit = static function (int $parentId, int $depth) use (&$visit, &$out, $byParent) {
        foreach ($byParent[$parentId] ?? [] as $r) {
            $r['_tree_depth'] = $depth;
            $out[] = $r;
            $visit((int)$r['id'], $depth + 1);
        }
    };
    $visit(0, 0);
    $used = [];
    foreach ($out as $r) {
        $used[(int)$r['id']] = true;
    }
    foreach ($rows as $r) {
        if (!isset($used[(int)$r['id']])) {
            $r['_tree_depth'] = 0;
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * Slug ASCII a partir de texto (mesma ideia da topbar legada em index.php).
 */
function achadinhosSlugAsciiDeTexto(string $texto): string {
    return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $texto)), '-');
}

/**
 * Indica se há categorias duplicadas por nome normalizado ou slug normalizado.
 */
function achadinhosCategoriasPrecisamMescla(PDO $pdo): bool {
    $rows = $pdo->query('SELECT nome, slug FROM categorias')->fetchAll(PDO::FETCH_ASSOC);
    $seenNome = [];
    $seenSlug = [];
    foreach ($rows as $r) {
        $nk = achadinhosChaveNomeCategoria((string)($r['nome'] ?? ''));
        if ($nk !== '') {
            if (isset($seenNome[$nk])) {
                return true;
            }
            $seenNome[$nk] = true;
        }
        $sk = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)($r['slug'] ?? '')), 'UTF-8')
            : strtolower(trim((string)($r['slug'] ?? '')));
        if ($sk !== '') {
            if (isset($seenSlug[$sk])) {
                return true;
            }
            $seenSlug[$sk] = true;
        }
    }
    return false;
}

/**
 * Redireciona vínculos de grupos_categorias antes de apagar categoria duplicada.
 */
function achadinhosMigrarGruposCategoriasParaId(PDO $pdo, int $manterId, int $removerId): void {
    try {
        $pdo->query('SELECT 1 FROM grupos_categorias LIMIT 1');
    } catch (PDOException $e) {
        return;
    }
    $st = $pdo->prepare('SELECT grupo_id FROM grupos_categorias WHERE categoria_id = ?');
    $st->execute([$removerId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $gid = (int)$gid;
        $chk = $pdo->prepare('SELECT 1 FROM grupos_categorias WHERE grupo_id = ? AND categoria_id = ? LIMIT 1');
        $chk->execute([$gid, $manterId]);
        if ($chk->fetchColumn()) {
            $pdo->prepare('DELETE FROM grupos_categorias WHERE grupo_id = ? AND categoria_id = ?')->execute([$gid, $removerId]);
        } else {
            $pdo->prepare('UPDATE grupos_categorias SET categoria_id = ? WHERE grupo_id = ? AND categoria_id = ?')
                ->execute([$manterId, $gid, $removerId]);
        }
    }
}

/**
 * Mescla categorias duplicadas: mesmo slug (case-insensitive) ou mesmo nome normalizado.
 * Mantém o menor id. Retorna quantas linhas de categorias foram removidas.
 */
function achadinhosMesclarCategoriasDuplicadas(PDO $pdo): int {
    $removidas = 0;
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query('SELECT id, nome, slug FROM categorias ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $pdo->commit();
            return 0;
        }

        $bySlug = [];
        foreach ($rows as $r) {
            $sk = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string)($r['slug'] ?? '')), 'UTF-8')
                : strtolower(trim((string)($r['slug'] ?? '')));
            if ($sk === '') {
                continue;
            }
            if (!isset($bySlug[$sk])) {
                $bySlug[$sk] = [];
            }
            $bySlug[$sk][] = (int)$r['id'];
        }
        foreach ($bySlug as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $keep = $ids[0];
            foreach (array_slice($ids, 1) as $dup) {
                $pdo->prepare('UPDATE produtos SET categoria_id = ? WHERE categoria_id = ?')->execute([$keep, $dup]);
                achadinhosMigrarGruposCategoriasParaId($pdo, $keep, $dup);
                $pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([$dup]);
                $removidas++;
            }
        }

        $rows = $pdo->query('SELECT id, nome, slug FROM categorias ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $byNome = [];
        foreach ($rows as $r) {
            $nk = achadinhosChaveNomeCategoria((string)($r['nome'] ?? ''));
            if ($nk === '') {
                continue;
            }
            if (!isset($byNome[$nk])) {
                $byNome[$nk] = [];
            }
            $byNome[$nk][] = (int)$r['id'];
        }
        foreach ($byNome as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $keep = $ids[0];
            foreach (array_slice($ids, 1) as $dup) {
                $pdo->prepare('UPDATE produtos SET categoria_id = ? WHERE categoria_id = ?')->execute([$keep, $dup]);
                achadinhosMigrarGruposCategoriasParaId($pdo, $keep, $dup);
                $pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([$dup]);
                $removidas++;
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('achadinhosMesclarCategoriasDuplicadas: ' . $e->getMessage());
    }
    return $removidas;
}

/**
 * Alinha rótulos da topbar aos nomes canônicos do BD e remove duplicatas por slug.
 */
function achadinhosSincronizarCategoriasTopbarComBd(PDO $pdo, string $pipe): string {
    $pipe = achadinhosNormalizarListaTopbar($pipe);
    if ($pipe === '') {
        return '';
    }
    $parts = array_filter(array_map('trim', explode('|', $pipe)));
    $out = [];
    $seenSlug = [];
    foreach ($parts as $p) {
        $r = achadinhosResolverCategoriaPorLabelTopbar($pdo, $p);
        if ($r && !empty($r['slug'])) {
            $sk = (string)$r['slug'];
            if (isset($seenSlug[$sk])) {
                continue;
            }
            $seenSlug[$sk] = true;
            $out[] = (string)$r['nome'];
        } else {
            $k = achadinhosChaveNomeCategoria($p);
            if (isset($seenSlug['__txt__' . $k])) {
                continue;
            }
            $seenSlug['__txt__' . $k] = true;
            $out[] = $p;
        }
    }
    return implode('|', $out);
}

/**
 * Atualiza o texto salvo em categorias_topbar quando o admin renomeia uma categoria.
 */
function achadinhosSubstituirTopbarPorMudancaNome(?string $nomeAntigo, string $nomeNovo): void {
    if ($nomeAntigo === null || trim($nomeAntigo) === '') {
        return;
    }
    if (!function_exists('getConfig') || !function_exists('setConfig')) {
        return;
    }
    $tb = getConfig('categorias_topbar', '');
    if ($tb === '') {
        return;
    }
    $want = achadinhosChaveNomeCategoria($nomeAntigo);
    $parts = array_map('trim', explode('|', $tb));
    $changed = false;
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        if (achadinhosChaveNomeCategoria($p) === $want) {
            $out[] = $nomeNovo;
            $changed = true;
        } else {
            $out[] = $p;
        }
    }
    if ($changed) {
        setConfig('categorias_topbar', implode('|', $out));
    }
}

/**
 * Normaliza string de categorias da topbar: trim, remove vazios, deduplica por nome normalizado.
 */
function achadinhosNormalizarListaTopbar(?string $pipe): string {
    if ($pipe === null) {
        return '';
    }
    $parts = array_map('trim', explode('|', $pipe));
    $parts = array_filter($parts, function ($p) {
        return $p !== '';
    });
    $seen = [];
    $out = [];
    foreach ($parts as $p) {
        $k = achadinhosChaveNomeCategoria($p);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $p;
    }
    return implode('|', $out);
}

/**
 * Resolve rótulo da topbar para linha ativa em categorias (id, nome, slug).
 */
function achadinhosResolverCategoriaPorLabelTopbar(PDO $pdo, string $label): ?array {
    $label = trim($label);
    if ($label === '') {
        return null;
    }
    $slugGuess = achadinhosSlugifyTexto($label);

    $st = $pdo->prepare('SELECT id, nome, slug FROM categorias WHERE ativo = 1 AND slug = ? LIMIT 1');
    $st->execute([$slugGuess]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return $r;
    }

    $st = $pdo->prepare('SELECT id, nome, slug FROM categorias WHERE ativo = 1 AND LOWER(TRIM(nome)) = LOWER(?) LIMIT 1');
    $st->execute([$label]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return $r;
    }

    $want = achadinhosChaveNomeCategoria($label);
    $all = $pdo->query('SELECT id, nome, slug FROM categorias WHERE ativo = 1 ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $row) {
        if (achadinhosChaveNomeCategoria($row['nome']) === $want) {
            return $row;
        }
    }

    // Fallback: slug da categoria contém o termo derivado do rótulo (ex.: "Cozinha" → casa-cozinha-decoracao)
    if ($slugGuess !== '' && strlen($slugGuess) >= 4) {
        $best = null;
        $bestLen = PHP_INT_MAX;
        foreach ($all as $row) {
            $cs = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string)$row['slug']), 'UTF-8')
                : strtolower(trim((string)$row['slug']));
            if ($cs === '' || strpos($cs, $slugGuess) === false) {
                continue;
            }
            $len = strlen($cs);
            if ($len < $bestLen) {
                $bestLen = $len;
                $best = $row;
            }
        }
        if ($best !== null) {
            return $best;
        }
    }

    return null;
}

/**
 * Categorias ativas para select no admin, sem nomes repetidos (usa menor id por chave).
 */
function achadinhosListarCategoriasUnicasSelect(PDO $pdo): array {
    $rows = $pdo->query('SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $k = achadinhosChaveNomeCategoria($r['nome']);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $r;
    }
    return $out;
}

/**
 * Busca id de categoria ativa por nome ou slug sugerido (sem criar linha nova).
 */
function achadinhosBuscarCategoriaIdPorNomeOuSlug(PDO $pdo, string $nomeOuSlug): ?int {
    $nomeOuSlug = trim($nomeOuSlug);
    if ($nomeOuSlug === '') {
        return null;
    }
    $resolved = achadinhosResolverCategoriaPorLabelTopbar($pdo, $nomeOuSlug);
    if ($resolved) {
        return (int)$resolved['id'];
    }
    $slug = achadinhosSlugifyTexto($nomeOuSlug);
    if ($slug !== '') {
        $st = $pdo->prepare('SELECT id FROM categorias WHERE ativo = 1 AND slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int)$row['id'];
        }
    }
    return null;
}

/**
 * Extrai payload de QR e código de pareamento da resposta JSON da Evolution API v2 (create/connect).
 *
 * @param array<string, mixed>|null $j
 * @return array{qr: string, pairing: ?string}
 */
function achadinhosEvolutionExtractQrFromJson(?array $j): array {
    $out = ['qr' => '', 'pairing' => null];
    if (!is_array($j)) {
        return $out;
    }
    $p = $j['pairingCode'] ?? $j['pairing_code'] ?? null;
    if ($p !== null && $p !== '') {
        $out['pairing'] = (string) $p;
    }
    $nested = isset($j['qrcode']) && is_array($j['qrcode']) ? $j['qrcode'] : null;
    $candidates = [];
    if ($nested !== null) {
        $candidates[] = $nested['base64'] ?? null;
        $candidates[] = $nested['code'] ?? null;
    }
    $candidates[] = $j['base64'] ?? null;
    $candidates[] = $j['code'] ?? null;
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '') {
            $out['qr'] = $c;
            break;
        }
    }
    return $out;
}

/**
 * GET /instance/connect/{instance} na Evolution API.
 *
 * @return array{code: int, body: string, json: ?array}
 */
function achadinhosEvolutionHttpGetConnect(string $urlBase, string $instance, string $apiKey): array {
    $url = rtrim($urlBase, '/') . '/instance/connect/' . rawurlencode($instance);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($body) ? $body : '';
    $json = json_decode($raw, true);
    $json = is_array($json) ? $json : null;

    return ['code' => $code, 'body' => $raw, 'json' => $json];
}

/**
 * DELETE /instance/delete/{instance}
 *
 * @return array{code: int, body: string}
 */
function achadinhosEvolutionHttpDeleteInstance(string $urlBase, string $instance, string $apiKey): array {
    $path = '/instance/delete/' . rawurlencode($instance);
    return achadinhosEvolutionHttpInstanceWrite('DELETE', $urlBase, $path, $apiKey, false);
}

/**
 * PUT /instance/restart/{instance}
 *
 * @return array{code: int, body: string}
 */
function achadinhosEvolutionHttpRestart(string $urlBase, string $instance, string $apiKey): array {
    $path = '/instance/restart/' . rawurlencode($instance);
    $r = achadinhosEvolutionHttpInstanceWrite('PUT', $urlBase, $path, $apiKey, true);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        return $r;
    }
    $r2 = achadinhosEvolutionHttpInstanceWrite('POST', $urlBase, $path, $apiKey, true);

    return $r2['code'] >= 200 && $r2['code'] < 300 ? $r2 : $r;
}

/**
 * DELETE /instance/logout/{instance}
 *
 * @return array{code: int, body: string}
 */
function achadinhosEvolutionHttpLogout(string $urlBase, string $instance, string $apiKey): array {
    $path = '/instance/logout/' . rawurlencode($instance);
    return achadinhosEvolutionHttpInstanceWrite('DELETE', $urlBase, $path, $apiKey, false);
}

/**
 * @return array{code: int, body: string}
 */
function achadinhosEvolutionHttpInstanceWrite(string $method, string $urlBase, string $pathAfterBase, string $apiKey, bool $sendEmptyJsonBody): array {
    $url = rtrim($urlBase, '/') . $pathAfterBase;
    $headers = ['apikey: ' . $apiKey];
    if ($sendEmptyJsonBody) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ];
    if ($sendEmptyJsonBody) {
        $opts[CURLOPT_POSTFIELDS] = '{}';
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

/**
 * Extrai API key da instância na resposta de POST /instance/create (Evolution v2).
 *
 * @param array<string, mixed>|null $j
 */
function achadinhosEvolutionExtractInstanceApikeyFromCreateResponse(?array $j, string $fallbackGlobalKey): string {
    if (!is_array($j)) {
        return $fallbackGlobalKey;
    }
    $candidates = [
        isset($j['hash']) && is_string($j['hash']) ? $j['hash'] : null,
        isset($j['hash']) && is_array($j['hash']) ? ($j['hash']['apikey'] ?? null) : null,
        $j['apikey'] ?? null,
    ];
    if (isset($j['instance']) && is_array($j['instance'])) {
        $candidates[] = $j['instance']['apikey'] ?? null;
        $candidates[] = $j['instance']['token'] ?? null;
    }
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '') {
            return $c;
        }
    }

    return $fallbackGlobalKey;
}

/**
 * Rótulo amigável para estado retornado pela Evolution (connectionState etc.).
 */
function achadinhosEvolutionHumanStateLabel(string $state): string {
    $s = strtolower(trim($state));
    if ($s === '' || $s === 'array') {
        return 'Indefinido';
    }
    $map = [
        'open' => 'Conectado',
        'connected' => 'Conectado',
        'close' => 'Desconectado',
        'closed' => 'Desconectado',
        'disconnect' => 'Desconectado',
        'connecting' => 'Conectando',
        'qr' => 'Aguardando QR code',
        'pairing' => 'Pareamento',
    ];
    foreach ($map as $needle => $label) {
        if (strpos($s, $needle) !== false) {
            return $label;
        }
    }

    return $state;
}

/**
 * Valor interpretado como verdadeiro (flags em JSON de APIs).
 *
 * @param mixed $v
 */
function achadinhosWhatsappValorFlagVerdadeira($v): bool {
    if ($v === true || $v === 1) {
        return true;
    }
    if ($v === '1') {
        return true;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));

        return $s === 'true' || $s === 'yes' || $s === 'on';
    }

    return false;
}

/**
 * Une metadados aninhados (Baileys groupMetadata, Uazapi chat, etc.) no mesmo nível para detecção.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function achadinhosWhatsappPrepararItemDetectarComunidade(array $item): array {
    $m = $item;
    foreach ([
        'groupMetadata', 'group_metadata', 'GroupMetadata', 'groupMeta', 'group_meta',
        'chat', 'Chat', 'metadata', 'Metadata', 'dialog', 'conversation', 'json',
        'response', 'group', 'Group', 'data', 'Data', 'result', 'wa_metadata',
    ] as $wrap) {
        if (empty($item[$wrap]) || !is_array($item[$wrap])) {
            continue;
        }
        foreach ($item[$wrap] as $k => $v) {
            if (!array_key_exists($k, $m) || $m[$k] === null || $m[$k] === '') {
                $m[$k] = $v;
            }
        }
    }

    // Uazapi / strings JSON com metadados (ex.: groupMetadata serializado)
    foreach (['metadata', 'Metadata', 'wa_metadata', 'wa_groupMetadata', 'groupMetadataJson'] as $metaKey) {
        if (empty($m[$metaKey]) || !is_string($m[$metaKey])) {
            continue;
        }
        $decoded = json_decode($m[$metaKey], true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $k => $v) {
            if (!array_key_exists($k, $m) || $m[$k] === null || $m[$k] === '') {
                $m[$k] = $v;
            }
        }
    }

    return $m;
}

/**
 * Procura em profundidade limitada chaves típicas de “canal de avisos da comunidade”.
 *
 * @param mixed $node
 */
function achadinhosWhatsappBuscarFlagAvisosComunidadeRecursivo($node, int $depth = 0): bool {
    if ($depth > 8 || !is_array($node)) {
        return false;
    }
    foreach ($node as $key => $val) {
        if (is_string($key)) {
            $kn = strtolower(str_replace(['-', ' ', '.'], '_', $key));
            if (preg_match('/community_?announce|is_?community_?announce|communityannouncement|announcement_?community|comunidade_?avisos|avisos_?comunidade/', $kn)) {
                if (achadinhosWhatsappValorFlagVerdadeira($val)) {
                    return true;
                }
            }
        }
        if (is_array($val) && achadinhosWhatsappBuscarFlagAvisosComunidadeRecursivo($val, $depth + 1)) {
            return true;
        }
    }

    return false;
}

/**
 * Texto do item (nome/descrição) indica canal de avisos da comunidade.
 *
 * @param array<string, mixed> $flat Resultado de achadinhosWhatsappPrepararItemDetectarComunidade
 */
function achadinhosWhatsappTextoIndicaAvisosComunidade(array $flat): bool {
    $textParts = [];
    foreach ([
        'subject', 'name', 'Name', 'pushName', 'push_name', 'desc', 'description', 'about', 'topic',
        'wa_name', 'wa_contactName', 'wa_desc', 'wa_subject', 'title', 'Subject', 'nome', 'Nome',
        'formattedTitle', 'pushname',
    ] as $tf) {
        if (!empty($flat[$tf]) && is_scalar($flat[$tf])) {
            $textParts[] = (string) $flat[$tf];
        }
    }
    $sub = mb_strtolower(implode(' ', $textParts));
    $needles = [
        'avisos da comunidade',
        'anúncios da comunidade',
        'anuncios da comunidade',
        'community announcements',
        'community announcement',
        'announcement-only',
        'somente avisos',
        'solo anuncios',
        'canal de avisos',
        'avisos ·',
        'comunidade · avisos',
        'comunidade - avisos',
        'comunidade • avisos',
    ];
    foreach ($needles as $needle) {
        if ($needle !== '' && $sub !== '' && mb_strpos($sub, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Indica se o item da API representa o canal de avisos de uma comunidade WhatsApp.
 *
 * Não usar só o campo genérico `announce`: em muitos grupos significa “só admins enviam”, não comunidade.
 * Prioriza isCommunityAnnounce (Baileys), equivalentes na Uazapi (wa_*) e texto do assunto/descrição.
 *
 * @param array<string, mixed> $item Linha de grupo/chat da Evolution/Uazapi/Baileys
 */
function achadinhosWhatsappDetectarAvisosComunidade(array $item): bool {
    $flat = achadinhosWhatsappPrepararItemDetectarComunidade($item);

    $explicitKeys = [
        'isCommunityAnnounce',
        'is_community_announce',
        'IsCommunityAnnounce',
        'communityAnnounce',
        'isCommunityAnnouncement',
        'is_community_announcement',
        'wa_isCommunityAnnounce',
        'wa_is_community_announce',
        'wa_isCommunityAnnouncement',
        'WaIsCommunityAnnounce',
        'Wa_IsCommunityAnnounce',
        'wa_isAnnouncementGroup',
        'wa_is_announcement_group',
        'isAnnouncementGroup',
        'is_announcement_group',
        'announcementGroup',
        'community_announce',
        'CommunityAnnounce',
        'isCommunityAnnounceGroup',
        'is_community_announce_group',
        'wa_CommunityAnnounce',
        'wa_communityAnnouncement',
        'CommunityAnnouncement',
    ];
    foreach ($explicitKeys as $k) {
        if (isset($flat[$k]) && achadinhosWhatsappValorFlagVerdadeira($flat[$k])) {
            return true;
        }
    }

    $gt = strtolower(trim((string) ($flat['groupType'] ?? $flat['group_type'] ?? $flat['wa_groupType'] ?? $flat['WaGroupType'] ?? $flat['type'] ?? '')));
    if ($gt !== '' && preg_match('/community[_\s-]*announce|announce[_\s-]*community|communityannounce|announcement[_\s-]*only|community[_\s-]*broadcast|^announcement$|^announce$/', $gt)) {
        return true;
    }

    // Metadados (ex.: groupMetadata.isCommunityAnnounce) costumam ficar aninhados; varrer o item bruto.
    if (achadinhosWhatsappBuscarFlagAvisosComunidadeRecursivo($item, 0)) {
        return true;
    }

    if (achadinhosWhatsappTextoIndicaAvisosComunidade($flat)) {
        return true;
    }

    $isAnn = achadinhosWhatsappValorFlagVerdadeira($flat['announce'] ?? null)
        || achadinhosWhatsappValorFlagVerdadeira($flat['Announce'] ?? null)
        || achadinhosWhatsappValorFlagVerdadeira($flat['wa_announce'] ?? null)
        || achadinhosWhatsappValorFlagVerdadeira($flat['wa_Announce'] ?? null);
    $isComm = achadinhosWhatsappValorFlagVerdadeira($flat['isCommunity'] ?? null)
        || achadinhosWhatsappValorFlagVerdadeira($flat['is_community'] ?? null)
        || achadinhosWhatsappValorFlagVerdadeira($flat['wa_isCommunity'] ?? null);
    if ($isAnn && ($isComm || ($gt !== '' && strpos($gt, 'community') !== false))) {
        return true;
    }

    return false;
}

/**
 * Prioridade para mesclar destino no painel (maior vence).
 */
function achadinhosWhatsappDestinoPainelPrioridade(string $d): int {
    if ($d === 'comunidade_avisos') {
        return 3;
    }
    if ($d === 'comunidade') {
        return 2;
    }

    return 1;
}

/**
 * Indica subgrupo ligado a uma comunidade (não é o canal de avisos): parentCommunityId, linkedParent, etc.
 *
 * @param array<string, mixed> $item
 */
function achadinhosWhatsappBuscarIndicioSubgrupoComunidadeRecursivo($node, int $depth = 0): bool {
    if ($depth > 8 || !is_array($node)) {
        return false;
    }
    foreach ($node as $key => $val) {
        if (is_string($key)) {
            $kn = strtolower(str_replace(['-', ' ', '.'], '_', $key));
            if (preg_match('/parent_?community|community_?parent|linked_?parent/', $kn)) {
                if (is_string($val)) {
                    $t = trim($val);
                    if ($t !== '' && strpos($t, '@') !== false) {
                        return true;
                    }
                }
                if (is_array($val)) {
                    $jid = trim((string) ($val['jid'] ?? $val['id'] ?? $val['Jid'] ?? ''));
                    if ($jid !== '' && strpos($jid, '@') !== false) {
                        return true;
                    }
                }
            }
        }
        if (is_array($val) && achadinhosWhatsappBuscarIndicioSubgrupoComunidadeRecursivo($val, $depth + 1)) {
            return true;
        }
    }

    return false;
}

/**
 * Nome/descrição sugere grupo vinculado a comunidade (quando a API não envia parentCommunityId).
 *
 * @param array<string, mixed> $flat Resultado de achadinhosWhatsappPrepararItemDetectarComunidade
 */
function achadinhosWhatsappTextoIndicaGrupoVinculadoComunidade(array $flat): bool {
    $textParts = [];
    foreach ([
        'subject', 'name', 'Name', 'pushName', 'push_name', 'desc', 'description', 'about', 'topic',
        'wa_name', 'wa_contactName', 'wa_desc', 'wa_subject', 'title', 'Subject', 'nome', 'Nome',
        'formattedTitle', 'pushname',
    ] as $tf) {
        if (!empty($flat[$tf]) && is_scalar($flat[$tf])) {
            $textParts[] = (string) $flat[$tf];
        }
    }
    $sub = mb_strtolower(implode(' ', $textParts));
    if ($sub === '') {
        return false;
    }
    $needles = [
        'grupo vinculado',
        'grupo da comunidade',
        'subgrupo da comunidade',
        'subgrupo comunidade',
        'community group',
        'linked to community',
        'vinculado à comunidade',
        'vinculado a comunidade',
        'grupo · comunidade',
        'grupo - comunidade',
        'comunidade · grupo',
    ];
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($sub, $needle) !== false) {
            return true;
        }
    }
    // "Comunidade" no nome mas não parece ser só avisos (já excluído pelo caller se for avisos).
    if (mb_strpos($sub, 'comunidade') !== false && (mb_strpos($sub, 'grupo') !== false || mb_strpos($sub, 'group') !== false)) {
        foreach (['avisos', 'announcement', 'anuncios', 'anúncios', 'somente avisos', 'canal de avisos'] as $ex) {
            if ($ex !== '' && mb_stripos($sub, $ex) !== false) {
                return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * Grupo comunitário que não é o canal de avisos (ex.: subgrupo com parentCommunityId).
 *
 * @param array<string, mixed> $item
 */
function achadinhosWhatsappDetectarSubgrupoComunidade(array $item): bool {
    if (achadinhosWhatsappDetectarAvisosComunidade($item)) {
        return false;
    }
    $flat = achadinhosWhatsappPrepararItemDetectarComunidade($item);
    foreach ([
        'parentCommunityId', 'parent_community_id', 'ParentCommunityId',
        'wa_parentCommunityId', 'communityParent', 'community_parent',
    ] as $k) {
        $v = $flat[$k] ?? null;
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '' && strpos($t, '@') !== false) {
                return true;
            }
        }
    }
    foreach (['linkedParent', 'linked_parent', 'LinkedParent', 'wa_linkedParent', 'wa_linked_parent', 'linkedParentJid', 'linked_parent_jid'] as $lp) {
        $v = $flat[$lp] ?? null;
        if (is_array($v)) {
            $jid = trim((string) ($v['jid'] ?? $v['id'] ?? $v['Jid'] ?? ''));
            if ($jid !== '' && strpos($jid, '@') !== false) {
                return true;
            }
        } elseif (is_string($v) && strpos($v, '@') !== false && trim($v) !== '') {
            return true;
        }
    }

    if (achadinhosWhatsappTextoIndicaAvisosComunidade($flat)) {
        return false;
    }
    if (achadinhosWhatsappTextoIndicaGrupoVinculadoComunidade($flat)) {
        return true;
    }

    return achadinhosWhatsappBuscarIndicioSubgrupoComunidadeRecursivo($item, 0);
}

/**
 * Destino para lista do painel admin: avisos da comunidade, outro vínculo com comunidade, ou grupo normal.
 *
 * @param array<string, mixed> $item
 */
function achadinhosWhatsappResolverDestinoPainelGrupo(array $item): string {
    if (achadinhosWhatsappDetectarAvisosComunidade($item)) {
        return 'comunidade_avisos';
    }
    if (achadinhosWhatsappDetectarSubgrupoComunidade($item)) {
        return 'comunidade';
    }

    return 'grupo';
}

/**
 * Se a lista de referência marcar comunidade (avisos ou subgrupo) para o mesmo JID, atualiza o destino em $principal.
 *
 * @param list<array{id: string, destino?: string}> $principal
 * @param list<array{id: string, destino?: string}> $referencia
 * @return list<array{id: string, destino?: string}>
 */
function achadinhosWhatsappMesclarDestinoPreferirAvisosComunidade(array $principal, array $referencia): array {
    $melhorPorJid = [];
    foreach ($referencia as $r) {
        $k = strtolower(trim((string) ($r['id'] ?? '')));
        $d = (string) ($r['destino'] ?? '');
        if ($k === '' || ($d !== 'comunidade_avisos' && $d !== 'comunidade')) {
            continue;
        }
        if (!isset($melhorPorJid[$k]) || achadinhosWhatsappDestinoPainelPrioridade($d) > achadinhosWhatsappDestinoPainelPrioridade($melhorPorJid[$k])) {
            $melhorPorJid[$k] = $d;
        }
    }
    if ($melhorPorJid === []) {
        return $principal;
    }
    foreach ($principal as $i => $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k === '' || !isset($melhorPorJid[$k])) {
            continue;
        }
        $pref = $melhorPorJid[$k];
        $cur = (string) ($g['destino'] ?? 'grupo');
        if (achadinhosWhatsappDestinoPainelPrioridade($pref) > achadinhosWhatsappDestinoPainelPrioridade($cur)) {
            $principal[$i]['destino'] = $pref;
        }
    }

    return $principal;
}

/**
 * Compara JIDs do WhatsApp (inclui @lid vs @s.whatsapp.net pelo número).
 */
function achadinhosWhatsappJidMesmoUsuario(string $jidA, string $jidB): bool {
    $a = strtolower(trim($jidA));
    $b = strtolower(trim($jidB));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $pa = strstr($a, '@', true) ?: $a;
    $pb = strstr($b, '@', true) ?: $b;
    $da = preg_replace('/\D/', '', $pa);
    $db = preg_replace('/\D/', '', $pb);

    return $da !== '' && $da === $db;
}

/**
 * Verifica se a linha de participante (Evolution/Baileys) corresponde ao JID do número conectado
 * (útil quando o id vem como @lid e o owner como @s.whatsapp.net).
 *
 * @param array<string, mixed> $p
 */
function achadinhosWhatsappParticipanteRowCorrespondeOwner(string $ownerJid, array $p): bool {
    $ownerJid = trim($ownerJid);
    if ($ownerJid === '') {
        return false;
    }
    $pid = trim((string) ($p['id'] ?? ''));
    if ($pid !== '' && achadinhosWhatsappJidMesmoUsuario($pid, $ownerJid)) {
        return true;
    }
    $digitsOwner = preg_replace('/\D/', '', strstr($ownerJid, '@', true) ?: $ownerJid);
    if ($digitsOwner === '') {
        return false;
    }
    foreach (['phoneNumber', 'phone', 'pn', 'user', 'wid', 'notify'] as $k) {
        if (empty($p[$k]) && $p[$k] !== 0 && $p[$k] !== '0') {
            continue;
        }
        $raw = (string) $p[$k];
        $d = preg_replace('/\D/', '', $raw);
        if ($d !== '' && $d === $digitsOwner) {
            return true;
        }
    }

    return false;
}

/**
 * Achata o JSON de fetchAllGroups em lista de itens (array) brutos.
 *
 * @param mixed $decoded
 * @return list<array<string, mixed>>
 */
function achadinhosEvolutionFlattenGruposFetchDecoded($decoded): array {
    if (!is_array($decoded)) {
        return [];
    }
    $list = $decoded;
    if (isset($list['response']) && is_array($list['response'])) {
        $list = $list['response'];
    }
    if (isset($list['message']) && is_array($list['message'])) {
        $msg = $list['message'];
        if ($msg !== [] && array_keys($msg) === range(0, count($msg) - 1)) {
            $list = $msg;
        }
    }
    if (isset($list['data']) && is_array($list['data'])) {
        $list = $list['data'];
    }
    if (isset($list['groups']) && is_array($list['groups'])) {
        $list = $list['groups'];
    }
    if (isset($list['group']) && is_array($list['group'])) {
        $innerG = $list['group'];
        if ($innerG !== [] && array_keys($innerG) === range(0, count($innerG) - 1)) {
            $list = $innerG;
        }
    }
    foreach (['result', 'results', 'items', 'records', 'content', 'value'] as $wrap) {
        if (!isset($list[$wrap]) || !is_array($list[$wrap])) {
            continue;
        }
        $inner = $list[$wrap];
        if (isset($inner['groups']) && is_array($inner['groups'])) {
            $list = $inner['groups'];

            break;
        }
        if ($inner !== [] && array_keys($inner) === range(0, count($inner) - 1)) {
            $list = $inner;

            break;
        }
    }
    if (isset($list['id']) && is_string($list['id']) && strpos($list['id'], '@g.us') !== false) {
        $isSeq = $list === [] || array_keys($list) === range(0, count($list) - 1);
        if (!$isSeq) {
            $list = [$list];
        }
    }
    if (!is_array($list)) {
        return [];
    }
    if ($list !== [] && array_keys($list) !== range(0, count($list) - 1)) {
        $list = array_values($list);
    }
    $out = [];
    foreach ($list as $item) {
        if (is_array($item)) {
            $out[] = $item;
        }
    }

    // Algumas builds da Evolution devolvem comunidades / subgrupos em chaves paralelas ao array principal.
    $extraRoots = [];
    if (is_array($decoded)) {
        $extraRoots[] = $decoded;
        if (isset($decoded['response']) && is_array($decoded['response'])) {
            $extraRoots[] = $decoded['response'];
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $extraRoots[] = $decoded['data'];
        }
    }
    foreach ($extraRoots as $root) {
        foreach ([
            'communityGroups', 'community_groups', 'CommunityGroups',
            'announcementGroups', 'announcement_groups', 'AnnouncementGroups',
            'linkedGroups', 'linked_groups', 'LinkedGroups',
            'subGroups', 'subgroups', 'sub_groups', 'SubGroups',
            'groupsCommunity', 'communitySubgroups',
        ] as $ek) {
            if (empty($root[$ek]) || !is_array($root[$ek])) {
                continue;
            }
            foreach ($root[$ek] as $sub) {
                if (is_array($sub)) {
                    $out[] = $sub;
                }
            }
        }
    }

    return $out;
}

/**
 * Normaliza um item bruto de grupo (fetchAllGroups / findChats metadata).
 *
 * @param array<string, mixed> $item
 * @return ?array{id: string, subject: string, size: int, destino: string}
 */
function achadinhosEvolutionNormalizarUmItemGrupoFetch(array $item): ?array {
    $gm = isset($item['groupMetadata']) && is_array($item['groupMetadata']) ? $item['groupMetadata'] : null;
    $id = (string) ($item['id'] ?? '');
    foreach (['jid', 'JID', 'groupJid', 'groupjid', 'remoteJid', 'remote_jid', 'remoteJidAlt', 'remote_jid_alt', 'chatId', 'chat_id'] as $k) {
        if ($id === '' && isset($item[$k]) && is_string($item[$k])) {
            $id = (string) $item[$k];
        }
    }
    if ($id === '' && isset($item['key']) && is_array($item['key'])) {
        $id = (string) ($item['key']['remoteJid'] ?? $item['key']['remoteJidAlt'] ?? $item['key']['participant'] ?? $item['key']['id'] ?? '');
    }
    if ($id === '' && $gm !== null) {
        $id = (string) ($gm['id'] ?? '');
    }
    $id = trim($id);
    if ($id !== '' && strpos($id, '@') === false && preg_match('/^\d+$/', $id)) {
        $id .= '@g.us';
    }
    if ($id === '' || strpos($id, '@g.us') === false) {
        return null;
    }
    $subject = trim((string) ($item['subject'] ?? $item['name'] ?? $item['Name'] ?? ''));
    if ($subject === '' && $gm !== null) {
        $subject = trim((string) ($gm['subject'] ?? $gm['desc'] ?? ''));
    }
    $size = isset($item['size']) ? (int) $item['size'] : 0;
    if (isset($item['participants']) && is_array($item['participants'])) {
        $size = max($size, count($item['participants']));
    }
    if ($size === 0 && $gm !== null && isset($gm['participants']) && is_array($gm['participants'])) {
        $size = count($gm['participants']);
    }

    return [
        'id' => $id,
        'subject' => $subject,
        'size' => $size,
        'destino' => achadinhosWhatsappResolverDestinoPainelGrupo($item),
    ];
}

/**
 * @param mixed $admin Campo admin do participante (Baileys: admin, superadmin, null).
 */
function achadinhosEvolutionParticipanteValorEhAdmin($admin): bool {
    if ($admin === null) {
        return false;
    }
    if (is_bool($admin)) {
        return $admin;
    }
    if (is_int($admin)) {
        return $admin === 1 || $admin === 2;
    }
    if (is_string($admin) && $admin !== '' && ctype_digit($admin)) {
        $i = (int) $admin;

        return $i === 1 || $i === 2;
    }
    $v = strtolower(trim((string) $admin));

    return $v !== '' && $v !== 'null' && !in_array($v, ['false', '0', 'member', 'regular'], true)
        && ($v === 'admin' || $v === 'superadmin' || $v === 'true' || $v === '1' || $v === 'creator');
}

/**
 * @param array<string, mixed> $row Linha de achadinhosEvolutionExtrairParticipantesDoItemGrupo
 */
function achadinhosEvolutionLinhaParticipanteIndicaAdmin(array $row): bool {
    if (achadinhosEvolutionParticipanteValorEhAdmin($row['admin'] ?? null)) {
        return true;
    }
    foreach (['isAdmin', 'is_admin', 'IsSuperAdmin', 'isSuperAdmin'] as $k) {
        if (!isset($row[$k])) {
            continue;
        }
        $v = $row[$k];
        if ($v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true')) {
            return true;
        }
    }
    foreach (['rank', 'Rank', 'role', 'Role'] as $rk) {
        if (!isset($row[$rk])) {
            continue;
        }
        $rv = strtolower(trim((string) $row[$rk]));

        if (in_array($rv, ['admin', 'superadmin', 'super_admin', 'creator'], true)) {
            return true;
        }
    }
    $t = strtolower(trim((string) ($row['participantType'] ?? $row['type'] ?? '')));

    return in_array($t, ['admin', 'superadmin', 'super_admin', 'creator'], true);
}

/**
 * @return list<array<string, mixed>>
 */
function achadinhosEvolutionExtrairParticipantesDoItemGrupo(array $item): array {
    $out = [];
    $sources = [];
    if (isset($item['participants']) && is_array($item['participants'])) {
        $sources[] = $item['participants'];
    }
    $gm = isset($item['groupMetadata']) && is_array($item['groupMetadata']) ? $item['groupMetadata'] : null;
    if ($gm !== null && isset($gm['participants']) && is_array($gm['participants'])) {
        $sources[] = $gm['participants'];
    }
    foreach ($sources as $plist) {
        foreach ($plist as $pk => $p) {
            if (is_string($p)) {
                $pid = trim($p);
                if ($pid !== '') {
                    $out[] = ['id' => $pid, 'admin' => null];
                }

                continue;
            }
            if (!is_array($p)) {
                continue;
            }
            $pid = trim((string) ($p['id'] ?? $p['jid'] ?? $p['user'] ?? ''));
            if ($pid === '' && is_string($pk) && strpos($pk, '@') !== false) {
                $pid = trim($pk);
            }
            if ($pid === '' && isset($p['key']) && is_array($p['key'])) {
                $pid = trim((string) ($p['key']['remoteJid'] ?? $p['key']['participant'] ?? $p['key']['id'] ?? ''));
            }
            if ($pid === '') {
                continue;
            }
            $out[] = [
                'id' => $pid,
                'admin' => $p['admin'] ?? $p['rank'] ?? $p['role'] ?? $p['Role'] ?? null,
                'isAdmin' => $p['isAdmin'] ?? $p['is_admin'] ?? null,
                'IsSuperAdmin' => $p['IsSuperAdmin'] ?? $p['isSuperAdmin'] ?? null,
                'participantType' => $p['participantType'] ?? $p['type'] ?? null,
                'rank' => $p['rank'] ?? $p['Rank'] ?? null,
                'role' => $p['role'] ?? $p['Role'] ?? null,
                'phoneNumber' => $p['phoneNumber'] ?? $p['phone'] ?? $p['pn'] ?? null,
            ];
        }
    }

    return $out;
}

/**
 * Indica se o dono da instância (ownerJid) é admin ou superadmin do grupo.
 *
 * @param array<string, mixed> $item Item bruto fetchAllGroups com participantes.
 */
function achadinhosEvolutionItemUsuarioEhAdminNoGrupo(array $item, string $ownerJid): bool {
    $ownerJid = trim($ownerJid);
    if ($ownerJid === '') {
        return false;
    }
    $gm = isset($item['groupMetadata']) && is_array($item['groupMetadata']) ? $item['groupMetadata'] : null;
    foreach (['owner', 'Owner', 'descOwner', 'groupOwner', 'ownerJid'] as $ok) {
        $ow = trim((string) ($item[$ok] ?? ($gm !== null ? ($gm[$ok] ?? '') : '')));
        if ($ow !== '' && achadinhosWhatsappJidMesmoUsuario($ow, $ownerJid)) {
            return true;
        }
    }
    foreach (achadinhosEvolutionExtrairParticipantesDoItemGrupo($item) as $row) {
        if (!achadinhosWhatsappParticipanteRowCorrespondeOwner($ownerJid, $row)) {
            continue;
        }
        if (achadinhosEvolutionLinhaParticipanteIndicaAdmin($row)) {
            return true;
        }
    }

    return false;
}

/**
 * Busca JID tipo número@s.whatsapp.net em estruturas aninhadas (fallback para fetchInstances).
 */
function achadinhosEvolutionExtrairOwnerJidRecursivo($node, int $depth = 0): string {
    if ($depth > 14 || $node === null) {
        return '';
    }
    if (is_string($node)) {
        $t = trim($node);
        if (preg_match('/^\d+@[gs]\.whatsapp\.net$/i', $t)) {
            return $t;
        }

        return '';
    }
    if (!is_array($node)) {
        return '';
    }
    $preferKeys = ['owner', 'ownerJid', 'wuid', 'wid', 'user', 'me', 'phone', 'pn'];
    foreach ($preferKeys as $pk) {
        if (!isset($node[$pk])) {
            continue;
        }
        $x = achadinhosEvolutionExtrairOwnerJidRecursivo($node[$pk], $depth + 1);
        if ($x !== '') {
            return $x;
        }
    }
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array(strtolower($k), $preferKeys, true)) {
            continue;
        }
        $x = achadinhosEvolutionExtrairOwnerJidRecursivo($v, $depth + 1);
        if ($x !== '') {
            return $x;
        }
    }

    return '';
}

/**
 * JID do número conectado (GET /instance/fetchInstances).
 */
function achadinhosEvolutionFetchOwnerJid(string $base, string $instanceName, string $apikey): string {
    $base = rtrim(trim($base), '/');
    $instanceName = trim($instanceName);
    $apikey = trim($apikey);
    if ($base === '' || $instanceName === '') {
        return '';
    }
    $urls = [
        $base . '/instance/fetchInstances?instanceName=' . rawurlencode($instanceName),
        $base . '/instance/fetchInstances',
    ];
    $j = null;
    foreach ($urls as $url) {
        $r = achadinhosEvolutionCurlPainel('GET', $url, $apikey, null, 12, 45);
        if ($r['curlErr'] === '' && $r['httpCode'] === 200 && is_array($r['decoded'])) {
            $j = $r['decoded'];

            break;
        }
    }
    if (!is_array($j)) {
        return '';
    }
    $want = strtolower($instanceName);
    $candidates = [];
    if (isset($j['instance']) && is_array($j['instance'])) {
        $candidates[] = $j['instance'];
    }
    if (isset($j['response']) && is_array($j['response'])) {
        $resp = $j['response'];
        if (isset($resp['instance']) && is_array($resp['instance'])) {
            $candidates[] = $resp['instance'];
        }
        if (is_array($resp) && $resp !== [] && array_keys($resp) === range(0, count($resp) - 1)) {
            foreach ($resp as $row) {
                if (is_array($row) && isset($row['instance']) && is_array($row['instance'])) {
                    $candidates[] = $row['instance'];
                } elseif (is_array($row)) {
                    $candidates[] = $row;
                }
            }
        }
    }
    if ($j !== [] && array_keys($j) === range(0, count($j) - 1)) {
        foreach ($j as $row) {
            if (is_array($row) && isset($row['instance']) && is_array($row['instance'])) {
                $candidates[] = $row['instance'];
            } elseif (is_array($row)) {
                $candidates[] = $row;
            }
        }
    }
    $fallback = '';
    foreach ($candidates as $inst) {
        if (!is_array($inst)) {
            continue;
        }
        $name = strtolower(trim((string) ($inst['instanceName'] ?? $inst['name'] ?? '')));
        $owner = trim((string) ($inst['owner'] ?? $inst['ownerJid'] ?? $inst['wuid'] ?? ''));
        if ($owner !== '' && strpos($owner, '@') !== false) {
            if ($fallback === '') {
                $fallback = $owner;
            }
            if ($name !== '' && $name === $want) {
                return $owner;
            }
        }
    }

    if ($fallback !== '') {
        return $fallback;
    }

    return achadinhosEvolutionExtrairOwnerJidRecursivo($j);
}

/**
 * Junta grupos do findChats que não vieram em fetchAllGroups (mesmo número participando).
 *
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $principal
 * @param list<array{id: string, subject: string, size: int, destino: string}>   $desdeFind
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionUnirFindChatsFaltantes(array $principal, array $desdeFind): array {
    $seen = [];
    foreach ($principal as $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k !== '') {
            $seen[$k] = true;
        }
    }
    $out = $principal;
    foreach ($desdeFind as $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k === '' || isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $g;
    }

    return achadinhosEvolutionGarantirDestinoGruposPainel($out);
}

/**
 * Normaliza JSON de GET /group/fetchAllGroups/{instance} (Evolution v2: array, ou envelope com "response"/"data").
 *
 * @param mixed $decoded
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionNormalizarListaGrupos($decoded): array {
    $out = [];
    foreach (achadinhosEvolutionFlattenGruposFetchDecoded($decoded) as $item) {
        $one = achadinhosEvolutionNormalizarUmItemGrupoFetch($item);
        if ($one !== null) {
            $out[] = $one;
        }
    }

    return $out;
}

/**
 * Extrai array de chats da resposta POST /chat/findChats/{instance}.
 *
 * @param mixed $decoded
 * @return list<array<string, mixed>>
 */
function achadinhosEvolutionExtrairChatsFindChatsResponse($decoded): array {
    if (!is_array($decoded)) {
        return [];
    }
    if (isset($decoded['chats']) && is_array($decoded['chats'])) {
        $raw = $decoded['chats'];
    } elseif (isset($decoded['response']) && is_array($decoded['response'])) {
        $resp = $decoded['response'];
        if (isset($resp['chats']) && is_array($resp['chats'])) {
            $raw = $resp['chats'];
        } elseif ($resp !== [] && array_keys($resp) === range(0, count($resp) - 1)) {
            $raw = $resp;
        } else {
            $raw = [];
        }
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $raw = $decoded['data'];
    } elseif ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
        $raw = $decoded;
    } else {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Monta lista de grupos a partir de findChats (rápido; costuma vir do banco da Evolution).
 *
 * @param list<array<string, mixed>> $chats
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionGruposDesdeFindChats(array $chats): array {
    $out = [];
    $seen = [];
    foreach ($chats as $item) {
        $jid = trim((string) ($item['remoteJid'] ?? $item['remote_jid'] ?? $item['remoteJidAlt'] ?? $item['remote_jid_alt'] ?? ''));
        if ($jid === '' && isset($item['id']) && is_string($item['id']) && strpos((string) $item['id'], '@g.us') !== false) {
            $jid = trim((string) $item['id']);
        }
        if ($jid === '' && isset($item['key']) && is_array($item['key'])) {
            $jid = trim((string) ($item['key']['remoteJid'] ?? $item['key']['remoteJidAlt'] ?? $item['key']['participant'] ?? ''));
        }
        if ($jid === '') {
            continue;
        }
        $isGroup = $item['isGroup'] ?? $item['is_group'] ?? null;
        $pareceComunidade = achadinhosWhatsappValorFlagVerdadeira($item['isCommunity'] ?? null)
            || achadinhosWhatsappValorFlagVerdadeira($item['is_community'] ?? null)
            || achadinhosWhatsappValorFlagVerdadeira($item['isCommunityAnnounce'] ?? null)
            || achadinhosWhatsappValorFlagVerdadeira($item['is_community_announce'] ?? null);
        if ($isGroup !== true && $isGroup !== 1 && $isGroup !== 'true' && stripos($jid, '@g.us') === false && !$pareceComunidade) {
            continue;
        }
        if (stripos($jid, '@g.us') === false) {
            continue;
        }
        $lk = strtolower($jid);
        if (isset($seen[$lk])) {
            continue;
        }
        $seen[$lk] = true;
        $subject = trim((string) ($item['name'] ?? $item['pushName'] ?? $item['push_name'] ?? $item['subject'] ?? ''));
        $size = 0;
        if (isset($item['size'])) {
            $size = (int) $item['size'];
        }
        if (isset($item['participants']) && is_array($item['participants'])) {
            $size = max($size, count($item['participants']));
        }
        $out[] = [
            'id' => $jid,
            'subject' => $subject,
            'size' => $size,
            'destino' => achadinhosWhatsappResolverDestinoPainelGrupo($item),
        ];
    }

    return $out;
}

/**
 * Mescla campo size usando mapa JID → tamanho (ex.: vindo de fetchAllGroups).
 *
 * @param list<array{id: string, subject: string, size: int}> $grupos
 * @param array<string, int>                                   $sizesByJidLower
 */
function achadinhosEvolutionMesclarTamanhosGrupos(array $grupos, array $sizesByJidLower): array {
    foreach ($grupos as $i => $g) {
        $k = strtolower((string) ($g['id'] ?? ''));
        if ($k !== '' && isset($sizesByJidLower[$k]) && $sizesByJidLower[$k] > 0) {
            $grupos[$i]['size'] = max((int) ($g['size'] ?? 0), $sizesByJidLower[$k]);
        }
    }

    return $grupos;
}

/**
 * Refina destino usando mapa JID → destino vindo do fetchAllGroups (prioridade: avisos > comunidade > grupo).
 *
 * @param array<string, string> $destinoPorJidLower ex.: ['120...@g.us' => 'comunidade_avisos']
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $grupos
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionMesclarDestinoGrupos(array $grupos, array $destinoPorJidLower): array {
    foreach ($grupos as $i => $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        $cur = (string) ($grupos[$i]['destino'] ?? '');
        if ($cur === '') {
            $cur = 'grupo';
        }
        if ($k !== '' && isset($destinoPorJidLower[$k])) {
            $pref = (string) $destinoPorJidLower[$k];
            if ($pref !== '' && achadinhosWhatsappDestinoPainelPrioridade($pref) > achadinhosWhatsappDestinoPainelPrioridade($cur)) {
                $grupos[$i]['destino'] = $pref;

                continue;
            }
        }
        if (!isset($grupos[$i]['destino']) || (string) $grupos[$i]['destino'] === '') {
            $grupos[$i]['destino'] = 'grupo';
        }
    }

    return $grupos;
}

/**
 * Enriquece a lista vinda de fetchAllGroups com nomes e flags de findChats (mesmo JID).
 * findChats costuma trazer menos grupos (só chats recentes); não deve ser a lista principal.
 *
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $grupos
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $gruposFind
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionEnriquecerGruposComFindChats(array $grupos, array $gruposFind): array {
    $byJid = [];
    foreach ($gruposFind as $gf) {
        $k = strtolower(trim((string) ($gf['id'] ?? '')));
        if ($k !== '') {
            $byJid[$k] = $gf;
        }
    }
    foreach ($grupos as $i => $g) {
        $k = strtolower(trim((string) ($g['id'] ?? '')));
        if ($k === '' || !isset($byJid[$k])) {
            continue;
        }
        $gf = $byJid[$k];
        $subMain = trim((string) ($g['subject'] ?? ''));
        $subF = trim((string) ($gf['subject'] ?? ''));
        if ($subF !== '' && $subMain === '') {
            $grupos[$i]['subject'] = $subF;
        }
        $gd = (string) ($gf['destino'] ?? '');
        if ($gd === 'comunidade_avisos' || $gd === 'comunidade') {
            $cur = (string) ($grupos[$i]['destino'] ?? 'grupo');
            if (achadinhosWhatsappDestinoPainelPrioridade($gd) > achadinhosWhatsappDestinoPainelPrioridade($cur)) {
                $grupos[$i]['destino'] = $gd;
            }
        }
    }

    return $grupos;
}

/**
 * Garante chave destino em cada item da lista de grupos do painel.
 *
 * @param list<array<string, mixed>> $grupos
 * @return list<array<string, mixed>>
 */
function achadinhosEvolutionGarantirDestinoGruposPainel(array $grupos): array {
    foreach ($grupos as $i => $g) {
        if (!isset($g['destino']) || (string) $g['destino'] === '') {
            $grupos[$i]['destino'] = 'grupo';
        }
    }

    return $grupos;
}

/**
 * @return array{curlErr: string, httpCode: int, body: string, decoded: ?array}
 */
function achadinhosEvolutionCurlPainel(string $method, string $url, string $apikey, ?string $jsonBody, int $connectTimeout, int $timeout): array {
    $headers = ['apikey: ' . $apikey, 'Accept: application/json'];
    $m = strtoupper($method);
    if ($m !== 'GET' && $jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_NOSIGNAL => true,
    ]);
    if ($m === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $m);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }
    }
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($body) ? $body : '';
    $decoded = json_decode($raw, true);

    return [
        'curlErr' => $curlErr,
        'httpCode' => $code,
        'body' => $raw,
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}

/**
 * Mapas JID (minúsculo) → size e destino preferido a partir do JSON de fetchAllGroups.
 *
 * @param mixed $decoded
 * @return array{sizes: array<string, int>, destino_por_jid: array<string, string>, avisos: array<string, true>}
 */
function achadinhosEvolutionMapsJidFetchAllGroups($decoded): array {
    $grupos = achadinhosEvolutionNormalizarListaGrupos($decoded);
    $sizes = [];
    $destinoPorJid = [];
    foreach ($grupos as $g) {
        $id = strtolower(trim((string) ($g['id'] ?? '')));
        if ($id === '') {
            continue;
        }
        $sizes[$id] = max($sizes[$id] ?? 0, (int) ($g['size'] ?? 0));
        $d = (string) ($g['destino'] ?? 'grupo');
        if ($d === '') {
            $d = 'grupo';
        }
        if (!isset($destinoPorJid[$id]) || achadinhosWhatsappDestinoPainelPrioridade($d) > achadinhosWhatsappDestinoPainelPrioridade($destinoPorJid[$id])) {
            $destinoPorJid[$id] = $d;
        }
    }
    $avisos = [];
    foreach ($destinoPorJid as $jid => $d) {
        if ($d === 'comunidade_avisos') {
            $avisos[$jid] = true;
        }
    }

    return ['sizes' => $sizes, 'destino_por_jid' => $destinoPorJid, 'avisos' => $avisos];
}

/**
 * Monta a lista do painel a partir do JSON de fetchAllGroups (com ou sem filtro “sou admin”).
 *
 * @param list<array{id: string, subject: string, size: int, destino?: string}> $gruposFind De findChats (enriquecimento de nomes).
 * @return list<array{id: string, subject: string, size: int, destino: string}>
 */
function achadinhosEvolutionProcessarRespostaFetchAllGruposPainel(
    array $decoded,
    array $gruposFind,
    bool $adminOnly,
    string $ownerJid
): array {
    if ($adminOnly) {
        $ownerJid = trim($ownerJid);
        if ($ownerJid === '') {
            return [];
        }
        $gruposAll = [];
        foreach (achadinhosEvolutionFlattenGruposFetchDecoded($decoded) as $raw) {
            if (!achadinhosEvolutionItemUsuarioEhAdminNoGrupo($raw, $ownerJid)) {
                continue;
            }
            $one = achadinhosEvolutionNormalizarUmItemGrupoFetch($raw);
            if ($one !== null) {
                $gruposAll[] = $one;
            }
        }
    } else {
        $gruposAll = achadinhosEvolutionNormalizarListaGrupos($decoded);
    }
    if ($gruposAll === []) {
        return [];
    }
    $maps = achadinhosEvolutionMapsJidFetchAllGroups($decoded);
    $gruposAll = achadinhosEvolutionMesclarTamanhosGrupos($gruposAll, $maps['sizes']);
    $gruposAll = achadinhosEvolutionMesclarDestinoGrupos($gruposAll, $maps['destino_por_jid']);
    if ($gruposFind !== []) {
        $gruposAll = achadinhosEvolutionEnriquecerGruposComFindChats($gruposAll, $gruposFind);
    }

    return achadinhosEvolutionGarantirDestinoGruposPainel($gruposAll);
}

function achadinhosEvolutionBuscarFindChatsPainelCompleto(string $base, string $seg, string $apikey, ?int $connectTimeout = null, ?int $timeout = null): array {
    $urlFind = $base . '/chat/findChats/' . $seg;
    $cT = $connectTimeout !== null ? max(5, $connectTimeout) : 15;
    $t = $timeout !== null ? max(10, $timeout) : 95;
    $r = achadinhosEvolutionCurlPainel('POST', $urlFind, $apikey, '{}', $cT, $t);
    if ($r['curlErr'] !== '' || !achadinhosEvolutionHttpRespostaOkPainel((int) $r['httpCode']) || !is_array($r['decoded'])) {
        return ['grupos' => [], 'raw_chats' => []];
    }
    $chatRows = achadinhosEvolutionExtrairChatsFindChatsResponse($r['decoded']);

    return [
        'grupos' => achadinhosEvolutionGruposDesdeFindChats($chatRows),
        'raw_chats' => $chatRows,
    ];
}

function achadinhosEvolutionBuscarGruposFindChatsParaPainel(string $base, string $seg, string $apikey, ?int $connectTimeout = null, ?int $timeout = null): array {
    return achadinhosEvolutionBuscarFindChatsPainelCompleto($base, $seg, $apikey, $connectTimeout, $timeout)['grupos'];
}

/**
 * Lista grupos só via POST /chat/findChats (rápido; sem GET fetchAllGroups).
 * Para lista completa com metadados extras, use {@see achadinhosEvolutionFetchGruposPainel}.
 *
 * @param array{url_base?:string,instancia?:string,api_key?:string,api_propria?:int|string} $conta
 * @return array{ok: bool, grupos: list<array{id: string, subject: string, size: int}>, message?: string}
 */
function achadinhosEvolutionFetchGruposPainelRapido(array $conta): array {
    $base = rtrim(trim((string) ($conta['url_base'] ?? '')), '/');
    $inst = trim((string) ($conta['instancia'] ?? ''));
    $key = trim((string) ($conta['api_key'] ?? ''));
    if ($base === '' || $inst === '') {
        return ['ok' => false, 'grupos' => [], 'message' => 'URL ou instância vazia.'];
    }
    $keys = [];
    if ($key !== '') {
        $keys[] = $key;
    }
    if (!empty($conta['api_propria'])) {
        $g = trim((string) getConfig('evolution_api_key_global', ''));
        if ($g !== '' && !in_array($g, $keys, true)) {
            $keys[] = $g;
        }
    }
    if ($keys === []) {
        return ['ok' => false, 'grupos' => [], 'message' => 'API Key não configurada.'];
    }
    $instSegs = [$inst];
    $enc = rawurlencode($inst);
    if ($enc !== $inst) {
        $instSegs[] = $enc;
    }
    $instSegs = array_values(array_unique($instSegs));
    $lastMsg = '';
    foreach ($keys as $apikey) {
        foreach ($instSegs as $seg) {
            $gruposFind = achadinhosEvolutionBuscarGruposFindChatsParaPainel($base, $seg, $apikey, 8, 35);
            if ($gruposFind !== []) {
                return ['ok' => true, 'grupos' => achadinhosEvolutionGarantirDestinoGruposPainel($gruposFind)];
            }
            $lastMsg = 'findChats não retornou grupos (instância conectada?).';
        }
    }

    return ['ok' => false, 'grupos' => [], 'message' => $lastMsg !== '' ? $lastMsg : 'Não foi possível listar grupos (modo rápido).'];
}

function achadinhosEvolutionHttpRespostaOkPainel(int $code): bool {
    return $code >= 200 && $code < 300;
}

/**
 * Lista grupos na Evolution (painel), fluxo alinhado à Uazapi (lista principal + complemento).
 *
 * 1) GET /group/fetchAllGroups com variantes de query (getParticipants false/true, snake_case, sem query).
 * 2) Instância no path: nome cru primeiro (como /message/sendText/{instance}), depois rawurlencode se diferente.
 * 3) Aceita qualquer HTTP 2xx com JSON array/objeto.
 * 4) POST /chat/findChats enriquece e inclui JIDs que faltaram no passo 1; se o GET não servir, usa só findChats.
 *
 * @param array{url_base?:string,instancia?:string,api_key?:string,api_propria?:int|string} $conta
 * @return array{ok: bool, grupos: list<array{id: string, subject: string, size: int}>, message?: string}
 */
function achadinhosEvolutionFetchGruposPainel(array $conta): array {
    $base = rtrim(trim((string) ($conta['url_base'] ?? '')), '/');
    $inst = trim((string) ($conta['instancia'] ?? ''));
    $key = trim((string) ($conta['api_key'] ?? ''));
    if ($base === '' || $inst === '') {
        return ['ok' => false, 'grupos' => [], 'message' => 'URL ou instância vazia.'];
    }
    $keys = [];
    if ($key !== '') {
        $keys[] = $key;
    }
    if (!empty($conta['api_propria'])) {
        $g = trim((string) getConfig('evolution_api_key_global', ''));
        if ($g !== '' && !in_array($g, $keys, true)) {
            $keys[] = $g;
        }
    }
    if ($keys === []) {
        return ['ok' => false, 'grupos' => [], 'message' => 'API Key não configurada.'];
    }
    $instSegs = [$inst];
    $enc = rawurlencode($inst);
    if ($enc !== $inst) {
        $instSegs[] = $enc;
    }
    $instSegs = array_values(array_unique($instSegs));

    $queryVariants = [
        'getParticipants=false',
        'get_participants=false',
        'getParticipants=true',
        '',
    ];
    $timeoutFetch = 180;
    $lastMsg = '';

    foreach ($keys as $apikey) {
        foreach ($instSegs as $seg) {
            $decodedFetch = null;
            $lastR = ['curlErr' => '', 'httpCode' => 0, 'decoded' => null];

            foreach ($queryVariants as $qv) {
                $suffix = $qv === '' ? '' : ('?' . $qv);
                $urlFetch = $base . '/group/fetchAllGroups/' . $seg . $suffix;
                $r = achadinhosEvolutionCurlPainel('GET', $urlFetch, $apikey, null, 20, $timeoutFetch);
                $lastR = $r;
                if ($r['curlErr'] !== '' || !achadinhosEvolutionHttpRespostaOkPainel((int) $r['httpCode'])) {
                    continue;
                }
                if (!is_array($r['decoded'])) {
                    continue;
                }
                $decodedFetch = $r['decoded'];
                $probe = achadinhosEvolutionProcessarRespostaFetchAllGruposPainel($decodedFetch, [], false, '');
                if ($probe !== []) {
                    break;
                }
            }

            $fcPack = achadinhosEvolutionBuscarFindChatsPainelCompleto($base, $seg, $apikey);
            $gruposFind = $fcPack['grupos'];

            if ($decodedFetch !== null) {
                $gruposAll = achadinhosEvolutionProcessarRespostaFetchAllGruposPainel($decodedFetch, $gruposFind, false, '');
                $gruposAll = achadinhosEvolutionUnirFindChatsFaltantes($gruposAll, $gruposFind);
                if ($gruposAll !== []) {
                    // Mesmo padrão da Uazapi: findChats pode marcar comunidade/avisos melhor que só fetchAllGroups.
                    $gruposAll = achadinhosWhatsappMesclarDestinoPreferirAvisosComunidade($gruposAll, $gruposFind);
                    $gruposAll = achadinhosEvolutionGarantirDestinoGruposPainel($gruposAll);

                    return ['ok' => true, 'grupos' => $gruposAll];
                }
            }

            if ($gruposFind !== []) {
                $gruposApenasFindChats = achadinhosEvolutionGarantirDestinoGruposPainel($gruposFind);

                return ['ok' => true, 'grupos' => $gruposApenasFindChats];
            }

            if (($lastR['curlErr'] ?? '') !== '') {
                $lastMsg = 'Evolution fetchAllGroups: ' . $lastR['curlErr'];
            } elseif (achadinhosEvolutionHttpRespostaOkPainel((int) ($lastR['httpCode'] ?? 0)) && is_array($lastR['decoded'])) {
                $lastMsg = 'Evolution retornou JSON sem grupos reconhecidos (confira instância e versão da API).';
            } else {
                $j2 = $lastR['decoded'] ?? null;
                $msg2 = is_array($j2) ? (string) ($j2['message'] ?? $j2['error'] ?? '') : '';
                $hc = (int) ($lastR['httpCode'] ?? 0);
                $lastMsg = $msg2 !== '' ? ('Evolution: ' . $msg2) : ('Evolution fetchAllGroups HTTP ' . $hc);
            }
        }
    }

    return ['ok' => false, 'grupos' => [], 'message' => $lastMsg !== '' ? $lastMsg : 'Não foi possível listar grupos na Evolution.'];
}

// #region agent log (instrumentação opcional — desligada por padrão em produção)
/**
 * Instrumentação NDJSON para diagnóstico. Ativa só com config `achadinhos_debug_instrumentacao` = 1
 * ou constante ACHADINHOS_DEBUG_INSTRUMENTACAO = true em config/config.php.
 */
function achadinhos_debug_instrumentacao_ativa(): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (defined('ACHADINHOS_DEBUG_INSTRUMENTACAO') && ACHADINHOS_DEBUG_INSTRUMENTACAO === true) {
        $cache = true;

        return true;
    }
    if (!function_exists('getConfig')) {
        $cache = false;

        return false;
    }
    $cache = getConfig('achadinhos_debug_instrumentacao', '0') === '1';

    return $cache;
}

/**
 * Caminho do ficheiro NDJSON de debug. Sobrescrever com config `achadinhos_debug_ndjson_path` (absoluto ou relativo ao project root).
 */
function achadinhos_agent_debug_log_path_df3052(): string {
    if (function_exists('getConfig')) {
        $custom = trim((string) getConfig('achadinhos_debug_ndjson_path', ''));
        if ($custom !== '') {
            if ($custom[0] === '/' || (strlen($custom) > 2 && $custom[1] === ':' && ($custom[2] === '\\' || $custom[2] === '/'))) {
                return $custom;
            }

            return rtrim(dirname(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $custom);
        }
    }
    $root = dirname(__DIR__);
    $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

    return $dir . DIRECTORY_SEPARATOR . 'agent-debug.ndjson';
}

/**
 * Sentinela: entrada de fluxo (ML / Shopee / dispatch).
 */
function achadinhos_agent_debug_sentinela(string $pontoNome): void {
    achadinhos_agent_debug_ndjson(
        'sentinela:' . $pontoNome,
        'sentinela',
        [
            'ponto' => $pontoNome,
            'caminho_escrita' => achadinhos_agent_debug_log_path_df3052(),
        ],
        'SENT'
    );
}

/**
 * Debug NDJSON — não registrar segredos nem PII. Sem efeito se instrumentação desligada.
 */
function achadinhos_agent_debug_ndjson(string $location, string $message, array $data, string $hypothesisId = ''): void {
    if (!achadinhos_debug_instrumentacao_ativa()) {
        return;
    }
    $path = achadinhos_agent_debug_log_path_df3052();
    $dir = dirname($path);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $e = error_get_last();
            error_log('[achadinhos_agent_debug_ndjson] mkdir falhou dir=' . $dir . ' ' . ($e['message'] ?? ''));

            return;
        }
    }

    if (!file_exists($path)) {
        $h = @fopen($path, 'cb');
        if ($h === false) {
            $e = error_get_last();
            error_log('[achadinhos_agent_debug_ndjson] criação do arquivo falhou path=' . $path . ' ' . ($e['message'] ?? 'fopen'));

            return;
        }
        fclose($h);
    }

    if (!is_writable($dir)) {
        error_log('[achadinhos_agent_debug_ndjson] diretório não gravável: ' . $dir);

        return;
    }
    if (!is_writable($path)) {
        error_log('[achadinhos_agent_debug_ndjson] arquivo não gravável: ' . $path);

        return;
    }

    $payload = [
        'sessionId' => 'df3052',
        'timestamp' => (int) round(microtime(true) * 1000),
        'location' => $location,
        'message' => $message,
        'data' => $data,
    ];
    if ($hypothesisId !== '') {
        $payload['hypothesisId'] = $hypothesisId;
    }
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    if (function_exists('error_clear_last')) {
        error_clear_last();
    }
    $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        $e = error_get_last();
        error_log('[achadinhos_agent_debug_ndjson] file_put_contents falhou path=' . $path . ' motivo=' . ($e['message'] ?? 'desconhecido'));
    }
}
// #endregion

require_once __DIR__ . '/dispatch-envio.php';
