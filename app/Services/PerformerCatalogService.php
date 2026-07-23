<?php

namespace App\Services;

use App\Models\PerformerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PerformerCatalogService
{
    /**
     * The four public "worlds" surfaced on the catalog — the single source of
     * truth is PerformerProfile::WORLDS. The whereIn stays as a defensive guard
     * so a card never renders a world the UI has no label/icon for.
     */
    public const PUBLIC_WORLDS = PerformerProfile::WORLDS;

    /**
     * Public (no-auth) catalog listing. Unlike search(), it does not force a
     * single world: with no filter it shows every public world; an optional
     * $world narrows to one. Only active + verified performers, never pending.
     */
    public function publicSearch(?string $world = null): LengthAwarePaginator
    {
        $query = PerformerProfile::query()
            ->publicCatalog()
            ->whereIn('category', self::PUBLIC_WORLDS);

        if ($world !== null && in_array($world, self::PUBLIC_WORLDS, true)) {
            // Multi-worlds: matches the `worlds` list, with a category fallback
            // for rows not yet migrated. See PerformerProfile::scopeInWorld.
            $query->inWorld($world);
        }

        return $query
            ->orderByDesc('followers_count')
            ->orderByDesc('rating_avg')
            ->paginate(24)
            ->withQueryString();
    }

    /**
     * Resolve a single public profile by slug, scoped so pending/unverified
     * performers 404 even when the slug is known.
     */
    public function findPublicBySlug(string $slug): PerformerProfile
    {
        return PerformerProfile::publicCatalog()
            ->whereIn('category', self::PUBLIC_WORLDS)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $query = PerformerProfile::query()->publicCatalog();

        // Guard alinhado ao publicSearch(): só filtra por um mundo conhecido.
        // O único chamador (CatalogController) já valida com Rule::in, mas o
        // guard aqui impede que o próximo chamador reabra a assimetria.
        if (! empty($filters['category']) && in_array($filters['category'], self::PUBLIC_WORLDS, true)) {
            $query->inWorld($filters['category']);
        }

        if (! empty($filters['is_live'])) {
            $query->where('is_live', true);
        }

        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('stage_name', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%");
            });
        }

        match ($filters['sort'] ?? 'rating_avg') {
            'followers_count' => $query->orderByDesc('followers_count'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('rating_avg'),
        };

        return $query->paginate(20)->withQueryString();
    }

    public function findBySlug(string $slug): PerformerProfile
    {
        return PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();
    }
}
