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
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\ChatService;
use App\Services\MemberPhotoService;
use App\Services\PerformerCatalogService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use App\Support\FanAlias;
use App\Support\MessageTeaser;
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
 * Cobrança é por ACESSO (ChatAccessService). M.13.1 (PR #132): NÃO há mais chat
 * grátis — todo membro (assinante ou não) paga uma janela por performer, e o
 * custo por tier vem da TokenCreditPolicy (2 ou 1 token).
 */
class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private ChatAccessService $chatAccessService,
        private TokenService $tokenService,
        private MemberPhotoService $memberPhotos,
        private TokenCreditPolicy $creditPolicy,
        private PerformerCatalogService $catalog,
    ) {}

    /**
     * feat/chat-economy-v2: tela de conversa acessada por PERFORMER (slug), não por
     * id de conversa. É a porta do membro iniciar o chat a partir do card do catálogo.
     *
     * Se já existe conversa do par, redireciona para a tela normal (show) — não
     * duplica a lógica de paywall/leitura. Se não existe, renderiza a MESMA tela em
     * modo "compor": o canal só nasce (e o membro só é cobrado) quando ele envia a
     * primeira mensagem (startWithPerformer → ChatService::memberSendToPerformer).
     *
     * findBySlug usa o escopo publicCatalog (verificada + ativa): performer fora do
     * ar dá 404, indistinguível de slug inexistente.
     */
    public function showWithPerformer(Request $request)
    {
        $user = $request->user();
        $performer = $this->catalog->findBySlug($request->route('slug'));

        $conversation = Conversation::where('member_id', $user->id)
            ->where('performer_profile_id', $performer->id)
            ->first();

        if ($conversation) {
            return redirect()->route('chat.show', $conversation->id);
        }

        return Inertia::render('Chat/Show', [
            'conversation' => [
                // id null = modo compor: o front mostra o compositor e envia por
                // chat.start (o canal ainda não existe).
                'id' => null,
                'status' => 'active',
                'performer' => [
                    'stage_name' => $performer->stage_name,
                    'slug' => $performer->slug,
                    'profile_id' => $performer->id,
                ],
            ],
            'messages' => new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]),
            'teaser' => null,
            'access' => [
                'state' => 'none',
                'can_send' => false,
                'can_read' => false,
                'locked' => true,
                'days_remaining' => 0,
                'expires_at' => null,
            ],
            'photoSharing' => ['can_share' => false, 'photos' => []],
            'accessCost' => $this->creditPolicy->chatCost($user),
            'balance' => $this->tokenService->balance($user),
        ]);
    }

    /**
     * feat/chat-economy-v2: o membro ENVIA a primeira mensagem a uma performer,
     * iniciando o canal. A cobrança do tier acontece dentro de
     * memberSendToPerformer (no envio), atômica com a criação da conversa e da
     * mensagem. Idempotente por (member, performer): reenvio não duplica a conversa.
     */
    public function startWithPerformer(SendMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $performer = $this->catalog->findBySlug($request->route('slug'));

        try {
            $message = $this->chatService->memberSendToPerformer(
                $performer,
                $user,
                $request->validated('body'),
            );
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente para iniciar a conversa.',
            ], 422);
        }

        return response()->json([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'created_at' => $message->created_at,
        ], 201);
    }

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
        // performer sempre lê; o membro só com janela paga vigente para AQUELE
        // par. Sem isso, preview vem null (a UI mostra "bloqueado") — nunca
        // vazamos o corpo na listagem.
        //
        // M.13.1 (PR #132): NÃO há mais atalho de assinante aqui. Este era um
        // segundo paywall (cópia do accessState) e um `|| $isSubscriber` vazaria
        // preview/contagem para assinante sem janela paga — bypass de leitura. A
        // leitura é uniforme: performer sempre, senão linha de `chat_access` ativa.
        $activePerformerIds = [];
        if (! $viewerIsPerformer) {
            $performerIds = collect($page->items())->pluck('performer_profile_id');
            $activePerformerIds = ChatAccess::where('member_id', $user->id)
                ->whereIn('performer_profile_id', $performerIds)
                ->get()
                ->filter(fn (ChatAccess $a) => $a->hasFullAccess())
                ->pluck('performer_profile_id')
                ->all();
        }

        $conversations = $page->through(function (Conversation $c) use ($user, $viewerIsPerformer, $activePerformerIds) {
            $canRead = $viewerIsPerformer
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
                // Com leitura: preview normal (60 chars). Sem leitura: o GANCHO
                // cortado no servidor (MessageTeaser) — as primeiras palavras em
                // claro, o resto fica para o desbloqueio. O corpo completo NUNCA
                // trafega para quem não pagou; é o backend que corta.
                'last_message_preview' => $last
                    ? ($canRead
                        ? str($last->body)->limit(60)->value()
                        : MessageTeaser::for($last->body))
                    : null,
                // Há mensagem, mas sem leitura: a UI mostra o gancho + "desbloqueie
                // para ler" (antes era só cadeado). `locked` distingue os dois.
                'locked' => ! $canRead && $c->last_message_at !== null,
                // Título = o OUTRO participante, por lado. A performer via SEMPRE o
                // próprio nome em toda linha (o payload só trazia o dela) e não
                // distinguia uma conversa da outra. Agora:
                //  - performer vê o MEMBRO por FanAlias (nunca dado real — M.13.10);
                //  - membro vê a performer (nome público).
                'title' => $viewerIsPerformer
                    ? ($c->member_id !== null
                        ? FanAlias::label($user->performerProfile->id, $c->member_id)
                        : 'Membro')
                    : $c->performerProfile->stage_name,
            ];
        });

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'accessCost' => $this->creditPolicy->chatCost($request->user()),
            'viewerIsPerformer' => (bool) $viewerIsPerformer,
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
        //
        // A marcação continua SEMPRE acontecendo, inclusive para quem desligou
        // read receipts, porque `read_at` tem dois usos: confirmar a leitura ao
        // remetente E alimentar o `unread_count` do index(). Deixar de marcar
        // desligaria os dois — o membro Black ficaria com a própria caixa
        // eternamente marcada como não-lida. O perk é aplicado na ENTREGA
        // (readReceiptVisible), não na escrita: quem tem o perk lê sem que o
        // remetente veja, e continua com o próprio contador funcionando.
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
            // O OUTRO participante desligou a confirmação de leitura? Então
            // nenhuma mensagem minha volta com read_at — resolvido uma vez, e
            // não por mensagem: numa conversa de duas pessoas, quem lê o que eu
            // mandei é sempre a mesma pessoa.
            $showReadReceipt = $this->readReceiptVisible($conversation, $request->user());

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
                    // Confirmação de leitura só nas MINHAS mensagens, e só se
                    // quem lê não desligou o perk. read_at de uma mensagem que
                    // EU recebi diz quando eu a li — não acrescenta nada na
                    // minha tela e não precisa trafegar.
                    'read_at' => ($showReadReceipt && $m->sender_id === $request->user()->id)
                        ? $m->read_at
                        : null,
                ]);
        }

        // Gancho do paywall: quando a leitura está travada (grace/expired/none), o
        // membro vê as primeiras palavras da ÚLTIMA mensagem cortadas no servidor —
        // o mesmo teaser da lista. `value('body')` traz só a coluna da última linha
        // (nunca a CONTAGEM atrás do paywall — o §152 acima devolve paginador vazio
        // de propósito). `null` quando destravado (o corpo já aparece) ou quando não
        // há mensagem legível (ex.: expirado com histórico soft-deletado).
        $teaser = null;
        if ($state['locked']) {
            $teaser = MessageTeaser::for($conversation->messages()->latest('id')->value('body'));
        }

        return Inertia::render('Chat/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'performer' => [
                    'stage_name' => $conversation->performerProfile->stage_name,
                    'slug' => $conversation->performerProfile->slug,
                    // O id vai para o payload do compartilhamento. É o perfil
                    // PÚBLICO da performer, não identidade de membro — nada a
                    // ver com o FanAlias, que protege o outro lado.
                    'profile_id' => $conversation->performer_profile_id,
                ],
            ],
            'messages' => $messages,
            'teaser' => $teaser,
            'access' => $state,
            'photoSharing' => $this->photoSharingProps($request, $conversation, $state),
            'accessCost' => $this->creditPolicy->chatCost($request->user()),
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
        } catch (InsufficientBalanceException) {
            // feat/chat-economy-v2: o membro paga no ENVIO. Sem saldo, recusa clara
            // (sem mensagem, sem cobrança, sem saldo negativo — o débito reverteu).
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente para enviar. Compre tokens na sua carteira.',
            ], 422);
        }

        return response()->json([
            'message_id' => $message->id,
            'created_at' => $message->created_at,
        ], 201);
    }

    /**
     * Compra ou renova o acesso ao chat desta conversa (M.13.1: todo membro paga).
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
            // Rede de segurança: o único InvalidArgumentException que resta em
            // openOrRenew é "não é o membro da conversa", já coberto pelo abort_if
            // 404 acima (M.13.1 removeu o caso "assinante tem chat livre"). Mantido
            // defensivamente para caminho de dinheiro nunca cair em 500.
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
     * As MINHAS mensagens podem voltar com confirmação de leitura?
     *
     * Depende de quem LÊ o que eu mando — o outro participante — porque é a
     * leitura dele que o read_at revelaria. Perk de Black/FC: o membro lê sem
     * que a performer saiba. A performer não tem o perk (não é consumer), então
     * na prática isto só apaga o "Lida" da tela dela.
     *
     * Ausência de confirmação é ambígua de propósito: pode ser não-lida ou
     * receipts desligados, e a UI não distingue as duas — se distinguisse, o
     * "desligado" viraria um aviso de que o membro é assinante Black.
     */
    /**
     * O que a tela do chat precisa para oferecer "Compartilhar foto".
     *
     * Só ao MEMBRO desta conversa: para a performer o bloco inteiro sai como
     * `can_share: false` e lista vazia. Ela não precisa saber se ele tem fotos
     * ativas — isso é estado do outro lado, e a tela dela nunca insinua.
     *
     * `can_share` repete o `can_send` porque é o MESMO gate que o Service aplica
     * (ver MemberPhotoService::shareWith): a tela não pode oferecer o que o
     * servidor vai recusar, e o servidor não pode confiar na tela. Quem decide
     * continua sendo o Service — isto aqui é só o botão.
     *
     * A lista carrega o id e a FAIXA, nunca `expires_at`. Se um dia ela for
     * reusada num componente compartilhado com a tela da performer, não há
     * relógio no payload para vazar (§ 1.2).
     *
     * @param  array<string, mixed>  $state
     * @return array{can_share:bool,photos:array<int, array<string, mixed>>}
     */
    private function photoSharingProps(Request $request, Conversation $conversation, array $state): array
    {
        $user = $request->user();

        if ($user->id !== $conversation->member_id) {
            return ['can_share' => false, 'photos' => []];
        }

        return [
            'can_share' => (bool) $state['can_send'],
            'photos' => $this->memberPhotos->activeFor($user)
                ->map(fn ($photo) => $photo->presentForMember())
                ->all(),
        ];
    }

    private function readReceiptVisible(Conversation $conversation, User $viewer): bool
    {
        $counterpartId = $viewer->id === $conversation->member_id
            ? $conversation->performerProfile->user_id
            : $conversation->member_id;

        // withTrashed: `User` usa SoftDeletes e o encerramento de conta soft-deleta.
        // Sem isto a contraparte encerrada some do find(), e o gate abaixo caía no
        // lado PERMISSIVO — a performer que nunca viu "Lida" passava a ver "Lida"
        // em todas as mensagens antigas do membro que acabou de sair (o read_at
        // continua gravado, porque a marcação é sempre feita). Além de furar o
        // perk depois do fato, a mudança era observável: o "Lida" aparecendo
        // sozinho anunciava o encerramento da conta.
        $counterpart = User::withTrashed()->find($counterpartId);

        // Fail-closed em dois casos, e o segundo NÃO é redundante:
        //
        //  - contraparte inexistente (linha sumiu, conversa órfã);
        //  - contraparte ENCERRADA. Aqui não dá para perguntar ao perk: o
        //    encerramento zera as colunas para o lado público (DeletionService::
        //    anonymizeUser), justamente para não deixar na linha o atestado de
        //    que a pessoa era Black/FC. Consultar `hasReadReceipts()` numa conta
        //    encerrada devolveria `true` por causa dessa limpeza e reabriria o
        //    vazamento pela outra porta — o "Lida" apareceria em bloco no
        //    instante do encerramento, que é o sinal que se quer evitar.
        //
        // Regra: conta encerrada não emite sinal novo, valor de coluna nenhum.
        if ($counterpart === null || $counterpart->trashed()) {
            return false;
        }

        // Conta viva: o perk é do LEITOR e é consultado normalmente.
        return $counterpart->hasReadReceipts();
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
