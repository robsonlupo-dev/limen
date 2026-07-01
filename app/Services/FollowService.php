<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class FollowService
{
    public function follow(User $user, PerformerProfile $profile): void
    {
        DB::transaction(function () use ($user, $profile) {
            try {
                $follow = Follow::firstOrCreate([
                    'user_id' => $user->id,
                    'performer_profile_id' => $profile->id,
                ]);

                if ($follow->wasRecentlyCreated) {
                    DB::table('performer_profiles')
                        ->where('id', $profile->id)
                        ->increment('followers_count');
                }
            } catch (UniqueConstraintViolationException) {
                // concurrent follow — unique constraint protected integrity; count unchanged
            }
        });
    }

    public function unfollow(User $user, PerformerProfile $profile): void
    {
        DB::transaction(function () use ($user, $profile) {
            $deleted = Follow::where('user_id', $user->id)
                ->where('performer_profile_id', $profile->id)
                ->delete();

            if ($deleted > 0) {
                DB::table('performer_profiles')
                    ->where('id', $profile->id)
                    ->where('followers_count', '>', 0)
                    ->decrement('followers_count');
            }
        });
    }

    public function isFollowing(User $user, PerformerProfile $profile): bool
    {
        return Follow::where('user_id', $user->id)
            ->where('performer_profile_id', $profile->id)
            ->exists();
    }
}
