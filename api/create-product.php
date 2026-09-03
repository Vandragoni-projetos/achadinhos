<?php
/**
 * API para criar produtos via n8n ou outras integrações
 * 
 * Método: POST
 * Content-Type: application/json
 * 
 * Headers obrigatórios:
 * - Authorization: Bearer {API_TOKEN}
 * 
 * Campos obrigatórios:
 * - nome: Nome do produto (string)
 * - link_compra: Link de compra do produto (string/URL)
 * 
 * Campos opcionais:
 * - categoria_id: ID da categoria (integer)
 * - imagem: URL da imagem ou caminho relativo (string)
 * - preco: Preço TOTAL/à vista do produto (float/string) — NUNCA use o valor da parcela aqui
 * - preco_original: Preço original (float/string)
 * - parcelas: Número de parcelas (integer, ex: 12)
 * - preco_parcela: Valor de cada parcela (float/string, ex: 46.43) — exibe "em 12x de R$ 46,43"
 * - destaque: Se o produto é destaque (boolean, default: false)
 * 
 * Exemplo: R$ 480,15 em 12x de R$ 46,43 → preco=480.15, parcelas=12, preco_parcela=46.43
 * 
 * Exemplo de requisição:
 * {
 *   "nome": "Produto Exemplo",
 *   "link_compra": "https://exemplo.com/produto",
 *   "categoria_id": 1,
 *   "imagem": "https://exemplo.com/imagem.jpg",
 *   "preco": 480.15,
 *   "preco_original": 769.00,
 *   "parcelas": 12,
 *   "preco_parcela": 46.43,
 *   "destaque": false
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Permitir OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido. Use POST.'
    ]);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Configuração do token de API (você pode mudar isso ou salvar no banco)
define('API_TOKEN', 'afiliadospro_api_token_digital_avance');

/**
 * Função para baixar imagem de URL e salvar localmente
 */
function downloadImageFromUrl($url, $pasta = 'uploads/produtos/') {
    if (empty($url)) {
        return null;
    }
    
    // Se já for um caminho relativo local, retornar como está
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        // Verificar se é um caminho válido
        if (file_exists(__DIR__ . '/../' . $url)) {
            return $url;
        }
        return null;
    }
    
    try {
        // Criar diretório se não existir
        $uploadDir = __DIR__ . '/../' . $pasta;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Validar URL da imagem
        $headers = @get_headers($url, 1);
        if (!$headers || strpos($headers[0], '200') === false) {
            return null;
        }
        
        // Verificar se é uma imagem
        $contentType = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        if (is_array($contentType)) {
            $contentType = end($contentType);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array(strtolower($contentType), $allowedTypes)) {
            // Tentar baixar mesmo assim e verificar depois
        }
        
        // Baixar a imagem
        $imageData = @file_get_contents($url);
        if ($imageData === false) {
            return null;
        }
        
        // Verificar se os dados são realmente uma imagem
        $tempFile = tmpfile();
        $tempPath = stream_get_meta_data($tempFile)['uri'];
        file_put_contents($tempPath, $imageData);
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tempPath);
        finfo_close($finfo);
        fclose($tempFile);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return null;
        }
        
        // Gerar nome único
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = 'jpg'; // Default
        }
        $fileName = uniqid('api_', true) . '.' . $extension;
        $filePath = $uploadDir . $fileName;
        
        // Salvar imagem
        if (file_put_contents($filePath, $imageData) !== false) {
            return $pasta . $fileName;
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Erro ao baixar imagem da URL {$url}: " . $e->getMessage());
        return null;
    }
}

// Verificar autenticação
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Token de autenticação não fornecido. Use: Authorization: Bearer {token}'
    ]);
    exit;
}

// Extrair token
$token = str_replace('Bearer ', '', $authHeader);
if ($token !== API_TOKEN) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Token de autenticação inválido'
    ]);
    exit;
}

// Obter dados JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'JSON inválido: ' . json_last_error_msg()
    ]);
    exit;
}

// Validar campos obrigatórios
$errors = [];

if (empty($data['nome'])) {
    $errors[] = 'Campo "nome" é obrigatório';
}

if (empty($data['link_compra'])) {
    $errors[] = 'Campo "link_compra" é obrigatório';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Campos obrigatórios faltando',
        'errors' => $errors
    ]);
    exit;
}

