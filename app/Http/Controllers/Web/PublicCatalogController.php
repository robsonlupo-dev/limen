<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
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
            'meta' => [
                'title' => "{$stageName} · Limen",
                'description' => $description,
                'og_type' => 'profile',
            ],
        ]);
    }
}
