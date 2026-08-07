<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Trilha "Agora" (redesign do catálogo): a prop `lives` do catálogo — quem está
// AO VIVO agora no mundo do membro. Respeita o recorte público (ativa +
// verificada), o mundo corrente e a feature flag de dark launch. A parte de
// STORIES da trilha vem do fetch de `stories.feed` (coberto em outro teste).

function nowMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

function nowPerformer(string $name, array $attributes = []): PerformerProfile
{
    $status = $attributes['user_status'] ?? 'active';
    unset($attributes['user_status']);
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $attributes));
}

/** @return array<int,string> os nomes na trilha "Agora" (prop `lives`). */
function nowLiveNames(User $member): array
{
    $response = test()->actingAs($member)->get(route('catalog'))->assertOk();

    return collect($response->viewData('page')['props']['lives'])
        ->pluck('stage_name')->sort()->values()->all();
}

// ── Feature flag: o dark launch governa a trilha ─────────────────────────────

it('nao devolve lives com a feature de live desligada (dark launch, padrao)', function () {
    config(['features.live_enabled' => false]);
    nowPerformer('Ana')->forceFill(['is_live' => true])->save();

    expect(nowLiveNames(nowMember()))->toBe([]);
});

// ── Com a feature ligada, a trilha respeita os gates do catálogo ─────────────

it('inclui na trilha so quem esta ao vivo, verificada, ativa e no mundo do membro', function () {
    config(['features.live_enabled' => true]);

    nowPerformer('AoVivo')->forceFill(['is_live' => true])->save();          // entra
    nowPerformer('Offline');                                                 // não está ao vivo
    nowPerformer('NaoVerificada', ['is_verified' => false])->forceFill(['is_live' => true])->save(); // fora do recorte
    nowPerformer('Suspensa', ['user_status' => 'suspended'])->forceFill(['is_live' => true])->save(); // conta inativa
    nowPerformer('OutroMundo', ['category' => 'homens', 'worlds' => ['homens']])
        ->forceFill(['is_live' => true])->save();                            // outro mundo

    expect(nowLiveNames(nowMember()))->toBe(['AoVivo']);
});

it('devolve slug, stage_name e avatar_url em cada item da trilha', function () {
    config(['features.live_enabled' => true]);
    $live = nowPerformer('Bella');
    $live->forceFill(['is_live' => true])->save();

    $response = test()->actingAs(nowMember())->get(route('catalog'))->assertOk();
    $item = collect($response->viewData('page')['props']['lives'])->firstWhere('slug', $live->slug);

    expect($item)->not->toBeNull()
        ->and($item['stage_name'])->toBe('Bella')
        ->and($item)->toHaveKey('avatar_url'); // null quando não há avatar — a chave sempre existe
});
