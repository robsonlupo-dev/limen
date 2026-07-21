<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

/**
 * Troca o token de desafio pelo token real, mediante código válido.
 *
 * É o equivalente Sanctum da tela de desafio da porta web. A diferença é onde a
 * prova fica guardada: na web, uma marca na sessão; aqui, no próprio token —
 * o que sai desta rota é um token sem a habilidade `2fa:challenge`, e é essa
 * ausência que o middleware lê.
 *
 * O token de desafio é DESTRUÍDO na troca (acerto ou não sobra credencial de
 * meio-caminho pendurada) e nasce com validade de 10 minutos.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function __invoke(TwoFactorCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Defesa em profundidade: a rota já exige a habilidade, mas um token
        // cheio caindo aqui não deve conseguir reemitir outro token cheio sem
        // apresentar código.
        abort_unless($this->twoFactor->isEnabled($user), 404);

        if (! $this->twoFactor->verify($user, $request->code())) {
            Audit::log('auth.2fa_challenge_failed', $user);

            return response()->json(['message' => 'Código inválido.'], 422);
        }

        // Queima o token de desafio ANTES de emitir o real: ele já cumpriu a
        // função e continuar válido só aumentaria a janela de reuso.
        $user->currentAccessToken()?->delete();

        $token = $user->createToken('api')->plainTextToken;

        Audit::log('auth.2fa_challenge_passed', $user);

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }
}
