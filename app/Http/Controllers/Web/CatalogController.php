<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogFilterRequest;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Services\FollowService;
use App\Services\PerformerCatalogService;
use App\Services\ProfileVisitService;
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

        $paginated = PerformerPublicResource::collection($performers)->response()->getData(true);
        $paginated['data'] = collect($paginated['data'])
            ->map(fn ($item) => array_merge($item, [
                'is_following' => $followingBySlug[$item['slug']] ?? false,
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
                'rate_public' => $profile->rate_public,
                'rate_private' => $profile->rate_private,
                'rate_camera' => $profile->rate_camera,
            ]
        );

        return Inertia::render('Catalog/Show', [
            'performer' => $performer,
            // Alvo da denúncia (ver PublicCatalogController::show). Toda a rota
            // já está atrás de auth, então não há caso de visitante aqui.
            'report' => ['type' => 'performer', 'id' => $profile->id],
        ]);
    }
}
