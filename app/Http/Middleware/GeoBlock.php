<?php

namespace App\Http\Middleware;

use App\Services\GeoLocationService;
use App\Support\Audit;
use App\Support\ClientFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra requisições vindas de países na lista (hoje: EUA, por FOSTA-SESTA).
 *
 * 451 (Unavailable For Legal Reasons) e não 403: o código existe exatamente
 * para "eu poderia te atender, a lei é que não deixa", e um 403 misturaria esta
 * recusa com as de autorização no monitoramento.
 *
 * NO-OP enquanto GEO_DRIVER=none — que é o estado de hoje. Ver config/geo.php:
 * o middleware está montado e testado esperando a fonte de geolocalização, e é
 * fail-OPEN de propósito (fail-closed sem fonte derruba o site inteiro).
 *
 * Aplicado aos grupos `web` e `api`, o que deixa `/up` de fora — o health check
 * não tem grupo de middleware. Isso é intencional e não é um furo: além de o
 * /up não expor conteúdo, monitor de uptime costuma sondar dos EUA, e barrá-lo
 * transformaria o geobloqueio em alarme falso permanente.
 */
class GeoBlock
{
    public function __construct(private GeoLocationService $geo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $country = $this->geo->countryFor($request);

        if (! $this->geo->isBlocked($country)) {
            return $next($request);
        }

        $this->auditOnce($request, $country);

        // abort() e não uma resposta montada aqui: fora de api/* o handler
        // renderiza a página de erro, e em api/* o `shouldRenderJsonWhen` do
        // bootstrap/app.php já converte para JSON. Uma resposta montada à mão
        // teria que reimplementar as duas portas.
        abort(451, 'Este serviço não está disponível na sua região.');
    }

    /**
     * Uma linha por IP por janela (padrão 1h), não uma por request.
     *
     * Um bot barrado num laço geraria milhares de linhas em `audit_logs` a
     * partir de um endpoint não autenticado — o mesmo vetor de flood que os
     * webhooks em routes/api.php já tratam com throttle generoso. A trilha que
     * importa (quem, de onde, quando começou) sobrevive à deduplicação; o que
     * se perde é a contagem exata de tentativas, que ninguém consome.
     *
     * O IP entra no metadata em HMAC, não em claro. O `audit_logs.ip` da mesma
     * linha já grava o IP cru — é a ressalva conhecida do projeto, registrada em
     * docs/SECURITY_ISSUES.md — mas isso não é razão para gravar de novo.
     */
    private function auditOnce(Request $request, ?string $country): void
    {
        $fingerprint = ClientFingerprint::hash($request->ip());
        $minutes = max(1, (int) config('geo.audit_dedup_minutes'));

        $key = 'geoblock:'.($country ?? 'unknown').':'.substr((string) $fingerprint, 0, 32);

        // add() é atômico: devolve false se a chave já existe, então requests
        // simultâneos do mesmo IP não escrevem duas linhas.
        if (! Cache::add($key, true, now()->addMinutes($minutes))) {
            return;
        }

        Audit::log('access.geo_blocked', null, [
            'country' => $country,
            'path' => $request->path(),
            'ip_hash' => $fingerprint,
        ], $request);
    }
}
