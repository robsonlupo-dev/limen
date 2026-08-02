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
 * Poderia ser `role:moderator,admin` (o EnsureUserHasRole é variádico), mas um
 * middleware nomeado dá um ponto único para "quem pode moderar": a próxima rota
 * de moderação referencia UMA coisa, e mudar a regra (ex.: um terceiro role de
 * curadoria) é mudar aqui, não caçar `role:moderator,admin` espalhado. Mesma
 * disciplina de fonte única do EnsureMemberVerified e do EnsureActiveCircle.
 *
 * O que este gate deliberadamente NÃO concede: os poderes de admin. A área
 * `/admin/*` segue sob `role:admin` — KYC, payout, ban e tier continuam fora do
 * alcance do moderador. Este middleware abre a fila de denúncias e nada mais.
 */
class EnsureModeratorOrAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! in_array($request->user()->role, ['moderator', 'admin'], true)) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
