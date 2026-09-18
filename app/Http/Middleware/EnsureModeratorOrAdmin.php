<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porta da área de moderação (`/moderacao/*`).
 *
 * Deixa passar `moderator` E `admin`: o moderador é o revisor dedicado da fila
 * de denúncias, e o admin — que vê tudo — não perde acesso a nada com o refactor
 * de roles do Sprint 13. Quem NÃO for um dos dois leva 403.
 *
 * A regra "quem pode moderar" tem dona única em `User::canModerate()`
 * (admin ⊇ moderator, sobre o enum App\Enums\Role) — o middleware só a aplica,
 * não repete a lista de papéis. Mudar a regra (ex.: um terceiro role de
 * curadoria) é mudar o helper, não caçar `in_array(..., ['moderator','admin'])`
 * espalhado. Mesma disciplina de fonte única do EnsureMemberVerified.
 *
 * O que este gate deliberadamente NÃO concede: os poderes de admin. A área
 * `/admin/*` fica sob `admin.access` (EnsureAdmin → `isAdmin()`) — KYC, payout,
 * ban, tier e config continuam fora do alcance do moderador. Este middleware
 * abre a fila de denúncias e nada mais.
 */
class EnsureModeratorOrAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->canModerate()) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
