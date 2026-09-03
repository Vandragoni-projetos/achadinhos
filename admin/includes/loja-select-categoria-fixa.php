<?php
/**
 * Select hierárquico para «Categoria fixa» nas páginas de loja.
 * Antes do require: $lojaCategoriaFixaFieldName (ex.: ml_site_categoria_id),
 * $lojaCategoriaFixaValor (valor atual string), $pdo (PDO).
 */
if (!isset($lojaCategoriaFixaFieldName) || $lojaCategoriaFixaFieldName === '') {
    return;
}
$__cfField = (string) $lojaCategoriaFixaFieldName;
$__cfVal = isset($lojaCategoriaFixaValor) ? (string) $lojaCategoriaFixaValor : '-1';
if (!($pdo instanceof PDO)) {
    return;
}
$__cfRows = function_exists('achadinhosListarCategoriasParaSelectLoja')
    ? achadinhosListarCategoriasParaSelectLoja($pdo)
    : $pdo->query('SELECT id, nome, slug, parent_id, ordem FROM categorias WHERE ativo = 1 ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
// Config antiga com id da categoria «mais-vendidos» → opção -2 (evita linha órfã no select)
foreach ($__cfRows as $__c) {
    if (strtolower(trim((string) ($__c['slug'] ?? ''))) !== 'mais-vendidos') {
        continue;
    }
    if ((string) (int) ($__c['id'] ?? 0) === $__cfVal) {
        $__cfVal = '-2';
        break;
    }
}
// Padrão e legado «0» / vazio → Todos (-1)
$__cfTodos = ($__cfVal === '' || $__cfVal === '0' || $__cfVal === '-1');
?>
<select id="<?php echo htmlspecialchars(str_replace('_', '-', $__cfField), ENT_QUOTES, 'UTF-8'); ?>"
        name="<?php echo htmlspecialchars($__cfField, ENT_QUOTES, 'UTF-8'); ?>"
        class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
    <option value="-1" <?php echo $__cfTodos ? 'selected' : ''; ?>>Todos (automático)</option>
    <option value="-2" <?php echo $__cfVal === '-2' ? 'selected' : ''; ?>>Mais vendidos</option>
    <?php foreach ($__cfRows as $__c) :
        if (strtolower(trim((string) ($__c['slug'] ?? ''))) === 'mais-vendidos') {
            continue;
        }
        $__depth = (int) ($__c['_tree_depth'] ?? 0);
        $__pad = $__depth > 0 ? str_repeat("\xc2\xb7 ", $__depth) : '';
        $__id = (int) ($__c['id'] ?? 0);
        ?>
    <option value="<?php echo $__id; ?>" <?php echo !$__cfTodos && $__cfVal !== '-2' && (string) $__id === $__cfVal ? 'selected' : ''; ?>><?php echo htmlspecialchars($__pad . (string) ($__c['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
    <?php endforeach; ?>
</select>
