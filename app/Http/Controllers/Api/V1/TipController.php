<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTipRequest;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Services\TipService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'message' => 'You cannot tip yourself.',
                'errors' => ['performer_slug' => ['Cannot send a tip to your own profile.']],
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
                'message' => 'Insufficient token balance.',
                'errors' => ['amount' => ['You do not have enough tokens to send this tip.']],
            ], 422);
        }

        $newBalance = $this->tokenService->balance($request->user());

        return response()->json([
            'tip_id' => $tip->id,
            'amount' => $tip->amount,
            'performer_amount' => $tip->performer_amount,
            'platform_amount' => $tip->platform_amount,
            'new_balance' => $newBalance,
        ], 201);
    }

    public function consumerHistory(Request $request): JsonResponse
    {
        $tips = Tip::where('consumer_id', $request->user()->id)
            ->with('performerProfile:id,stage_name,slug')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($tips);
    }

    public function performerHistory(Request $request): JsonResponse
    {
        $profile = $request->user()->performerProfile;

        if (! $profile) {
            return response()->json(['message' => 'Performer profile not found.'], 404);
        }

        $tips = Tip::where('performer_profile_id', $profile->id)
            ->with('consumer:id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($tips);
    }

    public function performerSummary(string $slug): JsonResponse
    {
        $profile = PerformerProfile::where('slug', $slug)->firstOrFail();

        return response()->json([
            'tips_count' => $profile->tips_count,
        ]);
    }
}
