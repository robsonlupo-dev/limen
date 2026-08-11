<?php

/*
|--------------------------------------------------------------------------
| Microinterações premium (feat/micro-interactions)
|--------------------------------------------------------------------------
|
| A camada de microinterações é CSS puro — o Pest não renderiza Vue, então não
| há como exercitar a animação em si. O que ESTE teste protege são os invariantes
| que uma regressão silenciosa quebraria e que ninguém pegaria numa review:
|
|  1. `prefers-reduced-motion: reduce` desliga tudo — acessibilidade. Remover o
|     bloco por descuido faria o site animar para quem pediu menos movimento.
|  2. Zero asset externo — nenhum `url(...)` na folha (ExternalAssetPolicyTest
|     cobre hosts literais; aqui garantimos que a folha nova não introduz NENHUM
|     url(), nem relativo, para não abrir exceção ao princípio nº 1).
|  3. A folha está de fato importada por app.css (senão nada disso embarca).
|
| Varredura estática de arquivo — fica em tests/Unit (não precisa de banco).
*/

it('a folha de microinterações existe e desliga tudo sob prefers-reduced-motion', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/micro-interactions.css');

    expect($css)->not->toBeFalse()
        ->and($css)->toContain('prefers-reduced-motion: reduce');

    // O bloco de reduced-motion tem de zerar animação/transição.
    $reduced = substr($css, strpos($css, 'prefers-reduced-motion'));
    expect($reduced)
        ->toContain('animation: none')
        ->and($reduced)->toContain('transition: none');
});

it('não introduz nenhum asset externo (sem url() na folha)', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/micro-interactions.css');

    // Ignora comentários /* ... */ (a doc do arquivo cita a palavra url() em prosa);
    // o que importa é não haver url() em REGRA de CSS.
    $rules = preg_replace('#/\*.*?\*/#s', '', $css);

    // Nenhum url(...) — nem remoto, nem relativo. Só transform/opacity/box-shadow.
    expect($rules)->not->toMatch('/\burl\s*\(/i');
});

it('está importada por app.css (senão não embarca no bundle)', function () {
    $app = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    expect($app)->toContain('micro-interactions.css');
});
