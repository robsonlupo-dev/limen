<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Resolve o país de origem de uma requisição.
 *
 * Existe separado do middleware porque a FONTE do dado vai mudar (hoje nenhuma,
 * amanhã Cloudflare ou MaxMind) e a REGRA de bloqueio não. Trocar de driver não
 * pode virar uma edição no meio da lógica de bloqueio.
 *
 * Devolve sempre ISO 3166-1 alfa-2 maiúsculo, ou null para "não sei" — e "não
 * sei" é um estado de primeira classe aqui, não um erro: é o estado normal
 * enquanto GEO_DRIVER=none. Quem decide o que fazer com o null é o GeoBlock
 * (config `block_unknown`), não este service.
 */
class GeoLocationService
{
    /** Header que o Cloudflare injeta com o país resolvido por ele. */
    public const CLOUDFLARE_HEADER = 'CF-IPCountry';

    /** O Cloudflare usa 'XX' para "não foi possível determinar". */
    private const CLOUDFLARE_UNKNOWN = ['XX', 'T1'];

    public function countryFor(Request $request): ?string
    {
        return match ((string) config('geo.driver')) {
            'cloudflare' => $this->fromCloudflare($request),
            // 'none' e qualquer valor desconhecido caem aqui. Driver escrito
            // errado no .env NÃO pode virar bloqueio silencioso de todo mundo
            // (nem passe livre disfarçado de configuração): vira "não sei", que
            // é o estado que o operador já sabe interpretar.
            default => null,
        };
    }

    /** O país está na lista de barrados? */
    public function isBlocked(?string $country): bool
    {
        if ($country === null) {
            return (bool) config('geo.block_unknown');
        }

        return in_array($country, (array) config('geo.blocked_countries'), true);
    }

    /**
     * País pelo header do Cloudflare.
     *
     * A confiança neste valor NÃO é decidida aqui: se a requisição chegou ao
     * origin sem passar pelo Cloudflare, o header é o que o cliente quis
     * escrever. Quem tem que garantir a procedência é o nginx, aceitando só os
     * ranges do CF — está registrado em config/geo.php e em docs/GEOBLOCKING.md.
     * O PHP não tem como distinguir e fingir que tem seria pior.
     *
     * O que dá para fazer aqui é não propagar lixo: só duas letras passam, o
     * resto vira "não sei". Sem isso, um header de 4 KB entraria no metadata do
     * audit_log.
     */
    private function fromCloudflare(Request $request): ?string
    {
        $raw = strtoupper(trim((string) $request->header(self::CLOUDFLARE_HEADER)));

        if (! preg_match('/^[A-Z]{2}$/', $raw) || in_array($raw, self::CLOUDFLARE_UNKNOWN, true)) {
            return null;
        }

        return $raw;
    }
}
