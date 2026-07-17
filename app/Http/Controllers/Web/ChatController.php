<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ChatException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenChatAccessRequest;
use App\Http\Requests\SendMessageRequest;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Services\ChatAccessService;
use App\Services\ChatService;
use App\Services\TokenService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chat pós-desbloqueio de Interesse. Membro e performer usam as mesmas telas; a
 * ConversationPolicy garante que só participantes entrem. NÃO há endpoint de
 * abertura de conversa pelo membro — o canal nasce no desbloqueio.
 *
 * Cobrança é por ACESSO (ChatAccessService): assinante tem chat livre; membro
 * sem assinatura paga uma janela por performer.
 */
class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private ChatAccessService $chatAccessService,
        private TokenService $tokenService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $viewerIsPerformer = $user->role === 'performer' && $user->performerProfile;

        $query = Conversation::query()
            ->with('performerProfile:id,user_id,stage_name,slug,avatar_path')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($viewerIsPerformer) {
            $query->where('performer_profile_id', $user->performerProfile->id);
        } else {
            $query->where('member_id', $user->id);
        }

        $page = $query->paginate(20);

        // Preview da última mensagem respeita o MESMO paywall do show(): a
        // performer sempre lê; o membro só com Círculo ativo (global) ou janela
        // paga vigente para AQUELE par. Sem isso, preview vem null (a UI mostra
        // "bloqueado") — nunca vazamos o corpo na listagem.
        $isSubscriber = ! $viewerIsPerformer && $user->activeSubscription() !== null;
        $activePerformerIds = [];
        if (! $viewerIsPerformer && ! $isSubscriber) {
            $performerIds = collect($page->items())->pluck('performer_profile_id');
            $activePerformerIds = ChatAccess::where('member_id', $user->id)
                ->whereIn('performer_profile_id', $performerIds)
                ->get()
                ->filter(fn (ChatAccess $a) => $a->hasFullAccess())
                ->pluck('performer_profile_id')
                ->all();
        }

        $conversations = $page->through(function (Conversation $c) use ($user, $viewerIsPerformer, $isSubscriber, $activePerformerIds) {
            $canRead = $viewerIsPerformer
                || $isSubscriber
                || in_array($c->performer_profile_id, $activePerformerIds, true);

            $last = $c->messages()->latest('id')->first();

            return [
                'id' => $c->id,
                'status' => $c->status,
                'last_message_at' => $c->last_message_at,
                // Não lidas = mensagens do OUTRO participante ainda sem read_at.
                // Só conta quando há leitura: sem acesso o cadeado já sinaliza —
                // não vazamos a CONTAGEM atrás do paywall (mesma regra do show()).
                'unread_count' => $canRead
                    ? $c->messages()
                        ->whereNull('read_at')
                        ->where('sender_id', '!=', $user->id)
                        ->count()
                    : 0,
                'last_message_preview' => ($canRead && $last)
                    ? str($last->body)->limit(60)->value()
                    : null,
                // Há mensagem, mas sem leitura: a UI mostra cadeado no lugar do preview.
                'locked' => ! $canRead && $c->last_message_at !== null,
                'performer' => [
                    'stage_name' => $c->performerProfile->stage_name,
                    'slug' => $c->performerProfile->slug,
                    'avatar_path' => $c->performerProfile->avatar_path,
                ],
            ];
        });

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'accessCost' => (int) config('chat.access_cost'),
        ]);
    }

    /**
     * Mensagens paginadas (20/página). O CORPO só é entregue quando o leitor tem
     * leitura plena: withhold do body na carência (grace) — a tarja "Pague para
     * ler" é UI, mas o gate real é NÃO enviar o texto para quem não pagou.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        $conversation->loadMissing('performerProfile');
        $state = $this->stateFor($request, $conversation);

        // Ler = marcar como lida: só quando o corpo é DE FATO entregue (leitura
        // plena e destravada). Em grace o corpo é retido, então não marca. Zera
        // as não-lidas do OUTRO participante; idempotente.
        if ($state['can_read'] && ! $state['locked']) {
            $conversation->messages()
                ->whereNull('read_at')
                ->where('sender_id', '!=', $request->user()->id)
                ->update(['read_at' => now()]);
        }

        // Sem leitura (nunca comprou ou já passou a carência): não expõe nem os
        // metadados NEM A CONTAGEM — paginador vazio de fato (total 0). Blanquear
        // só a collection deixaria total() revelar quantas mensagens existem
        // atrás do paywall.
        if (! $state['can_read']) {
            $messages = new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]);
        } else {
            // Com leitura bloqueada (grace): metadados + locked, sem corpo.
            $messages = $conversation->messages()
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn (Message $m) => [
                    'id' => $m->id,
                    'sender_id' => $m->sender_id,
                    'created_at' => $m->created_at,
                    'locked' => $state['locked'],
                    // Corpo só quando há leitura plena e destravada.
                    'body' => (! $state['locked']) ? $m->body : null,
                ]);
        }

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
            'access' => $state,
            'accessCost' => (int) config('chat.access_cost'),
            'balance' => $this->tokenService->balance($request->user()),
        ]);
    }

    public function storeMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        try {
            $message = $this->chatService->sendMessage(
                $conversation,
                $request->user(),
                $request->validated('body'),
            );
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message_id' => $message->id,
            'created_at' => $message->created_at,
        ], 201);
    }

    /**
     * Compra ou renova o acesso ao chat desta conversa (membro sem assinatura).
     * Idempotente por idempotency_key.
     */
    public function openAccess(OpenChatAccessRequest $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        // Só o membro dono compra acesso; a performer não. 404 para não revelar.
        abort_if($request->user()->id !== $conversation->member_id, 404);

        try {
            $this->chatAccessService->openOrRenew(
                $conversation,
                $request->user(),
                $request->validated('idempotency_key'),
            );
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente para abrir o chat.',
            ], 422);
        } catch (UniqueConstraintViolationException) {
            // Corrida de dois opens simultâneos do mesmo par: o outro venceu e já
            // criou a linha (cobrando uma vez). DB::transaction reverteu o débito
            // desta requisição no rollback, então NÃO houve cobrança dupla. Cai no
            // retorno de sucesso abaixo com o estado vigente — open é idempotente
            // por par: o membro fica com acesso, cobrado 1x.
        } catch (\InvalidArgumentException $e) {
            // Assinante (já tem chat livre) ou caso inválido.
            return response()->json(['reason' => 'not_applicable', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'access' => $this->chatAccessService->accessState($conversation, $request->user()),
            'new_balance' => $this->tokenService->balance($request->user()),
        ], 201);
    }

    public function performerStart(SendMessageRequest $request, PerformerInterest $interest): JsonResponse
    {
        $performerProfile = $request->user()->performerProfile;

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

        return response()->json(['status' => 'sent'], 202);
    }

    /**
     * Estado de acesso do ponto de vista do requisitante. A performer sempre lê a
     * própria conversa (não passa pela cobrança de acesso do membro).
     *
     * @return array{state:string,can_send:bool,can_read:bool,locked:bool,days_remaining:?int,expires_at:?string}
     */
    private function stateFor(Request $request, Conversation $conversation): array
    {
        $isPerformer = $request->user()->id === $conversation->performerProfile->user_id;

        if ($isPerformer) {
            return [
                'state' => 'performer',
                'can_send' => true,
                'can_read' => true,
                'locked' => false,
                'days_remaining' => null,
                'expires_at' => null,
            ];
        }

        return $this->chatAccessService->accessState($conversation, $request->user());
    }
}
