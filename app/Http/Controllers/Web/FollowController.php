<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\FollowService;
use App\Services\PerformerCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function __construct(
        private PerformerCatalogService $catalogService,
        private FollowService $followService,
    ) {}

    public function store(Request $request, string $slug): RedirectResponse
    {
        $profile = $this->catalogService->findBySlug($slug);

        if ($profile->user_id === $request->user()->id) {
            return back()->with('error', 'Você não pode seguir a si mesmo.');
        }

        $this->followService->follow($request->user(), $profile);

        return back()->with('success', "Você está seguindo {$profile->stage_name}.");
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $profile = $this->catalogService->findBySlug($slug);

        $this->followService->unfollow($request->user(), $profile);

        return back()->with('success', "Você deixou de seguir {$profile->stage_name}.");
    }
}
