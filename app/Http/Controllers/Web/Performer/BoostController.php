<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\BoostException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Services\BoostService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Boost pago (Sprint 11) — a performer gasta tokens para destacar o perfil no
 * topo do catálogo. Um endpoint só, POST, consumido pelo `postJson` do
 * dashboard. Toda a regra vive no BoostService; aqui só se traduz sucesso/erro
 * para JSON.
 *
 * A resposta de sucesso devolve o estado DERIVADO (is_boosted + faixa de tempo)
 * e o saldo novo, para o painel refletir sem recarregar. Nunca o carimbo
 * `boosted_until`.
 */
class BoostController extends Controller
{
    public function __construct(
        private BoostService $boost,
        private TokenService $tokens,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->performerProfile;

        // Performer sem perfil (onboarding nunca concluído): não há o que
        // destacar. 404, como o toggle de disponibilidade — o dashboard não
        // oferece o botão nesse estado.
        abort_unless($profile !== null, 404);

        try {
            $this->boost->boost($profile, $user);
        } catch (BoostException $e) {
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 422);
        } catch (InsufficientBalanceException) {
            // Corpo estável (reason) — o front oferece o link de comprar tokens.
            // Não devolve o saldo aqui de propósito: a mensagem de domínio basta,
            // e o dashboard já conhece o saldo atual.
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => "Você precisa de {$this->boost->cost()} tokens para destacar seu perfil.",
                'cost' => $this->boost->cost(),
            ], 422);
        }

        return response()->json([
            'boosted' => true,
            'is_boosted' => $profile->isBoosted(),
            // Faixa, nunca relógio (ver PerformerProfile::boostRemainingLabel).
            'remaining_label' => $profile->boostRemainingLabel(),
            'wallet' => $this->tokens->balance($user),
            'available_slots' => $this->boost->availableSlots(),
        ]);
    }
}
