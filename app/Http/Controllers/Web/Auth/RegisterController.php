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
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(Request $request): Response
    {
        // `tipo` comes from the /entrada role picker. Anything other than
        // "performer" falls back to the member (consumer) flow.
        $tipo = $request->query('tipo') === 'performer' ? 'performer' : 'membro';

        // Programa de indicação: captura o código do link (?ref=CODE), preenche o
        // campo e o guarda num cookie curto (30 dias) para sobreviver à navegação
        // até o cadastro concluir. Sanitiza para o formato do código (nunca ecoa
        // input cru no atributo).
        $ref = $this->sanitizeRef($request->query('ref'));

        if ($ref) {
            Cookie::queue('referral_ref', $ref, 60 * 24 * 30);
        }

        return Inertia::render('Auth/Register', [
            'tipo' => $tipo,
            'ref' => $ref ?: (string) $request->cookie('referral_ref', ''),
        ]);
    }

    /** Mantém só o formato de código de indicação (A–Z, 0–9, hífen); null se vazio. */
    private function sanitizeRef(mixed $ref): ?string
    {
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $ref) ?? '');

        return $clean !== '' ? substr($clean, 0, 32) : null;
    }

    public function store(RegisterWebRequest $request, AuthService $authService, MemberAvatarService $avatars)
    {
        $data = array_merge($request->validated(), ['terms_version' => '1.0']);

        // Programa de indicação: o código digitado no formulário tem prioridade
        // ('code'); se vazio, cai no cookie do link ('link'). Um código inválido
        // não é erro de cadastro — o ReferralService simplesmente não cria vínculo.
        $typed = $this->sanitizeRef($request->input('codigo_indicacao'));
        $fromCookie = $this->sanitizeRef($request->cookie('referral_ref'));
        $data['referral_code'] = $typed ?? $fromCookie;
        $data['referred_via'] = $typed ? 'code' : 'link';

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

        // Apelido OPCIONAL (feat/member-nickname). Já validado no RegisterWebRequest;
        // aqui só grava. Uma corrida rara (outro membro pegou o apelido no intervalo)
        // não derruba o cadastro — o membro escolhe depois no perfil.
        if (filled($request->input('nickname'))) {
            try {
                app(\App\Services\MemberNicknameService::class)->set($user, (string) $request->input('nickname'), $request);
            } catch (\App\Exceptions\NicknameException $e) {
                // Segue sem apelido; ele completa no perfil.
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
