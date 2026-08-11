<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogFilterRequest;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Conversation;
use App\Services\ChatAccessService;
use App\Services\ContentVisibilityService;
use App\Services\FavoriteService;
use App\Services\PerformerCatalogService;
use App\Services\ProfileVisitService;
use App\Services\StoryVisibilityService;
use App\Services\TokenCreditPolicy;
use App\Support\PhotoGalleryPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unauthenticated, SEO-friendly performer catalog served at /performers.
 *
 * This is a public marketing surface: it only ever renders active + verified
 * performers (never pending), exposes no PII, and every "interact" action
 * (follow, tip) routes a visitor to signup rather than acting here. The
 * authenticated experience lives in CatalogController (/catalogo).
 */
class PublicCatalogController extends Controller
{
    public function __construct(
        private PerformerCatalogService $catalogService,
        private ChatAccessService $chatAccessService,
        private ProfileVisitService $profileVisits,
        private StoryVisibilityService $storyVisibility,
        private FavoriteService $favorites,
        private TokenCreditPolicy $creditPolicy,
        private ContentVisibilityService $contentVisibility,
    ) {}

    public function index(CatalogFilterRequest $request): Response
    {
        // `mundo` (e não `category`) é o nome do parâmetro nesta porta desde
        // sempre, e /performers?mundo=mulheres é URL pública já indexada —
        // renomear quebraria links vivos. As facetas do Sprint 9 usam os mesmos
        // nomes das duas portas; só o mundo diverge, por compatibilidade.
        $validated = $request->validate([
            'mundo' => 'nullable|in:mulheres,homens,casais,trans',
        ]);

        $world = $validated['mundo'] ?? null;

        $performers = $this->catalogService->publicSearch($world, $request->filters());

        // PerformerPublicResource is already PII-free (slug, stage_name, bio,
        // category, work_modes, is_live, ratings, followers_count, signed media
        // URLs). No follow state on the public surface — that requires auth.
        $paginated = PerformerPublicResource::collection($performers)
            ->response()
            ->getData(true);

        // Pontinho de "story não visto" (Sprint 9C). Esta porta é pública, mas o
        // membro logado também chega aqui por link direto ou busca — e para ele o
        // indicador tem de funcionar igual ao /catalogo. Visitante deslogado
        // recebe `false` em todos os cards sem tocar o banco (o service devolve
        // lista vazia): não há "não visto" para quem não tem conta.
        //
        // Uma query para a página inteira, nunca por card. É o único estado de
        // membro nesta listagem — follow continua fora, porque seguir exige auth.
        $profiles = collect($performers->items());
        $unseenStoryIds = $this->storyVisibility->profileIdsWithUnseenStories(
            $profiles->pluck('id')->all(),
            $request->user(),
        );
        $unseenBySlug = $profiles->mapWithKeys(fn ($profile) => [
            $profile->slug => in_array($profile->id, $unseenStoryIds, true),
        ]);

        $paginated['data'] = collect($paginated['data'])
            ->map(fn ($item) => array_merge($item, [
                'has_unseen_stories' => $unseenBySlug[$item['slug']] ?? false,
            ]))
            ->all();

        return Inertia::render('Performers/Index', [
            'performers' => $paginated,
            'filters' => array_merge($request->filterState(), ['mundo' => $world]),
            'meta' => [
                'title' => 'Performers verificadas · Limen',
                'description' => 'Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total. Crie sua conta para interagir.',
                'og_title' => 'Performers verificadas · Limen',
                'og_description' => 'Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total.',
                'og_type' => 'website',
            ],
        ]);
    }

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        try {
            $profile = $this->catalogService->findPublicBySlug($slug);
        } catch (ModelNotFoundException $e) {
            // Slug antigo de um rename → 301 para o atual (SEO + links vivos), em
            // vez de 404. Só redireciona se o alvo ainda é público (ver service).
            if ($current = $this->catalogService->currentPublicSlugForPrevious($slug)) {
                return redirect()->route('performers.public.show', $current, 301);
            }

            throw $e;
        }

        // Esta rota é pública, mas o membro logado também chega aqui (link
        // direto, busca). Visitante anônimo não gera visita — não há quem
        // mostrar — e quem tem Ghost Mode também não. Ver ProfileVisitService.
        $this->profileVisits->record($request->user(), $profile);

