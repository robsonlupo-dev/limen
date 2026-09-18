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
        // Staff vai para o back-office — o `catalog` exige role de consumer e
        // devolveria 403. Checado ANTES do fallback de consumer. Admin abre o
        // painel administrativo; o moderador NÃO tem `admin.access` (só admin),
        // então vai direto para a fila de moderação (`admin.dashboard` lhe daria
        // 403).
        if ($user->isAdmin()) {
            return 'admin.dashboard';
        }

        if ($user->isModerator()) {
            return 'moderacao.reports.index';
        }

        if ($user->role !== 'performer') {
            return 'catalog';
        }

        // A home da performer ativa é o CATÁLOGO DE MEMBROS (a vitrine onde ela
        // navega e engaja), simétrico ao catálogo que o membro vê ao logar. O
        // painel (`performer.dashboard`) segue acessível pela nav. Performer ainda
        // em KYC vai para o onboarding, como antes.
        return $user->status === 'active'
            ? 'performer.members'
            : 'performer.onboarding';
    }
}
