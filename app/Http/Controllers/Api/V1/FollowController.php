<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowResource;
use App\Models\Follow;
use App\Models\PerformerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowController extends Controller
{
    public function follow(Request $request, string $slug): JsonResponse
    {
        $profile = PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($request, $profile) {
            $follow = Follow::firstOrCreate([
                'user_id'              => $request->user()->id,
                'performer_profile_id' => $profile->id,
            ]);

            if ($follow->wasRecentlyCreated) {
                DB::table('performer_profiles')
                    ->where('id', $profile->id)
                    ->increment('followers_count');
            }
        });

        $profile->refresh();

        return (new FollowResource([
            'following'       => true,
            'followers_count' => $profile->followers_count,
        ]))->response();
    }

    public function unfollow(Request $request, string $slug): JsonResponse
    {
        $profile = PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($request, $profile) {
            $deleted = Follow::where('user_id', $request->user()->id)
                ->where('performer_profile_id', $profile->id)
                ->delete();

            if ($deleted > 0) {
                DB::table('performer_profiles')
                    ->where('id', $profile->id)
                    ->where('followers_count', '>', 0)
                    ->decrement('followers_count');
            }
        });

        $profile->refresh();

        return (new FollowResource([
            'following'       => false,
            'followers_count' => $profile->followers_count,
        ]))->response();
    }

    public function following(Request $request, string $slug): JsonResponse
    {
        $profile = PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();

        $isFollowing = Follow::where('user_id', $request->user()->id)
            ->where('performer_profile_id', $profile->id)
            ->exists();

        return (new FollowResource([
            'following'       => $isFollowing,
            'followers_count' => $profile->followers_count,
        ]))->response();
    }
}
