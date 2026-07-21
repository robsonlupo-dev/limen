<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind an active Círculo. `->middleware('circle')` requires any
 * active subscription; `->middleware('circle:prestige')` requires Prestige OR
 * HIGHER (tier order in Circle::TIER_ORDER).
 */
class EnsureActiveCircle
{
    public function handle(Request $request, Closure $next, ?string $minTier = null): Response
    {
        $user = $request->user();
        $circle = $user?->activeCircle();

        if (! $circle) {
            abort(403, 'Círculo ativo necessário.');
        }

        // Comparação em Circle::tierAtLeast, que já é fail-closed nas duas pontas
        // (tier do usuário ou tier mínimo fora do TIER_ORDER ⇒ nega).
        if ($minTier !== null && ! $circle->tierAtLeast($minTier)) {
            abort(403, 'Seu Círculo não dá acesso a este recurso.');
        }

        return $next($request);
    }
}
