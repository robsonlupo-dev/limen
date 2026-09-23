<?php

/*
|--------------------------------------------------------------------------
| Esqueleto de carregamento com brilho (feat/catalog-skeleton-shimmer)
|--------------------------------------------------------------------------
|
| O molde de carregamento é CSS + um componente Vue — o Pest não renderiza Vue,
| então protegemos aqui os invariantes que uma regressão silenciosa quebraria:
|
|  1. A classe `.mi-skeleton` e o facho (`::after` com keyframe de varredura)
|     existem na folha de microinterações.
|  2. Há fallback sob `prefers-reduced-motion` — o brilho vira pulsar de opacidade
|     (nada varrendo a tela para quem pediu menos movimento).
|  3. O SkeletonCard reutilizável existe, é decorativo (aria-hidden) e usa a classe.
|  4. Os catálogos usam o SkeletonCard (e não sobrou `animate-pulse` nas grades).
|
| Varredura estática de arquivo — fica em tests/Unit (não precisa de banco).
*/

function skeletonCss(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/css/micro-interactions.css');
}

it('define a classe .mi-skeleton com o facho que varre', function () {
    $css = skeletonCss();

    expect($css)
        ->toContain('.mi-skeleton')
        ->and($css)->toContain('.mi-skeleton::after')
        ->and($css)->toContain('@keyframes mi-skeleton-sweep')
        // O facho move-se por transform (GPU), nunca por width/left.
        ->and($css)->toMatch('/mi-skeleton::after.*?transform:\s*translateX/s');
});

it('desliga o facho e cai num pulsar suave sob prefers-reduced-motion', function () {
    $css = skeletonCss();
    $reduced = substr($css, strpos($css, 'prefers-reduced-motion'));

    expect($reduced)
        ->toContain('.mi-skeleton::after')          // o facho entra na lista de "animation: none"
        ->and($reduced)->toContain('mi-skeleton-fade'); // e ganha o pulsar de opacidade
});

it('o componente SkeletonCard existe, é decorativo e usa a classe', function () {
    $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/SkeletonCard.vue');

    expect($vue)->not->toBeFalse()
        ->and($vue)->toContain('mi-skeleton')
        ->and($vue)->toContain('aria-hidden="true"');
});

it('os catálogos usam o SkeletonCard e não deixam animate-pulse nas grades', function () {
    $pages = [
        'resources/js/Pages/Catalog/Index.vue',
        'resources/js/Pages/Performers/Index.vue',
        'resources/js/Pages/Performer/Members.vue',
    ];

    foreach ($pages as $page) {
        // NB: o `toContain` do Pest é variádico (todos os argumentos viram texto
        // obrigatório) — nada de mensagem no 2º parâmetro. Um assert por texto.
        $src = file_get_contents(dirname(__DIR__, 2).'/'.$page);
        expect($src)->toContain('SkeletonCard');
        expect($src)->not->toContain('animate-pulse');
    }
});
