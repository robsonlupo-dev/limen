<?php

namespace App\Http\Middleware;

use App\Exceptions\AccountBlockedException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mata a sessão web VIVA de uma conta banida a cada request.
 *
 * O bloqueio em AuthService::attemptLogin cobre o PRÓXIMO login; não a sessão
 * que já estava aberta quando o ban aconteceu. Como `banned` é encerramento
 * permanente por moderação (tipicamente disparado por conteúdo ilegal ou
 * coação), deixar a conta operante por até o lifetime da sessão — navegando,
 * conversando no chat — não é aceitável. O UserBanController já revoga os tokens
 * Sanctum (porta API); isto fecha a porta web.
 *
 * **Só `banned`, deliberadamente.** `suspended` é temporário e tem tratamento
 * próprio (gates 403 por área, cobertos por teste): força-lo a logout mudaria um
 * 403 estabelecido para um 302 e reescreveria contrato existente. Banido é
 * permanente — some do site inteiro.
 */
class BlockBannedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = (new AccountBlockedException('banned'))->userMessage();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