        $performer = (new PerformerPublicResource($profile))->resolve($request);

        // Intro de voz (feat/voice-intro): a URL do serving público SÓ quando há
        // intro APROVADA — null esconde o botão de play. Fora do resource (que
        // serve a listagem), como no catálogo autenticado. Público toca sem login.
        $performer['voice_intro_url'] = $profile->hasApprovedVoiceIntro()
            ? route('voice-intro.audio', $profile)
            : null;

        $stageName = $profile->stage_name;
        $description = $profile->bio
            ? str($profile->bio)->stripTags()->limit(155)->value()
            : "{$stageName} — performer verificada no Limen. Crie sua conta para interagir.";

        return Inertia::render('Performers/Show', [
            'performer' => $performer,
            // Stories vivos dela (Sprint 9C). Para o visitante deslogado vêm
            // todos `locked` e sem URL — ele vê que existe conteúdo e a tela leva
            // ao cadastro, mesmo caminho de toda ação desta página. Story fechado
            // NUNCA recebe URL: blur em CSS não é paywall (ver profileStripFor).
            'stories' => $this->storyVisibility->profileStripFor($profile, $request->user()),
            // Galeria de fotos (Sprint 10) para o carrossel. Conteúdo público —
            // cada item traz a URL do serving público direto, sem paywall e sem
            // depender de login (o visitante deslogado vê as fotos, é vitrine).
            // Avatar/cover NÃO entram aqui: são separados.
            // Viewer passado: foto privada sem grant vem `locked` e sem URL
            // (Sprint 13). Visitante deslogado (`null`) vê toda privada bloqueada.
            'photos' => PhotoGalleryPresenter::forProfile($profile, $request->user()),
            // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Só os níveis que
            // o TIER do espectador alcança APARECEM. Visitante deslogado / membro
            // Free veem só o Aberto (bloqueado, sem URL de bytes — o CTA leva ao
            // cadastro/desbloqueio). Mesma fonte do serving (ContentVisibilityService).
            'contents' => $this->contentVisibility->galleryFor($request->user(), $profile),
            // Estado do chat para ESTE espectador. Chat é interest-gated: só há
            // conversa se a performer mandou Interesse e o membro desbloqueou —
            // não dá para iniciar chat frio daqui. Null (guest, performer/admin,
            // ou membro sem conversa) → a página não mostra botão de chat.
            'chat' => $this->chatStateFor($request, $profile->id),
            // Alvo da denúncia. Só para quem está logado — POST /reportar exige
            // auth, e oferecer o botão a um visitante levaria a um 401 mudo.
            // Vai numa prop própria porque PerformerPublicResource não expõe o
            // id do perfil, e não é para passar a expor: aqui é uma tela só,
            // atrás de auth, enquanto o resource também serve a listagem pública.
            'report' => $request->user()
                ? ['type' => 'performer', 'id' => $profile->id]
                : null,
            // Favorito (Sprint 10) — bookmark PRIVADO, e por isso uma prop
            // própria em vez de um campo no PerformerPublicResource: aquele
            // resource também serve a LISTAGEM pública, e um `is_favorited`
            // dentro dele passaria a ser calculado em toda superfície que o usa,
            // inclusive as que a performer enxerga. Aqui é uma tela só.
            //
            // Null para quem não é membro (visitante, performer, admin): só
            // `role:consumer` alcança a rota do toggle, e oferecer o coração a
            // quem levaria 403 seria um botão morto. Mesmo corte do `canTip`.
            'favorite' => $request->user()?->role === 'consumer'
                ? ['saved' => $this->favorites->isFavorited($request->user(), $profile)]
                : null,
            'meta' => [
                'title' => "{$stageName} · Limen",
                'description' => $description,
                'og_type' => 'profile',
            ],
        ]);
    }

    /**
     * Estado do chat do espectador logado com esta performer, ou null.
     *
     * Só membro (consumer) com uma conversa JÁ ABERTA vê algo. `can_access` =
     * pode enviar (Círculo ativo ou janela paga em dia) → a UI vira link para o
     * chat; false → a UI oferece o modal de acesso (50 tokens / 30 dias).
     *
     * @return array{conversation_id:int,state:string,can_access:bool}|null
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
