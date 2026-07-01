<?php

namespace App\Services;

use App\Models\PerformerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PerformerCatalogService
{
    public function search(array $filters): LengthAwarePaginator
    {
        $query = PerformerProfile::query()->publicCatalog();

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['is_live'])) {
            $query->where('is_live', true);
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
