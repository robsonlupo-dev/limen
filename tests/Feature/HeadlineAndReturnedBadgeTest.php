<?php

use App\Http\Resources\PerformerPublicResource;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\ReturnedBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Frase de destaque (headline) + selo "Voltou" (roadmap Fatal Model, Onda A).
 * Prefixo `hr` nos helpers (funções de teste do Pest são globais).
 */
function hrPerformer(array $userAttributes = [], array $profileAttributes = []): PerformerProfile
{
    // returned_at/ghost_mode (user) e appear_offline (profile) são guarded
    // (escrita só por forceFill) — não entram por mass-assign.
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    if ($userAttributes !== []) {
        $user->forceFill($userAttributes)->save();
    }

    static $n = 0;
    $n++;
    $name = $profileAttributes['stage_name'] ?? 'Perf'.$n; // stage_name tem índice único
    unset($profileAttributes['stage_name']);

    $guarded = [];
    foreach (['appear_offline'] as $k) {
        if (array_key_exists($k, $profileAttributes)) {
            $guarded[$k] = $profileAttributes[$k];
            unset($profileAttributes[$k]);
        }
    }

    $profile = $user->performerProfile()->create(array_merge([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileAttributes));

    if ($guarded !== []) {
        $profile->forceFill($guarded)->save();
    }

    return $profile;
}

/** O array do resource público para um perfil (carrega o user para is_returned). */
function hrResource(PerformerProfile $profile): array
{
    return (new PerformerPublicResource($profile->load('user')))->toArray(Request::create('/'));
}

// ── ReturnedBadge (janela) ───────────────────────────────────────────────────

it('ReturnedBadge: null é falso, recente é verdadeiro, vencido é falso', function () {
    expect(ReturnedBadge::isReturned(null))->toBeFalse()
        ->and(ReturnedBadge::isReturned(now()->subDay()))->toBeTrue()
        ->and(ReturnedBadge::isReturned(now()->subDays(ReturnedBadge::WINDOW_DAYS + 1)))->toBeFalse();
});

// ── Frase de destaque ────────────────────────────────────────────────────────

it('salva a frase de destaque e ela aparece no resource público', function () {
    $profile = hrPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'headline' => 'Curadoria e discrição'])
        ->assertRedirect();

    expect($profile->refresh()->headline)->toBe('Curadoria e discrição')
        ->and(hrResource($profile)['headline'])->toBe('Curadoria e discrição');
});

it('rejeita frase com contato (SafeProfileText) e acima de 40 caracteres', function () {
    $profile = hrPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'headline' => 'me chama no @minhaconta'])
        ->assertSessionHasErrors('headline');

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'headline' => str_repeat('a', 41)])
        ->assertSessionHasErrors('headline');

    expect($profile->refresh()->headline)->toBeNull();
});

// ── Selo "Voltou": exibição no resource ──────────────────────────────────────

it('expõe is_returned quando o retorno é recente', function () {
    $profile = hrPerformer(userAttributes: ['returned_at' => now()->subDay()]);

    expect(hrResource($profile)['is_returned'])->toBeTrue();
});

it('não expõe is_returned sem carimbo nem com retorno vencido', function () {
    expect(hrResource(hrPerformer())['is_returned'])->toBeFalse()
        ->and(hrResource(hrPerformer(userAttributes: ['returned_at' => now()->subDays(30)]))['is_returned'])->toBeFalse();
});

it('suprime is_returned sob appear_offline (sinal de presença)', function () {
    $profile = hrPerformer(
        userAttributes: ['returned_at' => now()->subDay()],
        profileAttributes: ['appear_offline' => true],
    );

    expect(hrResource($profile)['is_returned'])->toBeFalse();
});

it('o returned_at nunca sai como timestamp no resource (só o booleano)', function () {
    $profile = hrPerformer(userAttributes: ['returned_at' => now()->subDay()]);

    expect(hrResource($profile))->not->toHaveKey('returned_at');
});

// ── Middleware: carimbo do retorno ───────────────────────────────────────────

it('carimba returned_at quando a performer volta após mais de 30 dias', function () {
    $profile = hrPerformer();
    $profile->user->forceFill(['last_active_at' => now()->subDays(40)])->save();

    test()->actingAs($profile->user->fresh())->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->returned_at)->not->toBeNull();
});

it('NÃO carimba returned_at para conta nova (sem last_active_at) nem ausência curta', function () {
    $novo = hrPerformer();
    test()->actingAs($novo->user->fresh())->get(route('performers.public'))->assertOk();
    expect($novo->user->refresh()->returned_at)->toBeNull();

    $recente = hrPerformer();
    $recente->user->forceFill(['last_active_at' => now()->subHours(2)])->save();
    test()->actingAs($recente->user->fresh())->get(route('performers.public'))->assertOk();
    expect($recente->user->refresh()->returned_at)->toBeNull();
});

it('NÃO carimba returned_at sob Ghost Mode', function () {
    $profile = hrPerformer(userAttributes: ['ghost_mode' => true]);
    $profile->user->forceFill(['last_active_at' => now()->subDays(40)])->save();

    test()->actingAs($profile->user->fresh())->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->returned_at)->toBeNull();
});
