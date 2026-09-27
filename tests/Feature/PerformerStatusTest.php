<?php

use App\Models\PerformerProfile;
use App\Models\PerformerStatus;
use App\Models\User;
use App\Services\PerformerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Status do dia da performer (roadmap social, Onda 1a). Helpers prefixo pst*.
 */
function pstPerformer(string $stageName = 'Ana', string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'bio' => 'Bio',
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

// ── Serviço ────────────────────────────────────────────────────────────────

it('define o status com expires_at derivado do config', function () {
    config()->set('stories.status.ttl_hours', 24);
    $profile = pstPerformer();

    $status = app(PerformerStatusService::class)->set($profile, ['body' => 'Online agora']);

    expect($status->body)->toBe('Online agora')
        ->and($status->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
});

it('é upsert: um status por performer', function () {
    $profile = pstPerformer();
    $svc = app(PerformerStatusService::class);

    $svc->set($profile, ['body' => 'Primeiro']);
    $svc->set($profile, ['body' => 'Segundo']);

    expect(PerformerStatus::where('performer_profile_id', $profile->id)->count())->toBe(1)
        ->and($svc->currentFor($profile)->body)->toBe('Segundo');
});

it('descarta o rótulo da contagem quando não há alvo', function () {
    $profile = pstPerformer();
    $status = app(PerformerStatusService::class)->set($profile, [
        'body' => 'Sem contagem',
        'countdown_label' => 'Live',
    ]);

    expect($status->countdown_at)->toBeNull()->and($status->countdown_label)->toBeNull();
});

it('não retorna status expirado (expira na leitura)', function () {
    $profile = pstPerformer();
    $status = app(PerformerStatusService::class)->set($profile, ['body' => 'Vencido']);
    $status->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(app(PerformerStatusService::class)->currentFor($profile))->toBeNull()
        ->and($profile->fresh()->activeStatus)->toBeNull();
});

// ── HTTP (performer) ─────────────────────────────────────────────────────────

it('a performer ativa define e limpa o próprio status', function () {
    $profile = pstPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.status.save'), ['body' => 'Chamando hoje'])
        ->assertRedirect();
    expect(app(PerformerStatusService::class)->currentFor($profile)?->body)->toBe('Chamando hoje');

    $this->actingAs($profile->user)
        ->delete(route('performer.status.clear'))
        ->assertRedirect();
    expect(app(PerformerStatusService::class)->currentFor($profile))->toBeNull();
});

it('aceita contagem regressiva futura com rótulo', function () {
    $profile = pstPerformer();
    $when = now()->addHours(3)->toIso8601String();

    $this->actingAs($profile->user)
        ->post(route('performer.status.save'), [
            'body' => 'Live hoje',
            'countdown_at' => $when,
            'countdown_label' => 'Live começa',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $status = app(PerformerStatusService::class)->currentFor($profile);
    expect($status->countdown_label)->toBe('Live começa')->and($status->countdown_at)->not->toBeNull();
});

it('recusa status com contato (filtro anti-contato)', function () {
    $profile = pstPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.status.save'), ['body' => 'me chama no 11 99999-9999'])
        ->assertSessionHasErrors('body');
    expect(app(PerformerStatusService::class)->currentFor($profile))->toBeNull();
});

it('recusa contagem regressiva no passado', function () {
    $profile = pstPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.status.save'), [
            'body' => 'Live',
            'countdown_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertSessionHasErrors('countdown_at');
});

it('performer não-ativa não define status', function () {
    $profile = pstPerformer('Bia', 'pending');

    $this->actingAs($profile->user)
        ->post(route('performer.status.save'), ['body' => 'Oi'])
        ->assertForbidden();
});

// ── Exposição pública ────────────────────────────────────────────────────────

it('o perfil público expõe o status ativo', function () {
    $profile = pstPerformer('Aurora');
    app(PerformerStatusService::class)->set($profile, ['body' => 'Aceitando chamadas']);

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer.status.body', 'Aceitando chamadas'));
});

it('o perfil público não expõe status expirado', function () {
    $profile = pstPerformer('Aurora');
    $status = app(PerformerStatusService::class)->set($profile, ['body' => 'Vencido']);
    $status->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer.status', null));
});

it('a tela de edição faz prefill do status ativo', function () {
    $profile = pstPerformer();
    app(PerformerStatusService::class)->set($profile, ['body' => 'Meu status']);

    $this->actingAs($profile->user)
        ->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status.body', 'Meu status'));
});
