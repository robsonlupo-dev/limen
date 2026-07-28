<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\PerformerProfile;
use App\Services\PerformerCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PerformerCatalogController extends Controller
{
    public function __construct(private PerformerCatalogService $catalogService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Mesmas regras e mesma aplicação das duas portas web — a terceira
        // cópia era o risco real desta feature. Ver
        // PerformerCatalogService::filterRules().
        $validated = $request->validate(PerformerCatalogService::filterRules());

        $query = PerformerProfile::query()->publicCatalog();

        // Multi-worlds: matches the `worlds` list with a category fallback.
        // Allowlist antes da query (paridade com o catálogo web) — mundo
        // desconhecido simplesmente não filtra, em vez de vazar via query crua.
        if ($request->filled('category') && in_array($request->input('category'), PerformerProfile::WORLDS, true)) {
            $query->inWorld($request->input('category'));
        }

        // `work_mode` é só da API (nenhuma tela web o oferece) e por isso fica
        // aqui, fora do applyFilters compartilhado.
        if ($request->filled('work_mode')) {
            $query->whereJsonContains('work_modes', $request->input('work_mode'));
        }

        $this->catalogService->applyFilters($query, array_merge($validated, [
            'is_live' => $request->boolean('is_live'),
            'has_photo' => $request->boolean('has_photo'),
        ]));

        match ($request->input('sort', 'rating_avg')) {
            'followers_count' => $query->orderByDesc('followers_count'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('rating_avg'),
        };

        return PerformerPublicResource::collection($query->paginate(20));
    }

    public function show(string $slug): PerformerPublicResource
    {
        $profile = PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();

        return new PerformerPublicResource($profile);
    }
}
