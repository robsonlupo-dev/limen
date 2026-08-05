<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreLivePreviewFrameRequest;
use App\Services\LivePreviewService;
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

    /**
     * Frame de preview do catálogo (PR #143). Só grava se houver live ATIVA da
     * performer (senão 422 — sem live, não há sessão para keyar o arquivo). Valida
     * os bytes SEM decodificar server-side: teto de 50KB + magic-byte JPEG (finfo).
     * O <LiveRoom> chama isto a cada ~10s; falha é inócua (o próximo frame chega).
     */
    public function previewFrame(StoreLivePreviewFrameRequest $request, LivePreviewService $previews): JsonResponse
    {
        $session = $this->live->activeFor($request->user()->performerProfile);
        if ($session === null) {
            return response()->json(['reason' => 'not_live', 'message' => 'Nenhuma live ativa.'], 422);
        }

        $bytes = $request->decodedFrame();
        if ($bytes === null || strlen($bytes) > LivePreviewService::MAX_BYTES) {
            return response()->json(['reason' => 'invalid_frame', 'message' => 'Frame inválido ou grande demais.'], 422);
        }

        // Sniff sobre os BYTES (lê o cabeçalho, não decodifica o bitmap — sem risco
        // de bomba de descompressão). Só JPEG entra; o serving re-sniffa na saída.
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/jpeg') {
            return response()->json(['reason' => 'invalid_frame', 'message' => 'Frame inválido ou grande demais.'], 422);
        }

        $previews->store($session, $bytes);

        return response()->json(['ok' => true]);
    }
}
