# API de Criação de Produtos

API para criar produtos no sistema via integrações externas (n8n, Zapier, etc.)

## Endpoint

```
POST /api/create-product.php
```

## Autenticação

A API utiliza autenticação por token via header:

```
Authorization: Bearer {API_TOKEN}
```

**Token padrão:** `afiliadospro_api_token_digital_avance`

⚠️ **IMPORTANTE:** Altere este token em `api/create-product.php` para um valor seguro!

## Content-Type

```
Content-Type: application/json
```

## Campos

### Obrigatórios

- **nome** (string): Nome do produto
- **link_compra** (string): URL do link de compra

### Opcionais

- **categoria_id** (integer): ID da categoria do produto
- **imagem** (string): URL da imagem do produto (será baixada automaticamente) ou caminho relativo
- **preco** (float/string): Preço atual do produto
- **preco_original** (float/string): Preço original do produto (para calcular desconto)
- **destaque** (boolean): Se o produto deve aparecer em destaque (default: false)

## Comportamento

- **Ativo:** Produtos criados via API são automaticamente marcados como **ativos** e aparecem na loja
- **Imagem:** Se uma URL de imagem for fornecida, a API baixará e salvará a imagem localmente
- **Desconto:** Calculado automaticamente se `preco_original` e `preco` forem fornecidos

## Exemplo de Requisição (cURL)

```bash
curl -X POST https://seudominio.com/api/create-product.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer afiliadospro_api_token_digital_avance" \
  -d '{
    "nome": "Produto Exemplo",
    "link_compra": "https://exemplo.com/produto",
    "categoria_id": 1,
    "imagem": "https://exemplo.com/imagem.jpg",
    "preco": 99.90,
    "preco_original": 149.90,
    "destaque": false
  }'
```

## Exemplo de Requisição (JSON para n8n)

```json
{
  "nome": "Smartphone XYZ",
  "link_compra": "https://afiliado.exemplo.com/produto",
  "categoria_id": 2,
  "imagem": "https://exemplo.com/imagens/smartphone.jpg",
  "preco": 899.90,
  "preco_original": 1299.90,
  "destaque": true
}
```

## Resposta de Sucesso (201 Created)

```json
{
  "success": true,
  "message": "Produto criado com sucesso!",
  "data": {
    "id": 123,
    "nome": "Produto Exemplo",
    "categoria_id": 1,
    "categoria_nome": "Eletrônicos",
    "imagem": "uploads/produtos/api_696bdfe87747d3.jpg",
    "preco": 99.90,
    "preco_original": 149.90,
    "desconto": 33,
    "link_compra": "https://exemplo.com/produto",
    "destaque": false,
    "ativo": true,
    "created_at": "2024-01-17 21:00:00"
  }
}
```

## Respostas de Erro

### 401 Unauthorized (Token inválido)
```json
{
  "success": false,
  "error": "Token de autenticação inválido"
}
```

### 400 Bad Request (Campos faltando)
```json
{
  "success": false,
  "error": "Campos obrigatórios faltando",
  "errors": [
    "Campo \"nome\" é obrigatório",
    "Campo \"link_compra\" é obrigatório"
  ]
}
```

### 500 Internal Server Error
```json
{
  "success": false,
  "error": "Erro ao criar produto no banco de dados",
  "message": "Detalhes do erro..."
}
```

## Integração com n8n

1. Configure o nó HTTP Request
2. Método: POST
3. URL: `https://seudominio.com/api/create-product.php`
4. Authentication: Header Auth
   - Name: `Authorization`
   - Value: `Bearer afiliadospro_api_token_digital_avance`
5. Body: JSON
6. Envie os dados do produto no formato JSON

## Notas

- A imagem será baixada automaticamente se for uma URL válida
- O desconto é calculado automaticamente quando `preco_original > preco`
- Produtos criados via API sempre ficam ativos
- Categorias inexistentes ou inativas são ignoradas (produto sem categoria)
