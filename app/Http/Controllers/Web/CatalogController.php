<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogFilterRequest;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Services\ChatAccessService;
use App\Services\ContentVisibilityService;
use App\Services\FavoriteService;
use App\Services\FollowService;
use App\Services\PerformerCatalogService;
use App\Services\ProfileVisitService;
use App\Services\SavedSearchService;
use App\Services\StoryVisibilityService;
use App\Services\TokenCreditPolicy;
use App\Support\PhotoGalleryPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function __construct(
        private PerformerCatalogService $catalogService,
        private FollowService $followService,
        private ProfileVisitService $profileVisits,
        private StoryVisibilityService $storyVisibility,
        private FavoriteService $favorites,
        private ChatAccessService $chatAccessService,
        private SavedSearchService $savedSearches,
        private TokenCreditPolicy $creditPolicy,
        private ContentVisibilityService $contentVisibility,
    ) {}

    public function index(CatalogFilterRequest $request): Response|RedirectResponse
    {
        $user = $request->user();

        // A performer não navega o catálogo de membros (é a vitrine onde ela é o
        // produto). Em vez de um 403, redireciona ao próprio painel — UX melhor
        // que uma página de erro (UAT fase 1, R3). Espelha o dashboardRoute do
        // AppLayout: quem ainda está em onboarding (não `active`) cai na tela de
        // onboarding, não no painel gateado por `performer-active`.
        if ($user->role === 'performer') {
            return redirect()->route(
                $user->status === 'active' ? 'performer.dashboard' : 'performer.onboarding',
            );
        }

        // Os demais não-membros (admin) seguem barrados: o catálogo é área do
        // membro. O corte saiu do `role:consumer` da rota para o controller só
        // para dar à performer o redirect acima — a regra de papel não mudou.
        abort_unless($user->role === 'consumer', 403);

        // `category` fica fora do CatalogFilterRequest: o mundo é do catálogo
        // autenticado (o público usa `mundo`), e as duas telas compartilham só
        // as facetas do Sprint 9.
        $request->validate([
            'category' => ['nullable', Rule::in(PerformerProfile::WORLDS)],
        ]);

        // The member browses within a single "world". Default to their chosen
        // preferred_world (fallback "mulheres"); an explicit ?category overrides.
        $defaultWorld = $request->user()->preferred_world ?? 'mulheres';
        $currentWorld = $request->input('category', $defaultWorld);

        $filters = array_merge($request->filters(), [
            'category' => $currentWorld,
            'sort' => $request->input('sort', 'rating_avg'),
        ]);

        $performers = $this->catalogService->search($filters);

        $profiles = collect($performers->items());
        $followingIds = Follow::where('user_id', $request->user()->id)
            ->whereIn('performer_profile_id', $profiles->pluck('id'))
            ->pluck('performer_profile_id')
            ->all();

        // Keyed by slug (unique, present on both sides) rather than positional
        // index, so reordering either collection can't cross-wire follow state.
        $followingBySlug = $profiles->mapWithKeys(fn ($profile) => [
            $profile->slug => in_array($profile->id, $followingIds, true),
        ]);

        // Pontinho de "story não visto" (Sprint 9C). Uma query para a página
        // inteira, no mesmo molde do `is_following` acima — por card seria N+1 na
        // tela mais visitada do produto. Quem decide o que este membro alcança é
        // o StoryVisibilityService, que é a dona da regra.
        $unseenStoryIds = $this->storyVisibility->profileIdsWithUnseenStories(
            $profiles->pluck('id')->all(),
            $request->user(),
        );

        // Chaveado por slug pela mesma razão do follow: índice posicional
        // cruzaria o estado errado se qualquer uma das coleções reordenasse.
        $unseenBySlug = $profiles->mapWithKeys(fn ($profile) => [
            $profile->slug => in_array($profile->id, $unseenStoryIds, true),
        ]);

        // Coração preenchido (Sprint 10). Uma query para a página inteira, mesmo
        // molde dos dois acima. É dado do MEMBRO e só dele: a performer não
        // recebe nada daqui, nem contador nem lista (ver FavoriteService).
        $favoritedIds = $this->favorites->favoritedProfileIds(
            $request->user(),
            $profiles->pluck('id')->all(),
        );

        $favoritedBySlug = $profiles->mapWithKeys(fn ($profile) => [
            $profile->slug => in_array($profile->id, $favoritedIds, true),
        ]);

        // Id da conversa JÁ ABERTA do membro com cada performer da página (uma
        // query, mesmo molde dos blocos acima — por card seria N+1). Chat é
        // interest-gated: só existe conversa se a performer mandou Interesse e o
        // membro desbloqueou (ou Círculo ativo). Serve o ícone "Conversar" do card
        // (fix/member-chat-click): COM conversa, o ícone abre `chat.show` direto;
        // SEM conversa, cai no perfil — o único lugar onde o chat pode começar. É
        // só o id (para o <Link>), NUNCA estado de acesso/paywall. É a conversa do
        // PRÓPRIO membro; `chat.show` reconfere `can view` (404), então o id não é
        // oráculo. Chaveado por slug como o resto.
        $conversationIdByProfileId = Conversation::where('member_id', $request->user()->id)
            ->whereIn('performer_profile_id', $profiles->pluck('id'))
            ->pluck('id', 'performer_profile_id');

        $conversationBySlug = $profiles->mapWithKeys(fn ($profile) => [
            $profile->slug => $conversationIdByProfileId[$profile->id] ?? null,
        ]);

        // Trilha "Agora" (redesign do catálogo): quem está AO VIVO agora no mundo
        // corrente, para o topo do catálogo. Independente do filtro/paginação — é
        // "quem está ao vivo agora", não a busca — mas no MESMO recorte público
        // (`publicCatalog`: ativa + verificada) e mundo (`inWorld`) que o catálogo,
        // então não expõe ninguém que o catálogo esconderia. Só quando a feature
        // está ligada: com o dark launch off ninguém está de fato ao vivo e o card
        // já não mostra "AO VIVO". Sem live → array vazio → a trilha não renderiza.
        // A parte de STORIES da trilha continua vindo do fetch de `stories.feed`
        // (fora do caminho crítico — ver nota no fim do método).
        $lives = [];
        if (config('features.live_enabled')) {
            $lives = PerformerProfile::query()
                ->publicCatalog()
                ->inWorld($currentWorld)
                ->where('is_live', true)
                ->limit(30)
                ->get()
                ->map(fn (PerformerProfile $profile) => [
                    'slug' => $profile->slug,
                    'stage_name' => $profile->stage_name,
                    // Foto pública, URL assinada por profile_id (nunca user_id) —
                    // mesma escolha do PerformerPublicResource e do feed de stories.
                    'avatar_url' => $profile->avatar_path
                        ? URL::temporarySignedRoute(
                            'performer.media',
                            now()->addMinutes(60),
                            ['profile_id' => $profile->id, 'type' => 'avatar'],
                        )
                        : null,
                ])
                ->values()
                ->all();
        }

        $paginated = PerformerPublicResource::collection($performers)->response()->getData(true);
        $paginated['data'] = collect($paginated['data'])
            ->map(fn ($item) => array_merge($item, [
                'is_following' => $followingBySlug[$item['slug']] ?? false,
                'has_unseen_stories' => $unseenBySlug[$item['slug']] ?? false,
                'is_favorited' => $favoritedBySlug[$item['slug']] ?? false,
                // null = sem conversa aberta → o ícone leva ao perfil (onde o
                // chat começa); com id → abre a conversa direto (fix/member-chat-click).
                'chat_conversation_id' => $conversationBySlug[$item['slug']] ?? null,
            ]))
            ->all();

        return Inertia::render('Catalog/Index', [
            'performers' => $paginated,
            // Trilha "Agora": lives ativas do mundo corrente (vazio com a feature
            // off). Os stories da trilha vêm do fetch de `stories.feed`.
            'lives' => $lives,
            // filterState() e não $filters: a tela precisa dos extremos do
            // slider resolvidos em número para renderizar os controles.
            'filters' => array_merge($request->filterState(), [
                'category' => $currentWorld,
                'sort' => $filters['sort'],
            ]),
            'currentWorld' => $currentWorld,
            'userWorld' => $request->user()->preferred_world,
            // Buscas salvas (Sprint 12): dado do MEMBRO, servido junto para o
            // painel de filtros sem um round-trip extra. Mesma fonte do endpoint
            // de listagem (SavedSearchService::listFor), que a tela recarrega após
            // salvar/apagar. `user_id` nunca sai (o service já mapeia campo a campo).
            'savedSearches' => $this->savedSearches->listFor($request->user()),
        ]);
        // NOTA: o carrossel de Stories (Sprint 13) NÃO vem como prop daqui de
        // propósito. `StoryVisibilityService::feedFor()` roda `canView` por story
        // (é O(stories) em queries), e servi-lo junto do catálogo quebraria a
        // garantia de "uma query para o pontinho, não uma por card"
        // (StoryCatalogTest). O carrossel busca `stories.feed` por fetch no
        // mount — um round-trip separado, fora do caminho crítico do catálogo.
    }

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        try {
            $profile = $this->catalogService->findBySlug($slug);
        } catch (ModelNotFoundException $e) {
            // Slug antigo de um rename → 301 para o atual, em vez de 404. Só
            // redireciona se o alvo ainda renderiza neste recorte (ver service).
            if ($current = $this->catalogService->currentSlugForPrevious($slug)) {
                return redirect()->route('catalog.show', $current, 301);
            }

            throw $e;
        }

        // Visita: no-op para quem tem Ghost Mode, e a resposta é idêntica nos
        // dois casos de propósito (ver ProfileVisitService::record).
        $this->profileVisits->record($request->user(), $profile);

        $performer = array_merge(
            (new PerformerPublicResource($profile))->resolve($request),
            [
                'tips_count' => $profile->tips_count,
                'is_following' => $this->followService->isFollowing($request->user(), $profile),
                // Bookmark privado — só o membro logado vê o próprio estado.
                // A performer que abrir o próprio perfil por aqui recebe false
                // e a tela não desenha o botão (ver Catalog/Show.vue).
                'is_favorited' => $this->favorites->isFavorited($request->user(), $profile),
                'rate_public' => $profile->rate_public,
                'rate_private' => $profile->rate_private,
                'rate_camera' => $profile->rate_camera,
                // Preço/min da chamada (feat/scheduled-call-v1): null = não aceita
                // chamadas → o botão "Agendar chamada" não aparece. É o preço DELA,
                // mostrado ao membro para decidir; nada sensível.
                'call_price_per_minute' => $profile->call_price_per_minute,
                // profile_id para o POST reservations.store ({profile}). O resource
                // público omite o `id` de propósito; o media já expõe o profile_id em
                // URL assinada, então isto não vaza nada novo.
                'profile_id' => $profile->id,
                // Intro de voz (feat/voice-intro): a URL do serving público SÓ
                // quando há intro APROVADA — null esconde o botão de play. Fica
                // fora do resource (que serve a listagem) para o card não carregar
                // a URL; aqui é a tela de perfil, que precisa dela para tocar.
                'voice_intro_url' => $profile->hasApprovedVoiceIntro()
                    ? route('voice-intro.audio', $profile)
                    : null,
            ]
        );

        return Inertia::render('Catalog/Show', [
            'performer' => $performer,
            // Stories vivos dela (Sprint 9C). Cada item já vem com `locked` e, se
            // aberto, a URL do serving autenticado — story fechado NÃO recebe URL
            // (ver profileStripFor: blur em CSS não é paywall).
            'stories' => $this->storyVisibility->profileStripFor($profile, $request->user()),
            // Galeria de fotos (Sprint 10) para o carrossel. Conteúdo público,
            // então cada item traz a URL do serving público direto — sem paywall,
            // ao contrário dos stories. Avatar/cover NÃO entram aqui: são
            // separados (o resource os expõe em avatar_url/cover_url).
            // Viewer passado: foto privada sem grant vem `locked` e sem URL
            // (Sprint 13). A performer que abrir o próprio perfil por aqui vê as
            // dela (o service reconhece a dona).
            'photos' => PhotoGalleryPresenter::forProfile($profile, $request->user()),
            // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Só os níveis que
            // o TIER do espectador alcança APARECEM — o Free vê só o Aberto (pago
            // para desbloquear), nunca Premium/Exclusivo/FC Only. Cada item já vem
            // com locked/price/image_url resolvido pelo ContentVisibilityService
            // (dona única do paywall) — bloqueado NÃO recebe URL de bytes.
            'contents' => $this->contentVisibility->galleryFor($request->user(), $profile),
            // Alvo da denúncia (ver PublicCatalogController::show). Toda a rota
            // já está atrás de auth, então não há caso de visitante aqui.
            'report' => ['type' => 'performer', 'id' => $profile->id],
            // Estado do chat para ESTE espectador — só para o CTA "Iniciar
            // conversa" do badge de disponibilidade (Sprint 11). Chat é
            // interest-gated: só há conversa se a performer mandou Interesse e o
            // membro desbloqueou; não dá para iniciar chat frio daqui. Null
            // (performer/admin, ou membro sem conversa) → o badge sai sem CTA.
            // Mesma forma do PublicCatalogController::chatStateFor.
            'chat' => $this->chatStateFor($request, $profile->id),
        ]);
    }

    /**
     * Estado do chat do espectador logado com esta performer, ou null. Gêmeo do
     * PublicCatalogController::chatStateFor — só membro (consumer) com conversa
     * JÁ ABERTA vê algo. `can_access` = pode enviar (Círculo ativo ou janela
     * paga em dia).
     *
     * @return array{conversation_id:int,state:string,can_access:bool,cost:int}|null
     */
    private function chatStateFor(Request $request, int $performerProfileId): ?array
    {
        $user = $request->user();

        if (! $user || $user->role !== 'consumer') {
            return null;
        }

        $conversation = Conversation::where('member_id', $user->id)
            ->where('performer_profile_id', $performerProfileId)
            ->first();

        if (! $conversation) {
            return null;
        }

        $state = $this->chatAccessService->accessState($conversation, $user);

        return [
            'conversation_id' => $conversation->id,
            'state' => $state['state'],
            'can_access' => $state['can_send'],
            // Custo por tier do próprio membro (M.13.1): 2 ou 1 token.
            'cost' => $this->creditPolicy->chatCost($user),
        ];
    }
}
