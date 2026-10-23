<?php

use Illuminate\Support\Facades\RateLimiter;

/**
 * Fix do 429 ao publicar (UAT 23/10). O `throttle:N,1` INLINE do Laravel chaveia
 * o balde SÓ por usuário (sem a rota), então todas as rotas de performer dividiam
 * um contador e o limite baixo do publish (10/min) estourava quando o painel
 * recarregava cheio de miniaturas (cada `content.image` é um hit no mesmo balde).
 * O publish e o serving de imagem passam a usar limiters NOMEADOS, cada um com
 * balde próprio — não se atropelam mais. Estes testes travam a fiação.
 */

it('publica por um limiter nomeado próprio (não o balde inline compartilhado)', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('performer.content.store')->gatherMiddleware())
        ->toContain('throttle:performer-publish')
        ->and($routes->getByName('performer.fanclub.content')->gatherMiddleware())
        ->toContain('throttle:performer-publish');
});

it('serve imagem por um limiter nomeado próprio, separado do publish', function () {
    $routes = app('router')->getRoutes();

    foreach (['performer.gallery.image', 'performer.content.image', 'content.image'] as $name) {
        expect($routes->getByName($name)->gatherMiddleware())
            ->toContain('throttle:media-serving');
    }
});

it('registra os limiters nomeados', function () {
    expect(RateLimiter::limiter('performer-publish'))->not->toBeNull()
        ->and(RateLimiter::limiter('media-serving'))->not->toBeNull();
});
