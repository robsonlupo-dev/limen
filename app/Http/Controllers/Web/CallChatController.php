<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendCallMessageRequest;
use App\Models\CallSession;
use App\Services\CallChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat de TEXTO da chamada 1:1 (feat/in-call-chat). Rotas por SESSÃO, dos dois
 * lados — a autorização de participante é aqui (404 uniforme para terceiro, como
 * o resto das rotas /call/{call}/*). O envio exige a chamada ATIVA (acabou a
 * chamada, acabou o chat; as mensagens ficam retidas só para moderação).
 */
class CallChatController extends Controller
{
    public function __construct(private CallChatService $chat) {}

    /** Histórico recente para o painel ao abrir. */
    public function index(Request $request, CallSession $call): JsonResponse
    {
        $this->assertParticipant($request, $call);

        return response()->json(['messages' => $this->chat->history($call)]);
    }

    /** Envia uma mensagem (só com a chamada ativa). */
    public function store(SendCallMessageRequest $request, CallSession $call): JsonResponse
    {
        $this->assertParticipant($request, $call);

        if (! $call->isActive()) {
            return response()->json(['message' => 'A chamada não está ativa.'], 409);
        }

        try {
            $message = $this->chat->send($call, $request->user(), (string) $request->input('body'));
        } catch (ChatException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message], 201);
    }

    /**
     * Só o membro dono ou a performer da sessão. Terceiro → 404 uniforme (não
     * vaza a existência da chamada), como as demais rotas /call/{call}/*.
     */
    private function assertParticipant(Request $request, CallSession $call): void
    {
        $user = $request->user();
        $isMember = $call->member_id === $user->id;
        $isPerformer = $call->performerProfile?->user_id === $user->id;

        abort_unless($isMember || $isPerformer, 404);
    }
}
