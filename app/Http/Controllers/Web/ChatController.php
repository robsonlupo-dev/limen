<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ChatException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenChatAccessRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\Web\StoreChatAudioRequest;
use App\Http\Requests\Web\StorePpvMessageRequest;
use App\Http\Requests\Web\StoreStoryReplyRequest;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerContent;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\ChatAudioStore;
use App\Services\ChatService;
use App\Services\ContentVisibilityService;
use App\Services\MemberPhotoService;
use App\Services\PerformerCatalogService;
use App\Services\StoryVisibilityService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use App\Support\FanAlias;
use App\Support\MemberDisplayName;
use App\Support\MessageTeaser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        private ChatAudioStore $audioStore,
        private ContentVisibilityService $contentVisibility,
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
                // Modo compor: quem olha é o MEMBRO, então o cabeçalho é a performer.
                'viewer_is_performer' => false,
                'performer' => [
                    'stage_name' => $performer->stage_name,
                    'slug' => $performer->slug,
                    'profile_id' => $performer->id,
                    'avatar_url' => $this->performerAvatarUrl($performer),
                ],
                'member' => null,
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

    /**
     * Responder ao story → chat (feat/story-reply-to-chat). Estilo Insta: o membro
     * responde em cima do story da performer e essa resposta vira a 1ª mensagem do
     * chat, ABRINDO/pagando a janela (mesma economia de startWithPerformer). A
     * mensagem carrega o ponteiro do story respondido → a bolha mostra "Respondeu
     * ao story" (+ miniatura, para a dona).
     *
     * Visibilidade ANTES de tudo: quem não pode VER o story (não-seguidor onde o
     * story pede seguir, tier insuficiente, exclusivo) não pode respondê-lo — 404
     * indistinguível de story inexistente (não vaza que o story existe). Story
     * expirado também é 404 (StoryVisibilityService já trata TTL). A cobrança e a
     * criação da conversa são atômicas dentro de memberSendToPerformer.
     */
    public function storyReply(
        StoreStoryReplyRequest $request,
        PerformerStory $story,
        StoryVisibilityService $storyVisibility,
    ): JsonResponse {
        $user = $request->user();

        // O membro precisa poder VER o story para respondê-lo. 404 (não 403) para
        // não confirmar a existência de um story que ele não deveria alcançar.
        abort_unless($storyVisibility->canView($story, $user), 404);

        $performer = $story->performerProfile;

        try {
            $message = $this->chatService->memberSendToPerformer(
                $performer,
                $user,
                $request->validated('body'),
                $story->id,
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
            $query->where('performer_profile_id', $user->performerProfile->id)
                // fix/voice-access-and-chat-avatar: a foto do membro acompanha o
                // FanAlias no chat, como já acontece no catálogo. Só as colunas que
                // User::avatarUrl() precisa — a URL é chaveada no avatar_token OPACO,
                // nunca no member_id (o alias segue escondendo o id). `nickname` entra
                // aqui para a LISTA exibir o apelido (feat/member-nickname) — sem ele o
                // select enxuto deixava $c->member->nickname null e a lista caía no alias
                // enquanto a conversa aberta mostrava o apelido (achado da revisão).
                ->with(['member' => fn ($q) => $q->select('id', 'nickname', 'avatar_path', 'avatar_token')]);
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
                //
                // Redigida ("desfazer envio") e efêmera JÁ VISTA não vazam o corpo
                // na listagem — sem esse gate o preview seria a porta dos fundos
                // que fura o "some depois de vista" e a redação de exibição. O
                // corpo segue no banco para a moderação; só a EXIBIÇÃO some.
                'last_message_preview' => $last && ! $this->previewHidden($last)
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
                        ? MemberDisplayName::for($c->member?->nickname, $user->performerProfile->id, $c->member_id)
                        : 'Membro')
                    : $c->performerProfile->stage_name,
                // Foto do OUTRO participante, ao lado do alias (silhueta quando não
                // há). À performer, a do membro (token opaco); ao membro, a da
                // performer. Nenhum member_id/nome/dado real trafega — só a URL
                // assinada e o alias já resolvido em `title`.
                'avatar_url' => $viewerIsPerformer
                    ? $c->member?->avatarUrl()
                    : $this->performerAvatarUrl($c->performerProfile),
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

        $conversation->loadMissing('performerProfile', 'member');
        $state = $this->stateFor($request, $conversation);
        $viewerIsPerformer = $request->user()->id === $conversation->performerProfile->user_id;

        // Ler = marcar como lida: só quando o corpo é DE FATO entregue (leitura
        // plena e destravada). Em grace o corpo é retido, então não marca. Zera
        // as não-lidas do OUTRO participante; idempotente.
        //
        // A marcação continua SEMPRE acontecendo, inclusive para quem desligou
        // read receipts, porque `read_at` tem dois usos: confirmar a leitura ao
        // remetente E alimentar o `unread_count` do index(). Deixar de marcar
        // desligaria os dois — o membro Black ficaria com a própria caixa
        // eternamente marcada como não-lida. O perk é aplicado na ENTREGA
        // (readReceiptVisible), não na escrita.
        //
        // Timer efêmero (Onda 3): a EFÊMERA NÃO é marcada como lida ao abrir — ela
        // só é lida (e consumida) quando o destinatário TOCA para revelar (reveal).
        // Marcar aqui a consumiria sem nunca ter sido vista. Por isso a marcação
        // automática cobre só `ephemeral = false`.
        $viewer = $request->user();
        $viewerId = $viewer->id;

        if (! $state['can_read']) {
            $messages = new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]);
        } else {
            $showReadReceipt = $this->readReceiptVisible($conversation, $request->user());

            if (! $state['locked']) {
                $conversation->messages()
                    ->whereNull('read_at')
                    ->where('sender_id', '!=', $viewerId)
                    ->where('ephemeral', false)
                    ->update(['read_at' => now()]);
            }

            $messages = $conversation->messages()
                ->with(['gift:id,slug,name', 'replyToStory:id,expires_at', 'ppvContent.performerProfile.user'])
                ->orderByDesc('id')
                ->paginate(20)
                ->through(function (Message $m) use ($state, $viewer, $viewerId, $viewerIsPerformer, $conversation, $showReadReceipt) {
                    // Timer efêmero (Onda 3), dois estados distintos:
                    //  - SELADA: efêmera não revelada, do lado do DESTINATÁRIO →
                    //    "toque para ver". O corpo NÃO trafega até o reveal (mais
                    //    seguro que o v1 antigo, que mandava o corpo na 1ª abertura).
                    //  - CONSUMIDA (vanished): efêmera já revelada → some das duas
                    //    pontas ("expirada"). Ver ephemeralVanished/ephemeralSealed.
                    // O REMETENTE vê o próprio corpo até ser consumido (sealed=false
                    // do lado dele). Corpo/áudio ficam no banco para a moderação.
                    $vanished = $this->ephemeralVanished($m);
                    $sealed = $this->ephemeralSealed($m, $viewerId);
                    // Conteúdo não trafega quando redigido, consumido ou selado.
                    $hidden = $m->isRedacted() || $vanished || $sealed;

                    return [
                        'id' => $m->id,
                        'sender_id' => $m->sender_id,
                        'created_at' => $m->created_at,
                        'locked' => $state['locked'],
                        // "Desfazer envio": redigida → "Mensagem apagada" nas duas pontas.
                        'redacted' => $m->isRedacted(),
                        // Efêmera (qualquer estado) — a UI decide selada/contando/expirada.
                        'ephemeral' => $m->isEphemeral(),
                        'sealed' => $sealed,
                        'vanished' => $vanished,
                        // Presente NÃO é redigível (ação de dinheiro). Redação vale texto e voz.
                        'can_redact' => ! $m->isRedacted()
                            && ! $vanished
                            && $m->gift_id === null
                            && $m->sender_id === $viewerId
                            && $m->created_at->copy()->addMinutes((int) config('chat.redact_window_minutes'))->isFuture(),
                        // Corpo só com leitura destravada e conteúdo não escondido. PPV
                        // não tem corpo de texto (o rótulo de sistema não vai à tela); a
                        // bolha é desenhada do bloco `ppv`.
                        'body' => (! $state['locked'] && ! $hidden && ! $m->isPpv()) ? $m->body : null,
                        // PPV (Onda 4): conteúdo travado do cofre. O bloco leva o preço, o
                        // estado (locked/unlocked/owner) e as URLs de preview borrado e da
                        // mídia (só quando o viewer pode ver). Escondido se redigido.
                        'ppv' => $this->ppvBlock($m, $viewer),
                        // Presente: sempre exposto (ação do membro, catálogo público); redigido → escondido.
                        'gift_slug' => $m->isRedacted() ? null : $m->gift?->slug,
                        'gift_name' => $m->isRedacted() ? null : $m->gift?->name,
                        // Voz: status/duração seguem o gate do corpo; selado/consumido → nada.
                        'audio_status' => $hidden ? null : $m->audio_status,
                        'audio_duration' => $hidden ? null : $m->audio_duration_seconds,
                        'audio_url' => (! $state['locked'] && ! $hidden && $m->audio_status === Message::AUDIO_READY)
                            ? route('chat.audio', [$conversation->id, $m->id])
                            : null,
                        // Responder story: miniatura só para a DONA, enquanto o story vive; escondido junto.
                        'reply_to_story' => (! $hidden && $m->reply_to_story_id) ? [
                            'thumb_url' => ($viewerIsPerformer && $m->replyToStory && ! $m->replyToStory->isExpired())
                                ? route('performer.stories.image', $m->reply_to_story_id)
                                : null,
                        ] : null,
                        // Confirmação de leitura só nas MINHAS mensagens e se o outro não desligou o perk.
                        'read_at' => ($showReadReceipt && $m->sender_id === $viewerId)
                            ? $m->read_at
                            : null,
                    ];
                });
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
                // Modo efêmero (Onda 2): estado atual do toggle. Qualquer um dos dois
                // participantes liga/desliga (a tela só é acessível a participante).
                'ephemeral' => (bool) $conversation->ephemeral,
                // Cabeçalho por lado: a performer vê o MEMBRO (alias + foto), o
                // membro vê a performer. O front escolhe por este flag.
                'viewer_is_performer' => $viewerIsPerformer,
                'performer' => [
                    'stage_name' => $conversation->performerProfile->stage_name,
                    'slug' => $conversation->performerProfile->slug,
                    // O id vai para o payload do compartilhamento. É o perfil
                    // PÚBLICO da performer, não identidade de membro — nada a
                    // ver com o FanAlias, que protege o outro lado.
                    'profile_id' => $conversation->performer_profile_id,
                    'avatar_url' => $this->performerAvatarUrl($conversation->performerProfile),
                ],
                // O OUTRO participante, só quando quem olha é a performer: FanAlias
                // (o nome exibido, M.13.10) + a foto do membro por token opaco. O
                // membro nunca recebe um bloco "member" (ele é o dono do lado dele).
                'member' => ($viewerIsPerformer && $conversation->member_id !== null)
                    ? [
                        'label' => MemberDisplayName::for($conversation->member?->nickname, $conversation->performerProfile->id, $conversation->member_id),
                        // O APELIDO isolado (ou null): habilita "denunciar apelido" no
                        // cabeçalho do chat (feat/nickname-report, Fase 4b-ui). Só há o
                        // que denunciar quando o membro escolheu um apelido — o alias
                        // de par não é denunciável.
                        'nickname' => MemberDisplayName::nickname($conversation->member?->nickname),
                        'avatar_url' => $conversation->member?->avatarUrl(),
                    ]
                    : null,
            ],
            'messages' => $messages,
            'teaser' => $teaser,
            'access' => $state,
            'photoSharing' => $this->photoSharingProps($request, $conversation, $state),
            'accessCost' => $this->creditPolicy->chatCost($request->user()),
            'balance' => $this->tokenService->balance($request->user()),
            // Timer efêmero (Onda 3): segundos que o conteúdo revelado fica visível
            // antes de sumir (contagem client-side). O servidor consome no reveal.
            'ephemeralSeconds' => (int) config('chat.ephemeral_reveal_seconds'),
            // PPV no chat (Onda 4): o cofre da performer para o seletor "mandar
            // conteúdo travado" (só quando quem olha é a performer dona). Limites de
            // preço para o front validar antes de enviar.
            'ppv' => [
                'can_send' => $viewerIsPerformer,
                'min_price' => (int) config('monetization.ppv.min_price'),
                'max_price' => (int) config('monetization.ppv.max_price'),
                'price_step' => (int) config('monetization.ppv.price_step'),
                'vault' => $viewerIsPerformer
                    ? $this->ppvVault($conversation->performerProfile)
                    : [],
            ],
        ]);
    }

    /**
     * PPV no chat (Onda 4): o bloco da bolha de conteúdo travado. `null` quando não é
     * PPV ou está redigido (a bolha vira "apagada"). A REGRA de acesso é do
     * ContentVisibilityService (dona única) — o controller só monta as URLs. A URL da
     * mídia SÓ sai quando o viewer pode ver (dona, grátis ou desbloqueado E performer
     * de pé); senão vai o preview BORRADO (irreversível), nunca os bytes reais.
     *
     * @return array<string, mixed>|null
     */
    private function ppvBlock(Message $m, User $viewer): ?array
    {
        if (! $m->isPpv() || $m->isRedacted()) {
            return null;
        }

        $content = $m->ppvContent;

        // Peça apagada (nullOnDelete) → bolha "indisponível", sem preço acionável.
        if ($content === null) {
            return ['available' => false];
        }

        $state = $this->contentVisibility->stateFor($viewer, $content);
        $canView = $this->contentVisibility->canView($viewer, $content);
        $isVideo = $content->isVideo();
        $isOwner = $state === 'owner';

        // URL da mídia real só quando o viewer PODE ver. A DONA serve pela rota DELA
        // (performer.content.image → pôster do vídeo / a foto): as rotas content.* são
        // `role:consumer` e barrariam a performer, deixando a própria bolha dela com a
        // imagem quebrada. O MEMBRO destravado serve pela rota de consumidor
        // (content.image/video), que já checa canView (404 se não pode).
        $mediaUrl = null;
        if ($canView) {
            $mediaUrl = $isOwner
                ? route('performer.content.image', $content->id)
                : ($isVideo ? route('content.video', $content->id) : route('content.image', $content->id));
        }

        return [
            'available' => true,
            'price' => (int) $m->ppv_price_tokens,
            'kind' => $isVideo ? 'video' : 'photo',
            // owner / unlocked / free / locked — do PRÓPRIO viewer (nunca superfície da
            // performer sobre o membro).
            'state' => $state,
            // Do lado da DONA a mídia é o pôster/foto (rota dela), não o player — o front
            // renderiza <img> quando is_owner, mesmo em vídeo.
            'is_owner' => $isOwner,
            // Prévia borrada irreversível (baixa resolução) — servível mesmo travado.
            'preview_url' => route('content.blur', $content->id),
            // Bytes reais só quando pode ver; senão null e a UI mostra o borrado.
            'media_url' => $mediaUrl,
        ];
    }

    /**
     * PPV no chat (Onda 4): o cofre da performer para o seletor de "mandar conteúdo
     * travado" — só peças PRONTAS dela, na ordem da vitrine. Miniatura pela porta da
     * própria performer (performer.content.image), nunca a URL de disco.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ppvVault(PerformerProfile $profile): array
    {
        return PerformerContent::query()
            ->where('performer_profile_id', $profile->id)
            ->ready()
            ->orderedForShowcase()
            ->limit(60)
            ->get()
            ->map(fn (PerformerContent $c) => [
                'id' => $c->id,
                'kind' => $c->isVideo() ? 'video' : 'photo',
                'access_level' => $c->access_level,
                'price_tokens' => (int) $c->price_tokens,
                'thumb_url' => route('performer.content.image', $c->id),
            ])
            ->values()
            ->all();
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
     * PPV no chat (Onda 4): a PERFORMER manda uma peça do cofre TRAVADA, com preço. A
     * policy `view` garante participante; o ChatService recusa quem não é a dona da
     * conversa, peça que não é dela/pronta, e preço inválido (422 com reason).
     */
    public function storePpv(StorePpvMessageRequest $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        $content = PerformerContent::find((int) $request->validated('content_id'));
        if ($content === null) {
            return response()->json([
                'reason' => ChatException::PPV_INVALID,
                'message' => 'Conteúdo não encontrado.',
            ], 422);
        }

        try {
            $message = $this->chatService->sendPpvMessage(
                $conversation,
                $request->user(),
                $content,
                (int) $request->validated('price_tokens'),
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
     * PPV no chat (Onda 4): o MEMBRO desbloqueia (paga) a peça travada. Débito +
     * crédito 80/20 + linha content_unlocks, atômicos e idempotentes no ChatService.
     * Devolve o bloco `ppv` já desbloqueado para o front trocar a bolha sem reload.
     */
    public function unlockPpv(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        try {
            $this->chatService->unlockPpvMessage($conversation, $request->user(), $message);
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], match ($e->reason) {
                ChatException::NOT_A_PARTICIPANT => 404,
                default => 422,
            });
        } catch (InsufficientBalanceException) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'Saldo de tokens insuficiente para desbloquear. Compre tokens na sua carteira.',
            ], 422);
        }

        // Re-monta o bloco PPV já desbloqueado (mesma fonte do show) para trocar a bolha
        // borrada pela mídia na hora.
        $message->refresh()->loadMissing('ppvContent.performerProfile.user');

        return response()->json([
            'message_id' => $message->id,
            'ppv' => $this->ppvBlock($message, $request->user()),
        ], 200);
    }

    /**
     * Mensagem de VOZ (feat/chat-voice-message). Mesma porta do storeMessage
     * (mesma policy, mesma cobrança/exceções), só que recebe um ARQUIVO. O corpo
     * do áudio é sanitizado por ffmpeg fora do request (nasce `processing`).
     */
    public function storeAudio(StoreChatAudioRequest $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        try {
            $message = $this->chatService->sendVoiceMessage(
                $conversation,
                $request->user(),
                $request->file('audio'),
            );
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        } catch (InsufficientBalanceException) {
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
     * Serve os bytes de uma mensagem de voz (feat/chat-voice-message). Autorização
     * por PARTICIPAÇÃO na conversa (a policy `view`) + PAYWALL (leitura destravada,
     * como o corpo do texto) + a mensagem tem que ser desta conversa e estar
     * `ready`. Sem URL assinada: acesso conferido a cada request. Content-Type FIXO
     * (nós produzimos o MP3); Range é tratado pelo BinaryFileResponse.
     */
    public function audio(Request $request, Conversation $conversation, Message $message): BinaryFileResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);
        abort_if($message->conversation_id !== $conversation->id, 404);
        // "Desfazer envio" (feat/chat-unsend-message): áudio redigido fica
        // indisponível aqui igual ao corpo de texto — senão o destinatário poderia
        // rebuscar os bytes pela URL direta depois do "apagar". A moderação ouve o
        // áudio retido por outro endpoint (moderacao.evidence.message-audio).
        abort_if($message->isRedacted(), 404);
        // Timer efêmero (Onda 3): áudio efêmero só é servível na JANELA certa —
        // senão os bytes seriam rebuscáveis pela URL direta antes do reveal (selado)
        // ou depois de consumido. Antes de revelar: 404. Depois: só enquanto durar a
        // janela de exibição (contagem + duração do áudio + folga). Ver
        // ephemeralAudioServable(). A moderação ouve o áudio retido por outro endpoint.
        abort_unless($this->ephemeralAudioServable($message, $request->user()->id), 404);
        abort_unless($message->audio_status === Message::AUDIO_READY && $message->audio_path, 404);

        // Paywall: com a leitura travada (grace/expired), o áudio fica indisponível
        // igual ao corpo do texto. A performer nunca cai aqui — `stateFor` (role-
        // aware) curto-circuita a dona da conversa como não-travada; usar o
        // `accessState` cru aqui travaria a performer (accessFor→null→'none'/locked),
        // deixando 404 todo áudio pronto que ela recebe ou envia.
        $state = $this->stateFor($request, $conversation);
        abort_if($state['locked'], 404);

        return response()->file($this->audioStore->absolutePath($message->audio_path), [
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="mensagem-de-voz.mp3"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * "Desfazer envio" (feat/chat-unsend-message): o remetente redige a própria
     * mensagem numa janela curta. Redação de EXIBIÇÃO — o conteúdo some da tela das
     * duas pontas, mas o original fica retido para a moderação. Sem estorno de
     * token. A policy `view` confere participação; o resto (é sua? dentro do
     * prazo?) é do ChatService.
     */
    public function destroyMessage(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        try {
            $this->chatService->redactMessage($conversation, $request->user(), $message);
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['redacted' => true], 200);
    }

    /**
     * Liga/desliga o modo efêmero da conversa (roadmap social, Onda 2). Qualquer um
     * dos dois participantes controla; a regra vive no ChatService. O corpo traz
     * `on` (bool). 404 para não-participante (mesma máscara do resto do chat).
     */
    public function toggleEphemeral(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        $on = $request->boolean('on');

        try {
            $this->chatService->setEphemeral($conversation, $request->user(), $on);
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ephemeral' => $on], 200);
    }

    /**
     * Revela uma mensagem efêmera para o DESTINATÁRIO (timer, Onda 3): grava
     * `revealed_at` (consumo imutável) e devolve o corpo/áudio UMA vez, para o cliente
     * mostrar por X seg antes de sumir. A partir daí a mensagem some das duas pontas
     * em qualquer load. Paywall conferido aqui (leitura destravada), como no áudio.
     */
    public function revealEphemeral(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_if($request->user()->cannot('view', $conversation), 404);

        // Paywall: com a leitura travada (grace/expired), não revela — igual ao corpo.
        // A performer nunca fica travada (stateFor curto-circuita a dona).
        $state = $this->stateFor($request, $conversation);
        abort_if($state['locked'], 404);

        try {
            $revealed = $this->chatService->revealEphemeral($conversation, $request->user(), $message);
        } catch (ChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], match ($e->reason) {
                ChatException::EPHEMERAL_GONE => 410, // já consumida → "expirada"
                default => 404,                       // não-revelável / não-participante (máscara)
            });
        }

        return response()->json([
            'body' => $revealed->body,
            'audio_status' => $revealed->audio_status,
            'audio_duration' => $revealed->audio_duration_seconds,
            'audio_url' => $revealed->audio_status === Message::AUDIO_READY
                ? route('chat.audio', [$conversation->id, $revealed->id])
                : null,
            'reply_to_story' => $revealed->reply_to_story_id ? ['label' => true] : null,
            // Segundos que o cliente mostra o conteúdo antes de esconder.
            'seconds' => (int) config('chat.ephemeral_reveal_seconds'),
        ], 200);
    }

    /**
     * Efêmera JÁ CONSUMIDA (timer, Onda 3) — some da exibição nas DUAS pontas?
     *
     * Revelar = consumir: uma vez `revealed_at` gravado (o destinatário tocou para
     * ver), a mensagem está gasta e some para todos em qualquer load seguinte. Só de
     * EXIBIÇÃO — `body`/áudio ficam no banco para a moderação. A contagem de X seg é
     * client-side; aqui o servidor só olha "foi revelada?".
     */
    private function ephemeralVanished(Message $message): bool
    {
        return $message->isEphemeral() && $message->isRevealed();
    }

    /**
     * Efêmera SELADA (timer, Onda 3) — "toque para ver", do lado do DESTINATÁRIO?
     *
     * É a efêmera ainda não revelada, vista por quem NÃO a enviou: o corpo/áudio não
     * trafega até o reveal (o remetente vê o próprio corpo até ser consumido, então
     * do lado dele nunca é selada).
     */
    private function ephemeralSealed(Message $message, int $viewerId): bool
    {
        return $message->isEphemeral()
            && ! $message->isRevealed()
            && (int) $message->sender_id !== $viewerId;
    }

    /**
     * Os BYTES do áudio efêmero podem ser servidos a este viewer AGORA (timer, Onda 3)?
     *
     * - Não-efêmera: sem janela aqui (o resto do gate do audio() decide).
     * - Remetente: ouve o próprio áudio até ser consumido (revelado pelo outro).
     * - Destinatário: só DEPOIS de revelar e só dentro da janela de exibição —
     *   max(contagem, duração do áudio) + folga de rede. Antes de revelar (selada) o
     *   byte não sai; depois da janela, consumida.
     */
    private function ephemeralAudioServable(Message $message, int $viewerId): bool
    {
        if (! $message->isEphemeral()) {
            return true;
        }

        if ((int) $message->sender_id === $viewerId) {
            return ! $message->isRevealed();
        }

        if (! $message->isRevealed()) {
            return false;
        }

        $window = max(
            (int) config('chat.ephemeral_reveal_seconds'),
            (int) $message->audio_duration_seconds,
        ) + 10; // folga de rede/playback

        return $message->revealed_at->copy()->addSeconds($window)->isFuture();
    }

    /**
     * O corpo desta mensagem deve ficar FORA do preview da listagem (index)?
     *
     * Redigida ("desfazer envio") e QUALQUER efêmera ficam fora: o preview de 60
     * chars da lista seria a porta dos fundos que vaza um corpo que não deve aparecer
     * no fio (efêmera selada nunca mostra corpo; consumida já sumiu; e o próprio corpo
     * efêmero do remetente não deve persistir na listagem). A lista mostra um rótulo
     * neutro no lugar.
     */
    private function previewHidden(Message $message): bool
    {
        return $message->isRedacted() || $message->isEphemeral();
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

    /**
     * URL assinada temporária do avatar da performer para o cabeçalho/lista do chat
     * (mesma rota e TTL do PerformerPublicResource e do toast). Assina pelo
     * profile_id, nunca pelo user_id. Null quando não há avatar → o front cai na
     * silhueta.
     */
    private function performerAvatarUrl(PerformerProfile $profile): ?string
    {
        if (! $profile->avatar_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $profile->id, 'type' => 'avatar'],
        );
    }
}
