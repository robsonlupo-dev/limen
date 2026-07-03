<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTipRequest;
use App\Services\TipService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;

class TipController extends Controller
{
    public function __construct(
        private TipService $tipService,
        private TokenService $tokenService,
    ) {}

    public function store(SendTipRequest $request): JsonResponse
    {
        $performer = $request->resolvedPerformer();

        if ($performer->user_id === $request->user()->id) {
            return response()->json([
                'reason' => 'self_tip',
                'message' => 'Você não pode enviar gorjeta para o seu próprio perfil.',
            ], 422);
        }

        try {
            $tip = $this->tipService->send(
                $request->user(),
                $performer,
                $request->validated('amount'),
                $request->validated('idempotency_key'),
                $request->validated('message'),
            );
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente. Compre mais tokens para enviar a gorjeta.',
            ], 422);
        }

        return response()->json([
            'tip_id' => $tip->id,
            'amount' => $tip->amount,
            'performer_amount' => $tip->performer_amount,
            'new_balance' => $this->tokenService->balance($request->user()),
            'tips_count' => $performer->fresh()->tips_count,
        ], 201);
    }
}
