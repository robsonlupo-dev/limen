<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        // UI-only flags written by the front-end in plaintext (age gate + intro).
        // They carry no secret, so they must be exempt from cookie encryption —
        // otherwise Laravel discards the JS-set cookie and the gate/intro loop.
        $middleware->encryptCookies(except: [
            'limen_age_confirmed',
            'limen_intro_seen',
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'circle' => \App\Http\Middleware\EnsureActiveCircle::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->dontFlash(['cpf', 'cpfCnpj']);
    })->create();
