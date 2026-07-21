<?php

use App\Http\Middleware\DocumentsAccepted;
use App\Http\Middleware\EnsureActiveCircle;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TwoFactorChallenge;
use App\Http\Middleware\VerifyAsaasWebhookIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        // UI-only flags written by the front-end in plaintext (age gate + intro).
        // They carry no secret, so they must be exempt from cookie encryption —
        // otherwise Laravel discards the JS-set cookie and the gate/intro loop.
        $middleware->encryptCookies(except: [
            'limen_age_confirmed',
            'limen_intro_seen',
        ]);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'circle' => EnsureActiveCircle::class,
            'documents.accepted' => DocumentsAccepted::class,
            '2fa' => TwoFactorChallenge::class,
            'asaas.webhook_ip' => VerifyAsaasWebhookIp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // PII e dados de cartão nunca voltam para a sessão/log num erro de validação.
        $exceptions->dontFlash(['cpf', 'cpfCnpj', 'card_number', 'card_cvv', 'card_holder']);
    })->create();
