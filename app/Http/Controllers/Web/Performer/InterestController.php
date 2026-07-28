<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\InterestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendInterestRequest;
use App\Http\Requests\SendInterestToVisitorRequest;
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
            $this->interestService->send($profile, $member, InterestService::SOURCE_FOLLOWER);
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

    /**
     * Interesse a partir do PAINEL DE VISITANTES (Sprint 9).
     *
     * Endpoint próprio, e não um `source` no payload do store() acima: a origem
     * escolhe contra qual conjunto o handle é resolvido, e deixar essa escolha
     * num campo do request permitiria pedir "visitante" e cair no predicado de
     * seguidores (ou vice-versa). Duas rotas, dois Form Requests, cada um com
     * a sua regra — ver SendInterestToVisitorRequest.
     *
     * A resposta é byte a byte igual à do store(): mesmo 201, mesmo corpo,
     * mesmos 422 por cooldown/cota. Para o MEMBRO nada muda em lugar nenhum —
     * a notificação é a mesma cega e a revelação custa os mesmos 15 tokens.
     */
    public function storeForVisitor(SendInterestToVisitorRequest $request): JsonResponse
    {
        $profile = $request->user()->performerProfile;

        if (! $profile) {
            return response()->json([
                'reason' => 'no_profile',
                'message' => 'Complete seu perfil de performer antes de demonstrar interesse.',
            ], 422);
        }

        $member = $request->resolvedMember();

        try {
            $this->interestService->send($profile, $member, InterestService::SOURCE_VISITOR);
        } catch (InterestException $e) {
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['sent' => true], 201);
    }
}
