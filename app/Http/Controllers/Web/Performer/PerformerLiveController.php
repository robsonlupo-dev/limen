<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\LiveChatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\MuteLiveViewerRequest;
use App\Http\Requests\Web\SendLiveChatRequest;
use App\Http\Requests\Web\StoreLivePreviewFrameRequest;
use App\Services\LiveChatService;
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
    public function __construct(
        private LiveSessionService $live,
        private LiveChatService $chat,
    ) {}

    /**
     * Console de transmissão da performer (feat/live-room-console). O <LiveRoom>
     * conecta pelo start; o slug alimenta o canal `live.{slug}` (feed de gorjeta/
     * presente + chat da sala). Se ela recarrega no meio da live, pré-carrega o
     * chat recente para a sala não voltar vazia.
     */
    public function page(Request $request): Response
    {
        $session = $this->live->activeFor($request->user()->performerProfile);

        return Inertia::render('Performer/Live', [
            'performerSlug' => $request->user()->performerProfile->slug,
            'initialChat' => $session ? $this->chat->recent($session) : [],
        ]);
    }

    /**
     * Dados AO VIVO do console, polados a cada ~15s e após cada gorjeta/presente:
     * contagem real de espectadores (listParticipants do LiveKit, cache ~12s) e o
     * ganho acumulado NESTA transmissão (soma do ledger — bate por construção). Sem
     * live ativa → is_live:false (o console mostra o estado ocioso).
     */
    public function console(Request $request): JsonResponse
    {
        $session = $this->live->activeFor($request->user()->performerProfile);

        if ($session === null) {
            return response()->json(['is_live' => false]);
        }

        return response()->json([
            'is_live' => true,
            'viewers' => $this->live->viewerCount($session),
            'earned' => $this->live->earnedThisLive($session),
        ]);
    }

    /** A performer responde no chat da sala. Free; passa pelo filtro de conteúdo. */
    public function sendChat(SendLiveChatRequest $request): JsonResponse
    {
        $session = $this->live->activeFor($request->user()->performerProfile);

        if ($session === null) {
            return response()->json(['reason' => 'not_live', 'message' => 'Nenhuma live ativa.'], 409);
        }

        try {
            $message = $this->chat->send($session, $request->user(), true, $request->validated('body'));
        } catch (LiveChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(['id' => $message->id]);
    }

    /**
     * A performer silencia (e expulsa) o autor de uma mensagem da sala. O alvo é o ID
     * da mensagem; o serviço resolve o membro sem expor a identidade ao front.
     */
    public function mute(MuteLiveViewerRequest $request): JsonResponse
    {
        $session = $this->live->activeFor($request->user()->performerProfile);

        if ($session === null) {
            return response()->json(['reason' => 'not_live', 'message' => 'Nenhuma live ativa.'], 409);
        }

        try {
            $label = $this->chat->mute($session, (int) $request->validated('message_id'));
        } catch (LiveChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(['muted' => $label]);
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
