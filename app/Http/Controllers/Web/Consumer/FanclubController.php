<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\FanclubException;
use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use App\Services\FanclubService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fã-Clube — lado do MEMBRO (Onda 4, fork da assinatura). Assinar, desvincular e listar
 * as próprias assinaturas. A regra e o dinheiro (débito/crédito 80/20, ciclo) vivem no
 * FanclubService; aqui é só a porta (rotas web não viram JSON sozinhas). Binding por SLUG
 * (o identificador público; o resource não devolve o id numérico do perfil).
 */
class FanclubController extends Controller
{
    public function __construct(
        private FanclubService $fanclub,
        private TokenService $tokenService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Consumer/Fanclub/Index', [
            'subscriptions' => $this->fanclub->mySubscriptions($request->user()),
            'balance' => $this->tokenService->balance($request->user()),
        ]);
    }

    public function subscribe(Request $request, PerformerProfile $profile): JsonResponse
    {
        try {
            $membership = $this->fanclub->subscribe($request->user(), $profile);
        } catch (FanclubException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'status' => $membership->status,
            'renews_at' => $membership->current_period_end?->toIso8601String(),
            'balance' => $this->tokenService->balance($request->user()),
        ], 201);
    }

    public function cancel(Request $request, PerformerProfile $profile): JsonResponse
    {
        $membership = $this->fanclub->cancel($request->user(), $profile);

        return response()->json([
            // Desvinculada mantém acesso até o fim do ciclo pago; o cron encerra na virada.
            'status' => $membership ? 'cancel_requested' : 'none',
        ], 200);
    }
}
