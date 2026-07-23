<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\AccountBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TwoFactorChallenge;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private TwoFactorService $twoFactor,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->attemptLogin(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (AccountBlockedException $e) {
            // Credenciais OK, mas moderação barra. 401 (não 403) para manter o
            // contrato do endpoint — o corpo carrega a mensagem específica.
            Audit::log('auth.login_blocked', metadata: [
                'email' => $request->validated('email'),
                'status' => $e->status,
            ]);

            return response()->json([
                'message' => $e->userMessage(),
            ], 401);
        }

        if (! $user) {
            Audit::log('auth.login_failed', metadata: ['email' => $request->validated('email')]);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $user->update(['last_login_at' => now()]);

        // 2FA ligado: a senha sozinha NÃO pode render um token cheio. O modelo
        // de marca-na-sessão da porta web não se traduz para Bearer — não há
        // sessão onde marcar —, então o fator é provado antes de o token
        // existir: sai daqui um token que só serve para POST /auth/2fa/challenge
        // (habilidade única), e a troca por código devolve o token real.
        //
        // Sem isto o gate era contornável inteiro: bastava pedir um token com a
        // senha e usar /api/v1/performer/*, onde moram perfil, KYC e gorjetas.
        if ($this->twoFactor->isEnabled($user)) {
            $challengeToken = $user->createToken(
                '2fa-challenge',
                [TwoFactorChallenge::CHALLENGE_ABILITY],
                now()->addMinutes(10),
            )->plainTextToken;

            Audit::log('auth.login_2fa_pending', $user);

            // Sem UserResource: o perfil só sai depois do segundo fator.
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challengeToken,
                'message' => 'Informe o código do seu aplicativo autenticador.',
            ], 200);
        }

        $token = $user->createToken('api')->plainTextToken;

        Audit::log('auth.login', $user);

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }
}
