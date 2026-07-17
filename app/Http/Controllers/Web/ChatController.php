<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ChatException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Services\ChatService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chat pós-desbloqueio de Interesse. Membro e performer usam as mesmas telas; a
 * ConversationPolicy garante que só participantes entrem. NÃO há endpoint de
 * abertura de conversa pelo membro — o canal nasce no desbloqueio
 * (ver InterestService::unlock e docs/INTEREST_SYSTEM_SPEC.md §4-5).
 */
class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private TokenService $tokenService,
    ) {}

    /**
     * Lista as conversas do usuário. Para o membro, as suas; para a performer, as
     * do perfil dela. Nunca mistura os dois lados.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Conversation::query()
            ->with('performerProfile:id,user_id,stage_name,slug,avatar_path')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($user->role === 'performer' && $user->performerProfile) {
            $query->where('performer_profile_id', $user->performerProfile->id);
        } else {
            $query->where('member_id', $user->id);
        }

        $conversations = $query->paginate(20)->through(fn (Conversation $c) => [
            'id' => $c->id,
            'status' => $c->status,
            'last_message_at' => $c->last_message_at,
            'performer' => [
                'stage_name' => $c->performerProfile->stage_name,
                'slug' => $c->performerProfile->slug,
                'avatar_path' => $c->performerProfile->avatar_path,
            ],
        ]);

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'balance' => $this->tokenService->balance($user),
            'messageCost' => (int) config('chat.message_cost'),
            'freeChat' => $user->activeCircle() !== null,
        ]);
    }

    /**
     * Mensagens paginadas (20/página), mais recentes primeiro.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        // 404 (não 403) para não-participante: um id existente-mas-alheio e um
        // inexistente respondem igual, sem revelar a existência da conversa.
        abort_if($request->user()->cannot('view', $conversation), 404);

        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Message $m) => [
                'id' => $m->id,
                'sender_id' => $m->sender_id,
                'body' => $m->body,
                'read_at' => $m->read_at,
                'created_at' => $m->created_at,
            ]);

        return Inertia::render('Chat/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'performer' => [
                    'stage_name' => $conversation->performerProfile->stage_name,
                    'slug' => $conversation->performerProfile->slug,
                ],
            ],
            'messages' => $messages,
            'balance' => $this->tokenService->balance($request->user()),
            'messageCost' => (int) config('chat.message_cost'),
            'freeChat' => $request->user()->activeCircle() !== null,
        ]);
    }

    /**
     * Envia uma mensagem numa conversa aberta (resposta de qualquer lado).
     */
    public function storeMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        // Não-participante → 404 (não revela existência). Conversa arquivada é
        // tratada pelo ChatService (422), pois o participante já a conhece.
        abort_if($request->user()->cannot('view', $conversation), 404);

        try {
            $message = $this->chatService->sendMessage(
                $conversation,
                $request->user(),
                $request->validated('body'),
            );
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente. Compre mais tokens para enviar a mensagem.',
            ], 422);
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message_id' => $message->id,
            'created_at' => $message->created_at,
            'new_balance' => $this->tokenService->balance($request->user()),
        ], 201);
    }

    /**
     * A performer manda a primeira mensagem a partir de uma linha de Interesse.
     *
     * A resposta é DELIBERADAMENTE uniforme: se o membro optou por sair, o envio
     * parece bem-sucedido e não entrega nada (máscara de opt-out,
     * INTEREST_ANONYMITY_FLOOR.md). Nunca devolvemos o id da mensagem aqui — isso
     * distinguiria o caso mascarado (sem mensagem) do real e vazaria o opt-out.
     */
    public function performerStart(SendMessageRequest $request, PerformerInterest $interest): JsonResponse
    {
        $performerProfile = $request->user()->performerProfile;

        // Sem perfil, ou interesse de outra performer: 404 (não revela a linha).
        if (! $performerProfile || $interest->performer_profile_id !== $performerProfile->id) {
            abort(404);
        }

        try {
            $this->chatService->performerMessageFromInterest(
                $performerProfile,
                $interest,
                $request->validated('body'),
            );
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        // Idêntico para suppressed (nada entregue) e unlocked (entregue).
        return response()->json(['status' => 'sent'], 202);
    }
}
