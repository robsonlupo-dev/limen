<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\RegisterWebRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(Request $request): Response
    {
        // `tipo` comes from the /entrada role picker. Anything other than
        // "performer" falls back to the member (consumer) flow.
        $tipo = $request->query('tipo') === 'performer' ? 'performer' : 'membro';

        return Inertia::render('Auth/Register', [
            'tipo' => $tipo,
        ]);
    }

    public function store(RegisterWebRequest $request, AuthService $authService)
    {
        $data = array_merge($request->validated(), ['terms_version' => '1.0']);

        if (($data['role'] ?? 'consumer') === 'performer') {
            $user = $authService->registerPerformer($data, $request);

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('performer.onboarding');
        }

        $user = $authService->registerConsumer($data);

        Auth::login($user);
        $request->session()->regenerate();

        // Membro nasce em pending_kyc: o destino do cadastro é o envio da
        // selfie de verificação, não mais o aviso de e-mail. O EnsureMemberVerified
        // devolveria aqui de qualquer forma se ele tentasse pular direto para o painel.
        return redirect()->route('consumer.kyc.index');
    }
}
