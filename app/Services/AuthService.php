<?php

namespace App\Services;

use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function registerConsumer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'birthdate' => $data['birthdate'],
                'lgpd_consent_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);
            $user->role = 'consumer';
            $user->status = 'active';
            // preferred_world is intentionally kept out of $fillable and set
            // explicitly here to avoid mass-assignment of privileged fields.
            $user->preferred_world = $data['preferred_world'] ?? null;
            $user->save();

            TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);

            event(new Registered($user));

            return $user;
        });
    }

    public function registerPerformer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'birthdate' => $data['birthdate'],
                'lgpd_consent_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);
            $user->role = 'performer';
            $user->status = 'pending';
            $user->save();

            $user->performerProfile()->create([
                'stage_name' => $data['stage_name'],
                'category' => $data['category'] ?? 'mulheres',
            ]);

            IdentityVerification::create([
                'user_id' => $user->id,
                'document_type' => 'cpf',
                'status' => 'pending',
            ]);

            TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);

            event(new Registered($user));

            return $user;
        });
    }

    public function attemptLogin(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if ($user->status === 'suspended' || $user->status === 'banned') {
            return null;
        }

        return $user;
    }
}
