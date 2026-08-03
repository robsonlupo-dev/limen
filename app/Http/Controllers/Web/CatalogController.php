<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogFilterRequest;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Services\ChatAccessService;
use App\Services\FavoriteService;
use App\Services\FollowService;
use App\Services\PerformerCatalogService;
use App\Services\ProfileVisitService;
use App\Services\SavedSearchService;
use App\Services\StoryVisibilityService;
use App\Support\PhotoGalleryPresenter;
use Illuminate\Http\Request;
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
    ) {}

    public function index(CatalogFilterRequest $request): Response
    {
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

        $paginated = PerformerPublicResource::collection($performers)->response()->getData(true);
        $paginated['data'] = collect($paginated['data'])
            ->map(fn ($item) => array_merge($item, [
                'is_following' => $followingBySlug[$item['slug']] ?? false,
                'has_unseen_stories' => $unseenBySlug[$item['slug']] ?? false,
                'is_favorited' => $favoritedBySlug[$item['slug']] ?? false,
            ]))
            ->all();

        return Inertia::render('Catalog/Index', [
            'performers' => $paginated,
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
    }

    public function show(Request $request, string $slug): Response
    {
        $profile = $this->catalogService->findBySlug($slug);

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
            'cost' => (int) config('chat.access_cost'),
        ];
    }
}
