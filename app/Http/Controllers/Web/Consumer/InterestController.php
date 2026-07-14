<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\PerformerInterest;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestController extends Controller
{
    public function __construct(
        private InterestService $interestService,
        private TokenService $tokenService,
    ) {}

    /**
     * Caixa de interesses do membro. Interesses bloqueados são anônimos (nunca
     * revelam a performer); os desbloqueados trazem a identidade.
     */
    public function index(Request $request): JsonResponse
    {
        $interests = PerformerInterest::where('member_id', $request->user()->id)
            ->with('performerProfile:id,stage_name,slug,avatar_path')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (PerformerInterest $interest) => [
                'id' => $interest->id,
                'status' => $interest->status,
                'sent_at' => $interest->sent_at,
                'unlocked_at' => $interest->unlocked_at,
                // Identidade só quando desbloqueado — nunca vaza no estado 'sent'.
                'performer' => $interest->isUnlocked() ? [
                    'stage_name' => $interest->performerProfile->stage_name,
                    'slug' => $interest->performerProfile->slug,
                    'avatar_path' => $interest->performerProfile->avatar_path,
                ] : null,
            ]);

        return response()->json([
            'unlock_cost' => (int) config('interest.unlock_cost'),
            'interests' => $interests,
        ]);
    }

    public function unlock(Request $request, PerformerInterest $interest): JsonResponse
    {
        // Só o dono do interesse pode desbloquear. 404 (não 403) para não
        // revelar a existência do interesse a terceiros.
        if ($interest->member_id !== $request->user()->id) {
            abort(404);
        }

        try {
            $interest = $this->interestService->unlock($request->user(), $interest);
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente. Compre mais tokens para desbloquear.',
            ], 422);
        }

        $interest->loadMissing('performerProfile:id,stage_name,slug,avatar_path');

        return response()->json([
            'id' => $interest->id,
            'status' => $interest->status,
            'performer' => [
                'stage_name' => $interest->performerProfile->stage_name,
                'slug' => $interest->performerProfile->slug,
                'avatar_path' => $interest->performerProfile->avatar_path,
            ],
            'new_balance' => $this->tokenService->balance($request->user()),
        ]);
    }

    public function optOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opt_out' => ['required', 'boolean'],
        ]);

        $this->interestService->setOptOut($request->user(), $validated['opt_out']);

        return response()->json(['interests_opt_out' => $validated['opt_out']]);
    }
}
