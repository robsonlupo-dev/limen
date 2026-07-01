<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Follow;
use App\Services\FollowService;
use App\Services\PerformerCatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function __construct(
        private PerformerCatalogService $catalogService,
        private FollowService $followService,
    ) {}

    public function index(Request $request): Response
    {
        $request->validate([
            'category' => 'nullable|in:mulheres,homens,casais,trans,gls,swing',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:rating_avg,followers_count,newest',
        ]);

        $filters = [
            'category' => $request->input('category'),
            'is_live' => $request->boolean('is_live'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', 'rating_avg'),
        ];

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
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $profile = $this->catalogService->findBySlug($slug);

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
        ]);
    }
}
