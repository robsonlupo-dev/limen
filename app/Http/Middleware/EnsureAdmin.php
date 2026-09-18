<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porta admin-only (`/admin/*`, API `role:admin`): dinheiro, tier, KYC, waitlist,
 * ban, config e gestão de papéis. SÓ `admin` passa — o moderador (Trust & Safety)
 * NÃO alcança nada com dinheiro ou identidade civil.
 *
 * Par simétrico do EnsureModeratorOrAdmin: aquele abre a fila de moderação
 * (admin ⊇ moderator); este fecha o resto. A decisão vive num ponto único —
 * `User::isAdmin()`, sobre o enum App\Enums\Role — então "quem é admin" muda no
 * helper, não em `role:admin` espalhado pela definição de rotas. É um alias
 * nomeado (`admin.access`) por isso: dá um dono à regra, como o `moderator.access`.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
