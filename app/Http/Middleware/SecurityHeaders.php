<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Apply baseline security headers to every response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Anti-clickjacking também via CSP (não restringe carregamento de recursos,
        // então é seguro para o app Inertia/Vite; um default-src completo exige
        // afinar as fontes/scripts antes de ativar).
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        // HSTS só faz sentido sob HTTPS; navegadores ignoram o header em HTTP.
        //
        // O valor é condicional ao ambiente para que o deploy (git reset --hard)
        // não reintroduza um HSTS agressivo em staging:
        //  - produção (limen.com.br): max-age completo + includeSubDomains + preload;
        //  - staging/dev (limen.dev.br): max-age curto, SEM preload, para que a redução
        //    seja reversível e não quebre proxies de inspeção SSL corporativos.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                app()->environment('production')
                    ? 'max-age=31536000; includeSubDomains; preload'
                    : 'max-age=300'
            );
        }

        return $response;
    }
}
