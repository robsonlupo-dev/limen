<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\CallException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StartGroupShowRequest;
use App\Models\CallSession;
use App\Services\GroupShowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group show 1:X — lado da PERFORMER (Sprint 15, PR #141). Rotas WEB → JSON
 * explícito. Start/stop resolvem "o group dela" pelo perfil (sem id na URL);
 * upgrade-accept/decline recebem `{group}` = id da sessão e o service reconfere
 * `performer_profile_id == ator` (fecha IDOR de aceitar upgrade de outro group).
 */
class GroupShowController extends Controller
{
    public function __construct(private GroupShowService $group) {}

    /** Inicia o group show → JWT publisher da performer. */
    public function start(StartGroupShowRequest $request): JsonResponse
    {
        try {
            $bundle = $this->group->start(
                $request->user(),
                $request->pricePerMinute(),
                $request->maxParticipants(),
            );
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle);
    }

    /** Encerra o group show (todos desconectados). */
    public function stop(Request $request): JsonResponse
    {
        $this->group->stop($request->user());

        return response()->json(['ok' => true]);
    }

    /** Aceita o upgrade para 1:1 (os outros saem em 10s). */
    public function upgradeAccept(Request $request, CallSession $group): JsonResponse
    {
        try {
            $this->group->upgradeAccept($request->user(), $group);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Recusa o upgrade (grupo continua). */
    public function upgradeDecline(Request $request, CallSession $group): JsonResponse
    {
        try {
            $this->group->upgradeDecline($request->user(), $group);
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