// Processar dados (usar parsePrecoBr para evitar erros BR/EN, ex.: 504.31 virar 50431)
$nome = trim($data['nome']);
$link_compra = trim($data['link_compra']);
$categoria_id = !empty($data['categoria_id']) ? (int)$data['categoria_id'] : null;
$preco = !empty($data['preco']) ? (parsePrecoBr($data['preco']) ?? floatval(str_replace([','], ['.'], (string)$data['preco']))) : null;
$preco_original = !empty($data['preco_original']) ? (parsePrecoBr($data['preco_original']) ?? floatval(str_replace([','], ['.'], (string)$data['preco_original']))) : null;
$parcelas = isset($data['parcelas']) && $data['parcelas'] !== '' ? max(1, (int)$data['parcelas']) : null;
$preco_parcela = !empty($data['preco_parcela']) ? (parsePrecoBr($data['preco_parcela']) ?? floatval(str_replace([','], ['.'], (string)$data['preco_parcela']))) : null;
$destaque = isset($data['destaque']) ? (bool)$data['destaque'] : false;
$ativo = 1; // Sempre ativo para produtos criados via API

// Verificar se produto já existe (evitar duplicatas)
$pdo = getDB();
$nomeNorm = mb_substr($nome, 0, 50);
$stmt = $pdo->prepare("SELECT id FROM produtos WHERE SUBSTRING(nome, 1, 50) = ? LIMIT 1");
$stmt->execute([$nomeNorm]);
if ($stmt->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode([
        'success' => false,
        'error' => 'Produto já existe no site (nome similar).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sanidade: se preco_original > 10*preco, considerar inválido (ex.: 74738 em vez de 747.38)
if (function_exists('sanearPrecoOriginal') && $preco > 0 && $preco_original > 0) {
    list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, 0);
} else {
    $desconto = 0;
}
if ($desconto === 0 && $preco_original > 0 && $preco > 0 && $preco_original > $preco) {
    if (function_exists('calcularDesconto')) {
        $desconto = calcularDesconto($preco_original, $preco);
    } else {
        $desconto = round((($preco_original - $preco) / $preco_original) * 100);
    }
}

// Se temos preco_parcela mas não parcelas, não mostrar (evita confusão)
if ($preco_parcela !== null && $parcelas === null) {
    $preco_parcela = null;
}
// Se temos parcelas mas não preco_parcela, não mostrar
if ($parcelas !== null && $preco_parcela === null) {
    $parcelas = null;
}

// Garantir que preco é o total, não o valor da parcela
if (function_exists('corrigirPrecoTotalParcelas') && $parcelas && $preco_parcela) {
    $preco = corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela);
}

// Processar imagem
$imagem = null;
if (!empty($data['imagem'])) {
    // Tentar baixar da URL
    $imagem = downloadImageFromUrl($data['imagem']);
    
    if (!$imagem) {
        // Se não conseguiu baixar, pode ser um caminho local já existente
        $imagem = null; // Ou lançar erro se imagem for obrigatória
    }
}

// Verificar se categoria existe (se fornecida)
if ($categoria_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM categorias WHERE id = ? AND ativo = 1");
    $stmt->execute([$categoria_id]);
    if (!$stmt->fetch()) {
        $categoria_id = null; // Se categoria não existe, definir como null
    }
}

// Inserir produto no banco
try {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("
        INSERT INTO produtos (nome, categoria_id, imagem, preco, preco_original, desconto, parcelas, preco_parcela, link_compra, destaque, ativo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $nome,
        $categoria_id,
        $imagem,
        $preco,
        $preco_original,
        $desconto,
        $parcelas,
        $preco_parcela,
        $link_compra,
        $destaque ? 1 : 0,
        $ativo
    ]);
    
    $produtoId = $pdo->lastInsertId();
    
    // Buscar produto criado
    $stmt = $pdo->prepare("
        SELECT p.*, c.nome as categoria_nome 
        FROM produtos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$produtoId]);
    $produto = $stmt->fetch();
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Produto criado com sucesso!',
        'data' => [
            'id' => (int)$produto['id'],
            'nome' => $produto['nome'],
            'categoria_id' => $produto['categoria_id'] ? (int)$produto['categoria_id'] : null,
            'categoria_nome' => $produto['categoria_nome'],
            'imagem' => $produto['imagem'],
            'preco' => $produto['preco'] ? (float)$produto['preco'] : null,
            'preco_original' => $produto['preco_original'] ? (float)$produto['preco_original'] : null,
            'desconto' => (int)$produto['desconto'],
            'parcelas' => !empty($produto['parcelas']) ? (int)$produto['parcelas'] : null,
            'preco_parcela' => !empty($produto['preco_parcela']) ? (float)$produto['preco_parcela'] : null,
            'link_compra' => $produto['link_compra'],
            'destaque' => (bool)$produto['destaque'],
            'ativo' => (bool)$produto['ativo'],
            'created_at' => $produto['created_at']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    error_log("Erro ao criar produto via API: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao criar produto no banco de dados',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
