<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\InterestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendInterestRequest;
use App\Services\InterestService;
use Illuminate\Http\JsonResponse;

class InterestController extends Controller
{
    public function __construct(private InterestService $interestService) {}

    public function store(SendInterestRequest $request): JsonResponse
    {
        $member = $request->resolvedMember();
        $profile = $request->user()->performerProfile;

        if (! $profile) {
            return response()->json([
                'reason' => 'no_profile',
                'message' => 'Complete seu perfil de performer antes de demonstrar interesse.',
            ], 422);
        }

        try {
            $this->interestService->send($profile, $member);
        } catch (InterestException $e) {
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Corpo idêntico em todos os casos de sucesso — inclusive quando o
        // membro optou por sair (send() retorna null e nada é criado) — para
        // não vazar o comportamento/opt-out do membro à performer.
        return response()->json(['sent' => true], 201);
    }
}
