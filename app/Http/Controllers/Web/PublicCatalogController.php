<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Conversation;
use App\Services\ChatAccessService;
use App\Services\PerformerCatalogService;
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
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'mundo' => 'nullable|in:mulheres,homens,casais,trans',
        ]);

        $world = $validated['mundo'] ?? null;

        $performers = $this->catalogService->publicSearch($world);

        // PerformerPublicResource is already PII-free (slug, stage_name, bio,
        // category, work_modes, is_live, ratings, followers_count, signed media
        // URLs). No follow state on the public surface — that requires auth.
        $paginated = PerformerPublicResource::collection($performers)
            ->response()
            ->getData(true);

        return Inertia::render('Performers/Index', [
            'performers' => $paginated,
            'filters' => ['mundo' => $world],
            'meta' => [
                'title' => 'Performers verificadas · Limen',
                'description' => 'Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total. Crie sua conta para interagir.',
                'og_title' => 'Performers verificadas · Limen',
                'og_description' => 'Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total.',
                'og_type' => 'website',
            ],
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $profile = $this->catalogService->findPublicBySlug($slug);

        $performer = (new PerformerPublicResource($profile))->resolve($request);

        $stageName = $profile->stage_name;
        $description = $profile->bio
            ? str($profile->bio)->stripTags()->limit(155)->value()
            : "{$stageName} — performer verificada no Limen. Crie sua conta para interagir.";

        return Inertia::render('Performers/Show', [
            'performer' => $performer,
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
            'cost' => (int) config('chat.access_cost'),
        ];
    }
}
