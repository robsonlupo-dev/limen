<?php

use Illuminate\Support\Facades\Route;

/**
 * Dois registros com o mesmo nome de rota não geram erro no Laravel: o último a
 * ser registrado simplesmente vence no lookup por nome. Como routes/api.php é
 * registrada depois de routes/web.php, uma rota web que repita um nome da API
 * some — e route() no front passa a apontar para a URL da API, com verbo
 * diferente, devolvendo 405.
 *
 * Isso derrubou /performer/perfil: 'performer.profile.update' já existia como
 * PUT api/v1/performer/profile. Nada falha em tempo de registro, o route:list
 * mostra as duas, e o único sintoma é 405 em runtime.
 */
it('has no duplicate route names across web and api', function () {
    $duplicates = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->countBy()
        ->filter(fn (int $count) => $count > 1)
        ->keys()
        ->all();

    expect($duplicates)->toBe(
        [],
        'Nome de rota repetido: o último registrado vence no lookup e o outro fica '
        . 'inalcançável por route(). Renomeie um dos dois: ' . implode(', ', $duplicates),
    );
});
