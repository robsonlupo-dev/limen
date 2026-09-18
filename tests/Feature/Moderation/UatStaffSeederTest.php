<?php

use App\Models\User;
use Database\Seeders\UatSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Guarda da correção de DADOS do fix/rbac-admin-e-moderador.
 *
 * A raiz do 403 relatado NÃO era o middleware (que já aceitava moderator+admin),
 * e sim as contas de UAT: `moderador@uat.limen.test` existia como admin/pending
 * (criada à mão) e faltava um seed de moderador; além disso o `firstOrCreate`
 * DESCARTA `role`/`status` (fora do $fillable), então re-semear produziria
 * consumer/pending. Este teste trava as duas contas no papel certo e prova que
 * o seed é AUTO-CORRETIVO sobre uma linha pré-existente errada.
 */
it('seeds admin and moderator with the right role, active and able to log in', function () {
    $this->seed(UatSeeder::class);

    $admin = User::where('email', 'admin@uat.limen.test')->first();
    $moderator = User::where('email', 'moderador@uat.limen.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe('admin')
        ->and($admin->status)->toBe('active')
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Password1', $admin->password))->toBeTrue();

    expect($moderator)->not->toBeNull()
        ->and($moderator->role)->toBe('moderator')
        ->and($moderator->status)->toBe('active')
        ->and($moderator->isModerator())->toBeTrue()
        ->and($moderator->isAdmin())->toBeFalse()
        ->and($moderator->canModerate())->toBeTrue()
        ->and($moderator->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Password1', $moderator->password))->toBeTrue();
});

it('heals a pre-existing mis-roled moderator account (the live 403 case)', function () {
    // Reproduz a linha viva: moderador criado à mão como admin/pending.
    User::factory()->create([
        'email' => 'moderador@uat.limen.test',
        'role' => 'admin',
        'status' => 'pending',
    ]);

    $this->seed(UatSeeder::class);

    $moderator = User::where('email', 'moderador@uat.limen.test')->first();

    expect($moderator->role)->toBe('moderator')
        ->and($moderator->status)->toBe('active');

    // Uma única linha por e-mail — o seed corrigiu, não duplicou.
    expect(User::where('email', 'moderador@uat.limen.test')->count())->toBe(1);
});
