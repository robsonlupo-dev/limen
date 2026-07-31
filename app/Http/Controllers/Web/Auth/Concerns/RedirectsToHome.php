<?php

namespace App\Http\Controllers\Web\Auth\Concerns;

use App\Models\User;

/**
 * Para onde mandar o usuário depois de um login web bem-sucedido.
 *
 * Extraído para as DUAS portas web que estabelecem sessão — login por senha
 * (LoginController) e login por código (OtpLoginController) — terem o MESMO
 * destino. Uma cópia divergente mandaria uma das portas para a rota errada, e o
 * `intended()` de uma delas passaria a diferir da outra sem ninguém decidir isso.
 */
trait RedirectsToHome
{
    protected function homeRouteFor(User $user): string
    {
        if ($user->role !== 'performer') {
            return 'catalog';
        }

        return $user->status === 'active'
            ? 'performer.dashboard'
            : 'performer.onboarding';
    }
}
