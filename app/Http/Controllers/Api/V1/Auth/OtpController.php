<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\OtpThrottleException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TwoFactorChallenge;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\OtpService;
use App\Services\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

/**
 * Login passwordless por código OTP — porta API (Sanctum).
 *
 * Os MÉTODOS de domínio são os mesmos da porta web (OtpService) — a diferença é
 * só o transporte: aqui, JSON e um token Bearer no fim, em vez de sessão.
 *
 * O 2FA da performer é tratado como no login por senha (Api LoginController):
 * quando ligado, o acerto do OTP NÃO devolve token cheio — devolve um token de
 * desafio com a habilidade `2fa:challenge` e mais nada, e a troca por código na
 * rota `/auth/2fa/challenge` devolve o token real. Sem isso, o OTP seria um
 * caminho lateral que contorna o segundo fator.
 */
class OtpController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private TwoFactorService $twoFactor,
    ) {}

    /**
     * Envia (ou finge enviar) o código. Resposta SEMPRE genérica — existe ou não
     * o e-mail —, exceto o 429 do teto, que é idêntico para os dois casos.
     */
    public function request(RequestOtpRequest $request): JsonResponse
    {
        try {
            $this->otp->requestCode((string) $request->validated('email'));
        } catch (OtpThrottleException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, enviamos um código de acesso.',
        ]);
    }

    /**
     * Verifica o código. No acerto devolve o token real — ou, para performer com
     * 2FA, o token de desafio (ver o docblock da classe).
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->otp->verifyCode(
            (string) $request->validated('email'),
            (string) $request->validated('code'),
        );

        if ($user === null) {
            // Mesma resposta para todo modo de falha — sem oráculo.
            return response()->json(['message' => 'Código inválido ou expirado.'], 422);
        }

        // 2FA ligado: o OTP prova o e-mail, não o segundo fator. Token de desafio
        // com habilidade única, 10 min — igual ao login por senha da API.
        if ($this->twoFactor->isEnabled($user)) {
            $challengeToken = $user->createToken(
                '2fa-challenge',
                [TwoFactorChallenge::CHALLENGE_ABILITY],
                now()->addMinutes(10),
            )->plainTextToken;

            Audit::log('auth.otp_login_2fa_pending', $user);

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challengeToken,
                'message' => 'Informe o código do seu aplicativo autenticador.',
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }
}
