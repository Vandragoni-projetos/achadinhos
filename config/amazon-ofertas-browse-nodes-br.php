<?php
/**
 * Browse Nodes (departamentos) Amazon.com.br para PA-API SearchItems (marketplace www.amazon.com.br).
 * «Todas» = não envia BrowseNodeId na requisição.
 *
 * IDs conferidos com URLs de pesquisa / vitrine em amazon.com.br (abril/2026).
 * A Amazon pode alterar nós ao longo do tempo; use nó personalizado no grupo se precisar de um ID novo.
 *
 * @see https://webservices.amazon.com/paapi5/documentation/use-cases/organization-of-items-on-amazon/search-index.html
 * @see https://webservices.amazon.com/paapi5/documentation/locale-reference/brazil.html
 */

/**
 * IDs antigos (lista pré-2026 ou incorretos) → ID atual comprovado na Amazon.com.br.
 *
 * @var array<string, string>
 */
function amazonBrowseNodesBrasilMigracaoLegado(): array {
    return [
        // Casa: nó US / inválido na BR → raiz «Produtos para Casa»
        '6564055011' => '16191000011',
        // Computadores: ID alternativo visto no site → nó principal do departamento
        '16339954011' => '16339926011',
        // Moda: ID era subnó errado (ex.: Cozinha) → Amazon Fashion BR
        '16957125011' => '17365811011',
        // «Esportes»: no site BR apontava para DIY → Esporte, aventura e lazer
        '16957207011' => '17349396011',
        // Alimentos: nó incorreto → raiz departamento (mercado)
        '16957162011' => '19777985011',
        // Pet: legado → raiz Pet Shop BR
        '16957183011' => '19653947011',
        // Ferramentas: legado (DIY) → Ferramentas e materiais de construção
        '16957221011' => '17113538011',
        // Papelaria: legado → Papelaria e escritório
        '16957250011' => '17095634011',
        // Brinquedos: nó genérico → departamento «Brinquedos e Jogos» BR
        '6740744011' => '16194299011',
        // Automotivo: consolidar para raiz BR usada nos mais vendidos
        '6740742011' => '19701483011',
    ];
}

/**
 * Normaliza ID de nó (apenas dígitos) ou vazio = todas as categorias.
 * Aceita IDs fora da lista (nó personalizado); migra IDs legados conhecidos.
 */
function amazonNormalizarBrowseNodeGrupo(string $id): string {
    $id = trim($id);
    if ($id === '' || $id === '0') {
        return '';
    }
    if (!preg_match('/^\d{1,20}$/', $id)) {
        return '';
    }
    $map = amazonBrowseNodesBrasilMigracaoLegado();
    if (isset($map[$id])) {
        $id = $map[$id];
    }

    return $id;
}

/**
 * @return array<string, string> nodeId => rótulo (chave '' = todas)
 */
function amazonOfertasBrowseNodesBrasilLista(): array {
    return [
        '' => 'Todas as categorias',
        '16209062011' => 'Eletrônicos',
        '16339926011' => 'Computadores e Informática',
        '16191000011' => 'Casa',
        '23783015011' => 'Cozinha',
        '6740748011' => 'Livros',
        '17365811011' => 'Moda',
        '6740746011' => 'Beleza',
        '17349396011' => 'Esporte, aventura e lazer',
        '16194299011' => 'Brinquedos e jogos',
        '19701483011' => 'Automotivo',
        '19777985011' => 'Alimentos e bebidas',
        '19653947011' => 'Pet shop',
        '17113538011' => 'Ferramentas e materiais de construção',
        '17095634011' => 'Papelaria e escritório',
        '16253313011' => 'Games e consoles — PC',
        '20971488011' => 'Games e consoles — PlayStation 5',
        '16253308011' => 'Games e consoles — Nintendo Switch',
    ];
}
