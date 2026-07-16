<?php

namespace App\Http\Middleware;

use App\Models\Circle;
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

        if ($minTier !== null) {
            $required = array_search($minTier, Circle::TIER_ORDER, true);

            if ($required === false || $circle->tierRank() < $required) {
                abort(403, 'Seu Círculo não dá acesso a este recurso.');
            }
        }

        return $next($request);
    }
}
