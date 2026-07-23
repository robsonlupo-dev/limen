<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Múltiplos mundos por performer (Sprint 7). A `worlds` (json) é a fonte da
 * verdade; `category` sobrevive como mundo primário (compat) e é o fallback
 * enquanto houver linhas sem worlds. Helpers com prefixo mw* para rodar isolado.
 */
function mwPerformer(array $profileAttrs = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => 'MW '.Str::random(5),
        'slug' => 'mw-'.strtolower(Str::random(8)),
        'bio' => 'Bio pública.',
        'category' => 'mulheres',
        'is_verified' => true,
    ], $profileAttrs));
}

/** slugs da página pública do catálogo, opcionalmente filtrada por mundo. */
function mwCatalogSlugs(?string $world = null): array
{
    $url = $world ? "/performers?mundo={$world}" : '/performers';
    $slugs = [];

    test()->get($url)
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$slugs) {
            $slugs = collect($page->toArray()['props']['performers']['data'])
                ->pluck('slug')
                ->all();
        });

    return $slugs;
}

// ─── activeWorlds() (fallback) ───────────────────────────────────────────────

it('activeWorlds returns the worlds list when set', function () {
    $p = mwPerformer(['category' => 'mulheres', 'worlds' => ['mulheres', 'trans']]);

    expect($p->activeWorlds())->toBe(['mulheres', 'trans']);
});

it('activeWorlds falls back to category when worlds is null', function () {
    $p = mwPerformer(['category' => 'homens', 'worlds' => null]);

    expect($p->worlds)->toBeNull()
        ->and($p->activeWorlds())->toBe(['homens']);
});

// ─── Catálogo: pertencer a vários mundos ─────────────────────────────────────

it('a performer with worlds=[mulheres,trans] shows in BOTH world catalogs', function () {
    $multi = mwPerformer([
        'category' => 'mulheres',
        'worlds' => ['mulheres', 'trans'],
        'slug' => 'mw-multi-mt',
    ]);

    expect(mwCatalogSlugs('mulheres'))->toContain($multi->slug)
        ->and(mwCatalogSlugs('trans'))->toContain($multi->slug)
        // e não aparece num mundo que ela não marcou
        ->and(mwCatalogSlugs('homens'))->not->toContain($multi->slug);
});

it('a performer with worlds=null falls back to category in the catalog', function () {
    $legacy = mwPerformer([
        'category' => 'trans',
        'worlds' => null,
        'slug' => 'mw-legacy-trans',
    ]);

    expect($legacy->fresh()->worlds)->toBeNull()
        ->and(mwCatalogSlugs('trans'))->toContain($legacy->slug)
        ->and(mwCatalogSlugs('mulheres'))->not->toContain($legacy->slug);
});

it('the world-filtered catalog returns only performers of that world', function () {
    $mulher = mwPerformer(['worlds' => ['mulheres'], 'category' => 'mulheres', 'slug' => 'mw-only-mulher']);
    $homem = mwPerformer(['worlds' => ['homens'], 'category' => 'homens', 'slug' => 'mw-only-homem']);
    $multi = mwPerformer(['worlds' => ['mulheres', 'casais'], 'category' => 'mulheres', 'slug' => 'mw-mulher-casal']);

    $mulheres = mwCatalogSlugs('mulheres');

    expect($mulheres)->toContain($mulher->slug)
        ->toContain($multi->slug)          // multi-mundo entra
        ->not->toContain($homem->slug);    // outro mundo, não

    $homens = mwCatalogSlugs('homens');

    expect($homens)->toContain($homem->slug)
        ->not->toContain($mulher->slug)
        ->not->toContain($multi->slug);
});

// ─── Onboarding: pelo menos 1 mundo obrigatório ──────────────────────────────

it('registration requires at least one world for a performer', function () {
    $base = [
        'tipo' => 'performer',
        'name' => 'Sem Mundo',
        'email' => 'sem.mundo@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1995-03-10',
        'stage_name' => 'Sem Mundo Portal',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ];

    // worlds vazio explícito (o que o wizard nunca deixaria passar) → erro.
    $this->from(route('register'))
        ->post(route('register.store'), array_merge($base, ['worlds' => []]))
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('worlds');

    expect(User::where('email', 'sem.mundo@example.com')->exists())->toBeFalse();
});

it('registration persists worlds and derives category from the first', function () {
    $this->post(route('register.store'), [
        'tipo' => 'performer',
        'name' => 'Luna Multi',
        'email' => 'luna.multi@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1995-03-10',
        'stage_name' => 'Luna Multi Portal',
        'worlds' => ['trans', 'mulheres'],
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('performer.onboarding'));

    $profile = User::where('email', 'luna.multi@example.com')->sole()->performerProfile;

    expect($profile->worlds)->toBe(['trans', 'mulheres'])
        // category (compat) = primeiro mundo, derivado no servidor.
        ->and($profile->category)->toBe('trans')
        ->and($profile->activeWorlds())->toBe(['trans', 'mulheres']);
});

it('legacy registration with only category still works (worlds stays null)', function () {
    $this->post(route('register.store'), [
        'tipo' => 'performer',
        'name' => 'Legacy Cat',
        'email' => 'legacy.cat@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1995-03-10',
        'stage_name' => 'Legacy Cat Portal',
        'category' => 'casais',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('performer.onboarding'));

    $profile = User::where('email', 'legacy.cat@example.com')->sole()->performerProfile;

    expect($profile->worlds)->toBeNull()
        ->and($profile->category)->toBe('casais')
        ->and($profile->activeWorlds())->toBe(['casais']);
});
