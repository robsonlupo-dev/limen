<?php

namespace App\Http\Middleware;

use App\Exceptions\AccountBlockedException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mata a sessão web VIVA de uma conta suspensa a cada request (par do
 * BlockBannedUsers, feat/moderator-actions).
 *
 * O bloqueio em AuthService/OtpService cobre o PRÓXIMO login; não a sessão que já
 * estava aberta quando a suspensão aconteceu. A suspensão (temporária) existe
 * para PARAR dano vivo — chat abusivo/coercitivo é o caso típico — e o membro/
 * performer suspenso não tem gate 403 na superfície principal (catálogo, chat,
 * compra de tokens); sem isto ele seguiria operando até o cookie de sessão
 * expirar. O ModeratorActionService já revoga os tokens Sanctum (porta API);
 * isto fecha a porta web, exatamente como o BlockBannedUsers faz para `banned`.
 *
 * Suspensão TEMPORIZADA: se o prazo já passou, reativa a conta e deixa seguir —
 * mesma fonte única das outras portas (User::liftSuspensionIfExpired). Assim uma
 * sessão que atravessa o fim do prazo volta a funcionar sozinha, sem forçar novo
 * login. `suspended_until` NULL (indefinida) ou no futuro derruba a sessão.
 *
 * `banned` não é tratado aqui — o BlockBannedUsers roda ANTES e já derruba (e a
 * precedência ban > suspended vale em toda porta). No-op para guest e para
 * qualquer status que não seja `suspended`.
 */
class BlockSuspendedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            // Prazo cumprido → reativa e segue como conta normal.
            if ($user->liftSuspensionIfExpired()) {
                return $next($request);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = (new AccountBlockedException('suspended'))->userMessage();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
