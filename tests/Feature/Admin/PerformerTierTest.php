<?php

use App\Models\AuditLog;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Grant admin de tier (verificada/select/maison). Os campos tier,
 * tier_granted_at e tier_granted_by ficam FORA do $fillable de propósito
 * (mesmo padrão do discrete_mode): escrita só via forceFill() no endpoint
 * admin dedicado. Helpers com prefixo tier* para o arquivo rodar isolado.
 */
function tierAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

function tierProfile(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

it('lets an admin grant each tier', function (string $tier) {
    $admin = tierAdmin();
    $profile = tierProfile();

    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => $tier])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($profile->fresh()->tier)->toBe($tier);
})->with(PerformerProfile::TIERS);

it('records who granted the tier and when', function () {
    $admin = tierAdmin();
    $profile = tierProfile();

    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'select']);

    $profile->refresh();

    expect($profile->tier_granted_by)->toBe($admin->id)
        ->and($profile->tier_granted_at)->not->toBeNull()
        ->and($profile->tier_granted_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('writes an audit log entry for the grant', function () {
    $admin = tierAdmin();
    $profile = tierProfile();

    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'maison']);

    $log = AuditLog::where('action', 'performer.tier_granted')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->metadata['tier'])->toBe('maison')
        ->and($log->metadata['previous_tier'])->toBeNull();
});

it('denies the grant to non-admins', function () {
    $profile = tierProfile();
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'select'])
        ->assertForbidden();

    // A própria performer também não se autopromove.
    $this->actingAs($profile->user)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'maison'])
        ->assertForbidden();

    expect($profile->fresh()->tier)->toBeNull();
});

it('rejects an invalid tier with a validation error', function () {
    $admin = tierAdmin();
    $profile = tierProfile();

    // Rota web: exceção não vira JSON fora de api/* (bootstrap/app.php),
    // então validação falha é redirect + erros na sessão, não 422.
    $this->actingAs($admin)
        ->from(route('admin.reports'))
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'diamante'])
        ->assertRedirect(route('admin.reports'))
        ->assertSessionHasErrors('tier');

    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), [])
        ->assertSessionHasErrors('tier');

    expect($profile->fresh()->tier)->toBeNull();
});

it('short-circuits a re-grant of the same tier keeping the original grant date', function () {
    $admin = tierAdmin();
    $profile = tierProfile();

    // Grant original, ontem.
    $this->travelTo(now()->subDay()->startOfSecond());
    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'select'])
        ->assertSessionHas('success');
    $this->travelBack();

    $originalGrantedAt = $profile->fresh()->tier_granted_at;

    // Re-grant do MESMO tier: flash 'info', nada regravado, nada auditado.
    $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'select'])
        ->assertRedirect()
        ->assertSessionHas('info')
        ->assertSessionMissing('success');

    expect($profile->fresh()->tier_granted_at->equalTo($originalGrantedAt))->toBeTrue()
        ->and(AuditLog::where('action', 'performer.tier_granted')->count())->toBe(1);
});

it('rolls back the grant when the audit write fails', function () {
    $admin = tierAdmin();
    $profile = tierProfile();

    // Derruba o INSERT do audit no caminho real (Audit::log → AuditLog::create),
    // dentro da transação do controller.
    AuditLog::creating(function (): void {
        throw new RuntimeException('audit store is down');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'maison'])
    )->toThrow(RuntimeException::class);

    // Rollback: mudança de privilégio sem trilha não pode persistir.
    $profile->refresh();

    expect($profile->tier)->toBeNull()
        ->and($profile->tier_granted_at)->toBeNull()
        ->and($profile->tier_granted_by)->toBeNull()
        ->and(AuditLog::where('action', 'performer.tier_granted')->count())->toBe(0);
});

it('keeps tier fields out of mass assignment', function () {
    $profile = tierProfile();

    // fill() com dado de request cru não pode alcançar os campos de tier.
    $profile->fill([
        'tier' => 'maison',
        'tier_granted_by' => 999,
        'bio' => 'bio nova',
    ]);

    expect($profile->tier)->toBeNull()
        ->and($profile->tier_granted_by)->toBeNull()
        ->and($profile->bio)->toBe('bio nova')
        ->and($profile->getFillable())->not->toContain('tier')
        ->and($profile->getFillable())->not->toContain('tier_granted_by')
        ->and($profile->getFillable())->not->toContain('tier_granted_at');
});
