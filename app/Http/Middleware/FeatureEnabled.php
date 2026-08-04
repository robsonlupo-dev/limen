<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de feature flag para dark launch. `->middleware('feature:live')` exige
 * `config('features.live_enabled')` true; idem `feature:call`. 403 se desligada.
 *
 * É a porta HTTP amigável; o backstop de verdade é a checagem interna no
 * LiveKitService (defesa em profundidade — ver config/features.php).
 */
class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config("features.{$feature}_enabled", false)) {
            abort(403, 'Recurso indisponível.');
        }

        return $next($request);
    }
}
