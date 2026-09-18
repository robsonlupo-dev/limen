<?php

namespace App\Http\Controllers\Web\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    /**
     * A resposta de redirect pós-login, já ciente de DUAS armadilhas do fluxo:
     *
     * 1. O painel admin (`/admin/*`) é uma área BLADE, servida FORA do Inertia.
     *    O login é submetido pelo cliente Inertia (`form.post`), então um
     *    `redirect()` 302 comum para lá é seguido por XHR — e a resposta HTML,
     *    sem o cabeçalho `X-Inertia`, cai no MODAL de erro do Inertia: o painel
     *    aparece SOBRE o `/login`, a URL não muda e o `<title>` continua
     *    "Entrar". Só uma navegação de PÁGINA INTEIRA carrega o Blade de verdade
     *    — é o que `Inertia::location()` faz (409 + `X-Inertia-Location` para o
     *    cliente Inertia; 302 normal fora dele, então testes sem o header
     *    seguem vendo um redirect).
     *
     * 2. `redirect()->intended()` obedece um `url.intended` guardado na sessão
     *    pelo middleware `auth` quando um convidado tocou uma página protegida.
     *    Se essa página for do admin (o navegador do moderador abriu `/admin/*`
     *    deslogado, por bookmark ou link), o `intended` SOBREPÕE a home de papel
     *    e joga um NÃO-admin contra `admin.access` → 403. Foi a raiz do 403 do
     *    moderador. Um não-admin nunca é mandado para `/admin/*`: o destino de
     *    PAPEL é o piso seguro.
     */
    protected function redirectAfterLogin(User $user, Request $request): SymfonyResponse
    {
        $home = route($this->homeRouteFor($user));
        $intended = $request->session()->pull('url.intended', $home);

        // Só o admin alcança /admin/* — e essa área é Blade, então SEMPRE por
        // navegação de página inteira (senão cai no modal do Inertia).
        if ($user->isAdmin()) {
            return Inertia::location($intended);
        }

        // Não-admin com `intended` apontando para /admin/* seria 403 (e modal):
        // ignora e usa a home de papel.
        if ($this->pointsToAdminArea($intended)) {
            $intended = $home;
        }

        return redirect()->to($intended);
    }

    /** O `url.intended`/destino cai na área admin-only (Blade, `admin.access`)? */
    private function pointsToAdminArea(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

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
