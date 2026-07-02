<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
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
        $user = $authService->attemptLogin(
            $request->validated('email'),
            $request->validated('password'),
        );

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas. Verifique seu e-mail e senha.'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route($this->homeRouteFor($user)));
    }

    private function homeRouteFor(\App\Models\User $user): string
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
