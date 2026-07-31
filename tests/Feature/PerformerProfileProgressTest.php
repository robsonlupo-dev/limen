<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\LifestyleTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** Performer própria (helper local — prefixo pp — para o arquivo se bastar). */
function ppPerformer(array $profileAttrs = [], array $userAttrs = []): User
{
    $user = User::factory()->create(array_merge([
        'role' => 'performer',
        'status' => 'active',
    ], $userAttrs));

    $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana '.Str::random(4),
        'slug' => PerformerProfile::generateSlug('Ana '.Str::random(6)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileAttrs));

    return $user;
}

/**
 * Barra de completude do perfil da performer (Sprint 10).
 *
 * A porcentagem em si é DERIVADA no frontend (ProfileProgress.vue) — não há
 * runner de JS no repo para exercitar a conta. O que estes testes travam é o
 * CONTRATO da prop `profileProgress` que alimenta o componente: presença dos
 * campos, o booleano de foto (o caminho no storage não vaza para medir presença)
 * e a ausência do que NÃO pode entrar.
 *
 * Helper local (dashboard()) para o arquivo ser autossuficiente; makeWebPerformer
 * vem do CatalogPhase8Test (autoload de helpers do Pest).
 */

function progressProps(array $profileAttrs = [], array $userAttrs = []): array
{
    return test()->actingAs(ppPerformer($profileAttrs, $userAttrs))
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->viewData('page')['props']['profileProgress'];
}

it('expoe a prop profileProgress com todos os campos que a barra calcula', function () {
    test()->actingAs(ppPerformer())
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('profileProgress', fn (Assert $p) => $p
                ->has('has_avatar')
                ->has('has_cover')
                ->has('bio')
                ->has('looking_for')
                ->has('tags')
                ->has('languages')
                ->has('drinks')
                ->has('smokes')
                ->has('height_cm')
                ->has('state')
                ->has('city')
            )
        );
});

it('manda has_avatar e has_cover como booleano de presenca, nunca o caminho', function () {
    $props = progressProps([
        'avatar_path' => 'performers/avatars/secreto.jpg',
        'cover_path' => null,
    ]);

    expect($props['has_avatar'])->toBeTrue()
        ->and($props['has_cover'])->toBeFalse();

    // O caminho no storage não pode trafegar — só o booleano.
    $body = test()->actingAs(ppPerformer(['avatar_path' => 'performers/avatars/secreto.jpg']))
        ->get(route('performer.dashboard'))->assertOk()->getContent();

    expect($body)->not->toContain('secreto.jpg');
});

it('reflete os campos de sobre-mim preenchidos', function () {
    $props = progressProps([
        'bio' => Str::repeat('a', 200),
        'looking_for' => 'Conexões genuínas.',
        'languages' => ['portugues', 'ingles'],
        'drinks' => 'bebe_socialmente',
        'smokes' => 'nao_fuma',
        'height_cm' => 171,
        'state' => 'SP',
        'city' => 'Barueri',
    ]);

    expect($props['bio'])->toBe(Str::repeat('a', 200))
        ->and($props['looking_for'])->toBe('Conexões genuínas.')
        ->and($props['languages'])->toBe(['portugues', 'ingles'])
        ->and($props['drinks'])->toBe('bebe_socialmente')
        ->and($props['smokes'])->toBe('nao_fuma')
        ->and($props['height_cm'])->toBe(171)
        ->and($props['state'])->toBe('SP')
        ->and($props['city'])->toBe('Barueri');
});

it('entrega os campos vazios como null para o perfil recem-criado', function () {
    $props = progressProps();

    // Opt-in: campo não preenchido é null/[]/false, e é o que o componente lê
    // como "pendente". Não há placeholder inventado no backend.
    expect($props['has_avatar'])->toBeFalse()
        ->and($props['has_cover'])->toBeFalse()
        ->and($props['bio'])->toBeNull()
        ->and($props['looking_for'])->toBeNull()
        ->and($props['tags'])->toBe([])
        ->and($props['languages'])->toBe([])
        ->and($props['drinks'])->toBeNull()
        ->and($props['smokes'])->toBeNull()
        ->and($props['height_cm'])->toBeNull()
        ->and($props['state'])->toBeNull()
        ->and($props['city'])->toBeNull();
});

it('nao inclui o estilo de vida do membro na barra do perfil da performer', function () {
    // A barra é do perfil DELA. O Estilo de Vida (lifestyle_tier) é campo do
    // MEMBRO — não é completude de performer e não pode pegar carona na prop.
    $props = progressProps();

    expect($props)->not->toHaveKey('lifestyle_tier');

    // E o slug não aparece em lugar nenhum da resposta (é $hidden no User, mas a
    // asserção fecha o caso de uma prop solta acrescentada ao lado).
    foreach (LifestyleTier::storableValues() as $slug) {
        expect(json_encode($props))->not->toContain($slug);
    }
});
