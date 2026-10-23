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

        // Câmera e microfone liberados para a PRÓPRIA origem (`self`), nunca para
        // terceiros. A live/chamada (LiveKit, Sprint 15) publica a câmera da
        // performer via getUserMedia no MESMO documento — com `camera=()` o
        // navegador barra a captura ("Permissions policy violation: camera is not
        // allowed in this document") e a transmissão nunca começa. O erro aponta o
        // chunk `LiveOverlay-*.js` só porque o Vite empacotou o livekit-client ali;
        // quem pede a câmera é o <LiveRoom> (estúdio da performer), não o overlay.
        // Geolocalização segue barrada — a localização do produto é UF opt-in
        // digitada no servidor, o navegador nunca é consultado.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=()');

        // Anti-clickjacking também via CSP (não restringe carregamento de recursos,
        // então é seguro para o app Inertia/Vite; um default-src completo exige
        // afinar as fontes/scripts antes de ativar).
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        // HSTS só faz sentido sob HTTPS; navegadores ignoram o header em HTTP.
        //
        // O valor é condicional ao HOSTNAME, NÃO ao APP_ENV. Motivo (achado da
        // auditoria/pentest 23/10): a instância de produção serve o domínio real
        // (thelimen.com.br) E o apelido de dev (limen.dev.br) do MESMO checkout,
        // com o MESMO `.env` — e esse `.env` roda `APP_ENV=staging`. Gatear o HSTS
        // em `environment('production')` fazia o header completo NUNCA sair: o
        // usuário real no thelimen.com.br recebia o `max-age=300` fraco. Chavear
        // pelo host entrega HSTS forte a quem é produção e mantém o apelido de dev
        // reversível, independente do APP_ENV:
        //  - host de produção (thelimen.com.br / limen.com.br): max-age de 1 ano;
        //  - demais (limen.dev.br, localhost): max-age curto, reversível.
        //
        // `includeSubDomains`/`preload` ficam de FORA de propósito: são
        // compromissos ~irreversíveis (todo subdomínio tem de ser HTTPS; o preload
        // entra numa lista de navegador difícil de desfazer). Adicione
        // deliberadamente depois de confirmar que todo subdomínio do domínio de
        // produção serve HTTPS.
        if ($request->isSecure()) {
            $host = $request->getHost();
            $isProductionHost = str_ends_with($host, 'thelimen.com.br')
                || str_ends_with($host, 'limen.com.br');

            $response->headers->set(
                'Strict-Transport-Security',
                $isProductionHost
                    ? 'max-age=31536000'
                    : 'max-age=300'
            );
        }

        return $response;
    }
}
