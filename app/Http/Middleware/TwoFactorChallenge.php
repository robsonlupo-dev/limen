<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra a performer com 2FA ativo cuja sessão ainda não apresentou o segundo
 * fator.
 *
 * Só age sobre performer com 2FA CONFIRMADO: membro, admin e performer sem 2FA
 * passam direto. Mesma forma do DocumentsAccepted, e pela mesma razão — assim o
 * middleware pode ser aplicado no grupo `auth` inteiro, inclusive nas rotas
 * compartilhadas (chat), sem afetar quem não é performer.
 *
 * Aplicado no grupo inteiro e não só em `performer.*` de propósito: a sessão
 * autenticada da performer chega ao chat e ao catálogo também, e gatear só o
 * dashboard deixaria a conta sequestrada conversando com membros — que é
 * justamente a superfície de impersonation que o fator existe para fechar.
 *
 * FORA do gate ficam só as rotas do próprio desafio (senão o redirect aponta
 * para uma rota que ele mesmo bloqueia — loop infinito) e o logout, que vive
 * fora deste grupo: quem perdeu o autenticador precisa conseguir sair.
 *
 * Resposta por porta de auth (ver CLAUDE.md): redirect no caminho normal —
 * inclusive Inertia, que segue redirect no cliente — e 403 JSON quando o
 * chamador espera JSON, que seguindo o redirect receberia HTML e quebraria.
 */
class TwoFactorChallenge
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'performer' || ! $this->twoFactor->isEnabled($user)) {
            return $next($request);
        }

        if ($request->session()->get(TwoFactorService::SESSION_KEY) === true) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Verificação em duas etapas obrigatória.');
        }

        return redirect()->route('performer.2fa.challenge');
    }
}
