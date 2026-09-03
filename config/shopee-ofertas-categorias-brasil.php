<?php
/**
 * Categorias Shopee Brasil: o ID do grupo vira palavra-chave (rótulo) em productOfferV2(keyword).
 * A API Brasil não expõe mais categoryId na query; o rótulo é enviado como busca por nome.
 * IDs = referência às categorias do site; mantenha rótulos alinhados ao que a busca Shopee retorna bem.
 *
 * @return array<string, string> id (string só dígitos) => rótulo
 */
function shopeeOfertasCategoriasBrasilLista(): array {
    return [
        '' => 'Todas as ofertas',
        '11042813' => 'Moda Feminina',
        '11042715' => 'Moda Masculina',
        '11042812' => 'Moda Infantil',
        '11043245' => 'Beleza e Cuidados Pessoais',
        '11043045' => 'Saúde',
        '11046351' => 'Celulares e Acessórios',
        '11046360' => 'Informática e Acessórios',
        '11043112' => 'Games e Consoles',
        '11043100' => 'Esportes e Lazer',
        '11043150' => 'Brinquedos e Hobbies',
        '11059943' => 'Casa, Cozinha e Lavanderia',
        '11044264' => 'Móveis e Decoração',
        '11058594' => 'Mãe e Bebê',
        '11043140' => 'Pet Shop',
        '11043120' => 'Automotivo',
        '11043130' => 'Ferramentas e Construção',
        '11057404' => 'Alimentos e Bebidas',
        '11042745' => 'Calçados',
        '11043110' => 'Eletrônicos',
    ];
}
