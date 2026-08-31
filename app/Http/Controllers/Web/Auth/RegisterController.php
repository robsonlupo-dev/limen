<?php

namespace App\Http\Controllers\Web\Auth;

use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\RegisterWebRequest;
use App\Services\AuthService;
use App\Services\MemberAvatarService;
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

    public function store(RegisterWebRequest $request, AuthService $authService, MemberAvatarService $avatars)
    {
        $data = array_merge($request->validated(), ['terms_version' => '1.0']);

        if (($data['role'] ?? 'consumer') === 'performer') {
            $user = $authService->registerPerformer($data, $request);

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('performer.onboarding');
        }

        $user = $authService->registerConsumer($data);

        // Foto de perfil OPCIONAL do cadastro (fix/member-photo-and-crop). O membro
        // já existe aqui, então a foto passa pelo MESMO pipeline (sanitização +
        // anti-CSAM) do perfil. A foto é ACESSÓRIA ao cadastro: uma imagem
        // corrompida ou que bata no anti-CSAM NÃO derruba o registro (500) nem
        // deixa uma conta órfã sem login — o membro entra sem foto e completa
        // depois. O conteúdo ilegal segue sem gravar, e o service já sinalizou a
        // conta (csam_flagged_at) para a moderação agir. Pular (sem arquivo) é o
        // caminho normal.
        if ($request->hasFile('avatar')) {
            try {
                $avatars->replace($user, $request->file('avatar'), $request);
            } catch (ImageProcessingException|CsamDetectedException $e) {
                // Segue o cadastro sem foto; a conta fica sinalizada se foi CSAM.
            }
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Membro nasce em pending_kyc: o destino do cadastro é o envio da
        // selfie de verificação, não mais o aviso de e-mail. O EnsureMemberVerified
        // devolveria aqui de qualquer forma se ele tentasse pular direto para o painel.
        return redirect()->route('consumer.kyc.index');
    }
}
