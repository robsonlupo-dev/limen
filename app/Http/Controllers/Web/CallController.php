<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\CallException;
use App\Http\Controllers\Controller;
use App\Models\CallSession;
use App\Models\PerformerProfile;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chamada privada 1:1 (Sprint 15, PR #140). Rotas WEB consumidas por fetch → JSON
 * explícito (fora de api/*, a exceção não vira JSON sozinha). Toda a regra —
 * cobrança, sala, autorização de participante, idempotência sob lock — vive no
 * CallService; o controller só delega e traduz CallException para HTTP.
 *
 * A AUTORIZAÇÃO DE PARTICIPANTE é do service, não do route-binding: `{call}` é
 * resolvido por id, mas o service confere `member_id`/`performer_profile_id ==
 * ator` e devolve 404 uniforme para chamada de terceiro (armadilha do
 * SubstituteBindings — CLAUDE.md; testar com id EXISTENTE de terceiro).
 */
class CallController extends Controller
{
    public function __construct(private CallService $calls) {}

    /** Membro pede a chamada. `{profile}` = performer_profile_id. */
    public function request(Request $request, PerformerProfile $profile): JsonResponse
    {
        try {
            $session = $this->calls->request($request->user(), $profile);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'call_id' => $session->id,
            'price_per_minute' => $session->price_per_minute,
            'expires_in_seconds' => CallSession::PENDING_TTL_SECONDS,
        ], 201);
    }

    /** Performer aceita → devolve o JWT dela para entrar na sala. */
    public function accept(Request $request, CallSession $call): JsonResponse
    {
        try {
            $bundle = $this->calls->accept($request->user(), $call);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle + ['call_id' => $call->id]);
    }

    /** Performer recusa. */
    public function decline(Request $request, CallSession $call): JsonResponse
    {
        try {
            $this->calls->decline($request->user(), $call);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Batimento do membro: cobra o minuto devido e devolve o estado de saldo. */
    public function heartbeat(Request $request, CallSession $call): JsonResponse
    {
        try {
            $state = $this->calls->heartbeat($request->user(), $call);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($state);
    }

    /** Renovação do JWT (membro OU performer). Reconcilia a cobrança do membro. */
    public function tokenRefresh(Request $request, CallSession $call): JsonResponse
    {
        try {
            $bundle = $this->calls->refreshToken($request->user(), $call);
        } catch (CallException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle);
    }

    /** Encerramento voluntário por qualquer lado. */
    public function end(Request $request, CallSession $call): JsonResponse
    {
        try {
            $this->calls->end($request->user(), $call);
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
