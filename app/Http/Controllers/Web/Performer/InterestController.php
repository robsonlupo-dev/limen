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
        $profile = $request->user()->performerProfile;

        // Antes de resolver o alvo: resolvedMember() filtra pelos seguidores
        // DESTE perfil, e sem perfil isso viraria um 404 enganoso.
        if (! $profile) {
            return response()->json([
                'reason' => 'no_profile',
                'message' => 'Complete seu perfil de performer antes de demonstrar interesse.',
            ], 422);
        }

        $member = $request->resolvedMember();

        try {
            $this->interestService->send($profile, $member);
        } catch (InterestException $e) {
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Corpo idêntico em todos os casos de sucesso — inclusive quando o
        // membro optou por sair, caso em que send() grava a linha como
        // 'suppressed' — para não vazar o comportamento/opt-out do membro.
        return response()->json(['sent' => true], 201);
    }
}
