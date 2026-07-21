<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra a performer com 2FA ativo que ainda não apresentou o segundo fator.
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
 * ─── As DUAS portas de auth (ver CLAUDE.md) ──────────────────────────────────
 *
 * Vale para a API Sanctum também, e a prova do fator é diferente em cada porta:
 *
 * - **Sessão (web)**: a marca fica na sessão. É a porta que o frontend usa.
 * - **Bearer (API)**: não há sessão onde marcar. O fator é provado ANTES de o
 *   token existir — o login da API emite um token de desafio com a habilidade
 *   `2fa:challenge` e mais nada, e só a troca por código devolve o token cheio.
 *   Aqui o middleware só precisa recusar o token de desafio.
 *
 * Sem este segundo caso o gate era contornável inteiro: bastava pedir um token
 * em `POST /api/v1/auth/login` com a senha e usar `/api/v1/performer/*`, onde
 * moram perfil, KYC e gorjetas. A mesma lição que `documents.accepted` já tinha
 * aprendido neste projeto — gate que fecha uma porta só não é gate.
 */
class TwoFactorChallenge
{
    /** Habilidade do token que só serve para resolver o desafio. */
    public const CHALLENGE_ABILITY = '2fa:challenge';

    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'performer' || ! $this->twoFactor->isEnabled($user)) {
            return $next($request);
        }

        if ($this->hasPresentedFactor($request, $user)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Verificação em duas etapas obrigatória.');
        }

        return redirect()->route('performer.2fa.challenge');
    }

    private function hasPresentedFactor(Request $request, $user): bool
    {
        $token = $user->currentAccessToken();

        // Bearer: o token cheio já nasceu depois do fator. O de desafio não
        // abre nada além da própria troca.
        //
        // in_array na lista literal, e NÃO $token->can(): o `can()` do Sanctum
        // responde true para qualquer habilidade quando o token tem `*` — que é
        // o caso do token cheio. Usá-lo aqui barrava exatamente o token que
        // acabou de passar pelo desafio, e deixava passar só o que não deveria.
        if ($token instanceof PersonalAccessToken) {
            return ! in_array(self::CHALLENGE_ABILITY, $token->abilities ?? [], true);
        }

        // Sessão. A checagem (inclusive o hasSession, obrigatório porque numa
        // rota `api/*` não roda StartSession e chamar session() ali lançaria)
        // vive no service — a marca tem uma dona só.
        return $this->twoFactor->sessionHasFactor($request, $user);
    }
}
