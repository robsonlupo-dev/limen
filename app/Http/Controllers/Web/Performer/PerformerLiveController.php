<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Services\LiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Início/fim da live pública da performer (Sprint 15, PR #139). Rotas web
 * consumidas por fetch → JSON explícito (fora de api/*, a exceção não vira JSON
 * sozinha). Toda a regra (sala, token, idempotência sob lock, is_live) vive no
 * LiveSessionService — o controller só delega.
 *
 * A resposta devolve {token, wsUrl}; NÃO devolve o room_name (o livekit-client
 * extrai a sala do próprio JWT — invariante do #138 de não vazar o room_name).
 */
class PerformerLiveController extends Controller
{
    public function __construct(private LiveSessionService $live) {}

    /** Estúdio de transmissão da performer (o LiveRoom conecta pelo start). */
    public function page(Request $request): Response
    {
        // O slug alimenta o canal `live.{slug}` do <LiveOverlay> no estúdio — a
        // performer também vê as animações de gorjeta/presente (prova social).
        return Inertia::render('Performer/Live', [
            'performerSlug' => $request->user()->performerProfile->slug,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        return response()->json($this->live->start($request->user()));
    }

    public function stop(Request $request): JsonResponse
    {
        $this->live->stop($request->user());

        return response()->json(['ok' => true]);
    }
}
