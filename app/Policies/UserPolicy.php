<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->role === 'admin';
    }

    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->role === 'admin';
    }

    public function delete(User $user, User $target): bool
    {
        return $user->role === 'admin';
    }
}
