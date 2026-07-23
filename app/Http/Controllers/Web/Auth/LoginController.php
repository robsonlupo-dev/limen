<?php

namespace App\Http\Controllers\Web\Auth;

use App\Exceptions\AccountBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, AuthService $authService)
    {
        try {
            $user = $authService->attemptLogin(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (AccountBlockedException $e) {
            // Conta suspensa/banida: mensagem específica, no mesmo campo `email`
            // (o teste de bloqueio verifica erro nesse campo). Só chega aqui com
            // a senha correta — ver AccountBlockedException. Auditado como na
            // porta API, para a tentativa deixar trilha nos dois lados.
            Audit::log('auth.login_blocked', metadata: [
                'email' => $request->validated('email'),
                'status' => $e->status,
            ]);

            throw ValidationException::withMessages([
                'email' => [$e->userMessage()],
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas. Verifique seu e-mail e senha.'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // regenerate() TROCA o id da sessão mas PRESERVA os dados dela. Sem este
        // forget, uma marca de 2FA sobrevivente da sessão anterior faria a
        // sessão nova nascer já verificada — e o desafio nunca apareceria.
        // A marca é da sessão, não da conta: cada login prova o fator de novo.
        $request->session()->forget(TwoFactorService::SESSION_KEY);

        return redirect()->intended(route($this->homeRouteFor($user)));
    }

    private function homeRouteFor(User $user): string
    {
        if ($user->role !== 'performer') {
            return 'catalog';
        }

        return $user->status === 'active'
            ? 'performer.dashboard'
            : 'performer.onboarding';
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
