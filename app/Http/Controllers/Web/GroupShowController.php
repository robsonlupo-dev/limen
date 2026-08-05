<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\CallException;
use App\Http\Controllers\Controller;
use App\Models\CallSession;
use App\Services\GroupShowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group show 1:X — lado do MEMBRO (Sprint 15, PR #141). Rotas WEB consumidas por
 * fetch → JSON explícito. `{group}` = `call_sessions.id` (NÃO o room_name, que
 * nunca sai em URL/log/resposta — invariante do #138). A autorização de
 * PARTICIPANTE é do GroupShowService (resolve a participação do próprio ator sob
 * lock; 404 uniforme para participação de terceiro).
 */
class GroupShowController extends Controller
{
    public function __construct(private GroupShowService $group) {}

    /** Entra no grupo → JWT viewer (subscribe). */
    public function join(Request $request, CallSession $group): JsonResponse
    {
        try {
            $bundle = $this->group->join($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle + ['group_id' => $group->id]);
    }

    /** Batimento: cobra o próprio minuto, devolve o estado de saldo. */
    public function heartbeat(Request $request, CallSession $group): JsonResponse
    {
        try {
            $state = $this->group->heartbeat($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($state);
    }

    /** Renovação do JWT do membro. */
    public function tokenRefresh(Request $request, CallSession $group): JsonResponse
    {
        try {
            $bundle = $this->group->refreshToken($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle);
    }

    /** Saída voluntária. */
    public function leave(Request $request, CallSession $group): JsonResponse
    {
        try {
            $this->group->leave($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Pede upgrade para 1:1. */
    public function upgradeRequest(Request $request, CallSession $group): JsonResponse
    {
        try {
            $this->group->upgradeRequest($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    private function fail(CallException $e): JsonResponse
    {
        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }
}
