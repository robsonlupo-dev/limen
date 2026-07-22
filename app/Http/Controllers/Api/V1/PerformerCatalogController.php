<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\PerformerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PerformerCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PerformerProfile::query()->publicCatalog();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('work_mode')) {
            $query->whereJsonContains('work_modes', $request->input('work_mode'));
        }

        if ($request->boolean('is_live')) {
            $query->where('is_live', true);
        }

        if ($request->filled('search')) {
            $request->validate(['search' => 'string|max:100']);
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('stage_name', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%");
            });
        }

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
