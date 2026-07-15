<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\PerformerInterest;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

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
    public function index(Request $request): Response
    {
        $interests = PerformerInterest::where('member_id', $request->user()->id)
            // Suprimidos (opt-out) não existem para o membro.
            ->visibleToMember()
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
                'performer' => $interest->isUnlocked()
                    ? $this->performerIdentity($request, $interest)
                    : null,
            ]);

        return Inertia::render('Consumer/Interests/Index', [
            'unlockCost' => (int) config('interest.unlock_cost'),
            'balance' => $this->tokenService->balance($request->user()),
            'optOut' => (bool) $request->user()->interests_opt_out,
            'interests' => $interests,
        ]);
    }

    public function unlock(Request $request, PerformerInterest $interest): JsonResponse
    {
        // Só o dono do interesse pode desbloquear, e um interesse suprimido é
        // invisível para ele. 404 (não 403) para não revelar a existência do
        // interesse a terceiros — nem o opt-out ao próprio membro.
        if ($interest->member_id !== $request->user()->id || $interest->isSuppressed()) {
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
            'performer' => $this->performerIdentity($request, $interest),
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

    /**
     * Só o necessário para exibir quem enviou. O avatar vai por rota assinada e
     * expirável, chaveada pelo id do perfil (nunca pelo user_id) — mesma regra
     * do catálogo, ver PerformerPublicResource.
     *
     * @return array<string, mixed>
     */
    private function performerIdentity(Request $request, PerformerInterest $interest): array
    {
        $profile = $interest->performerProfile;

        return [
            'stage_name' => $profile->stage_name,
            'slug' => $profile->slug,
            'avatar_url' => $profile->avatar_path
                ? URL::temporarySignedRoute(
                    'performer.media',
                    now()->addMinutes(60),
                    ['profile_id' => $profile->id, 'type' => 'avatar'],
                )
                : null,
        ];
    }
}
