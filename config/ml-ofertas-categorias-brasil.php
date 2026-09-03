<?php
/**
 * Categorias raiz do Mercado Livre Brasil usadas em /ofertas?category=MLB…
 * Referência: árvore de categorias MLB (documentação para desenvolvedores / site).
 * Ajuste rótulos se o ML renomear seções.
 *
 * @return array<string, string> id MLB => rótulo
 */
function mercadolivreOfertasCategoriasBrasilLista(): array {
    return [
        '' => 'Todas as ofertas',
        'MLB5672' => 'Acessórios para Veículos',
        'MLB271599' => 'Agro',
        'MLB1403' => 'Alimentos e Bebidas',
        'MLB1368' => 'Arte, Papelaria e Armarinho',
        'MLB1139' => 'Bebês',
        'MLB1246' => 'Beleza e Cuidado Pessoal',
        'MLB1132' => 'Brinquedos e Hobbies',
        'MLB1430' => 'Calçados, Roupas e Bolsas',
        'MLB1574' => 'Casa, Móveis e Decoração',
        'MLB1051' => 'Celulares e Telefones',
        'MLB1500' => 'Construção',
        'MLB1039' => 'Câmeras e Acessórios',
        'MLB5726' => 'Eletrodomésticos',
        'MLB1000' => 'Eletrônicos, Áudio e Vídeo',
        'MLB1276' => 'Esportes e Fitness',
        'MLB1167' => 'Ferramentas',
        'MLB12404' => 'Festas e Lembrancinhas',
        'MLB1144' => 'Games',
        'MLB1953' => 'Indústria e Comércio',
        'MLB1648' => 'Informática',
        'MLB1182' => 'Instrumentos Musicais',
        'MLB3937' => 'Joias e Relógios',
        'MLB3025' => 'Livros, Revistas e Comics',
        'MLB195815' => 'Mais Categorias',
        'MLB264586' => 'Pet Shop',
        'MLB1181' => 'Saúde',
        'MLB1743' => 'Veículos',
    ];
}
