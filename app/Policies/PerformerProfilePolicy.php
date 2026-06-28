<?php

namespace App\Policies;

use App\Models\PerformerProfile;
use App\Models\User;

class PerformerProfilePolicy
{
    public function view(User $user, PerformerProfile $profile): bool
    {
        return true;
    }

    public function update(User $user, PerformerProfile $profile): bool
    {
        return $user->role === 'performer'
            && $user->id === $profile->user_id;
    }
}
