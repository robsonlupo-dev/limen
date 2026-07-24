<?php

use App\Http\Middleware\BlockBannedUsers;
use App\Http\Middleware\DocumentsAccepted;
use App\Http\Middleware\EnsureActiveCircle;
use App\Http\Middleware\EnsureMemberVerified;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\GeoBlock;
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
        health: '/up',
    )
    // `channels:` sairia daqui com /broadcasting/auth no middleware `web` e mais
    // nada — sem `auth` e, o que importa aqui, sem `2fa`. Os callbacks em
    // routes/channels.php só checam participação, então a sessão da performer
    // que o TwoFactorChallenge acabou de mandar para o desafio ainda assinava
    // `conversation.{id}` e lia o chat em tempo real — exatamente a superfície
    // que o gate diz cobrir. Hoje o Reverb não roda (driver `log`), então isto
    // é preventivo; no dia que subir, deixa de ser.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', '2fa']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Geobloqueio (FOSTA-SESTA). Nos grupos `web` e `api`, e NÃO no append
        // global: o append pegaria `/up`, e monitor de uptime costuma sondar
        // dos EUA — o health check viraria alarme falso permanente. É no-op
        // enquanto GEO_DRIVER=none (o estado de hoje); ver config/geo.php.
        $middleware->web(prepend: [GeoBlock::class]);
        $middleware->api(prepend: [GeoBlock::class]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            // Mata a sessão web viva de conta banida a cada request (o bloqueio
            // no login só cobre o PRÓXIMO acesso). No grupo `web` inteiro, não
            // por área: banido some do site todo, não só do dashboard. No-op
            // para guest e para qualquer status que não seja `banned`.
            BlockBannedUsers::class,
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
            'member.verified' => EnsureMemberVerified::class,
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
