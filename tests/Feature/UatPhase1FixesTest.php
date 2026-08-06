<?php

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\PerformerProfilePreviousSlug;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerContentService;
use App\Services\PerformerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Achados do UAT — fase 1. Helpers locais (prefixo u1). Cobre os quatro fixes:
 * redirect 301 de slug antigo, upload de capa (destravando o checklist),
 * navegação do logo e acesso à gestão de conteúdo permanente.
 */
function u1Performer(string $stageName = 'Ana', string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function u1Member(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ])->fresh();
}

// ── Fix 1: redirect 301 de slug antigo ──────────────────────────────────────

it('records the vacated slug and 301-redirects the old member-catalog url to the new one', function () {
    $profile = u1Performer('Ana');
    $oldSlug = $profile->slug;

    app(PerformerProfileService::class)->update($profile, ['stage_name' => 'Bianca']);
    $profile->refresh();

    expect($profile->slug)->not->toBe($oldSlug);
    expect(PerformerProfilePreviousSlug::where('slug', $oldSlug)->exists())->toBeTrue();

    $this->actingAs(u1Member())
        ->get(route('catalog.show', $oldSlug))
        ->assertRedirect(route('catalog.show', $profile->slug))
        ->assertStatus(301);
});

it('301-redirects the old public url to the new one', function () {
    $profile = u1Performer('Ana');
    $oldSlug = $profile->slug;

    app(PerformerProfileService::class)->update($profile, ['stage_name' => 'Bianca']);
    $profile->refresh();

    $this->get(route('performers.public.show', $oldSlug))
        ->assertRedirect(route('performers.public.show', $profile->slug))
        ->assertStatus(301);
});

it('still 404s an unknown slug that was never used', function () {
    $this->get(route('performers.public.show', 'nunca-existiu-zzzz'))->assertNotFound();
});

it('does not redirect to a performer who is no longer public', function () {
    $profile = u1Performer('Ana');
    $oldSlug = $profile->slug;

    app(PerformerProfileService::class)->update($profile, ['stage_name' => 'Bianca']);
    // Sai do catálogo público (deixa de ser verificada) — o link antigo 404,
    // não redireciona para uma página que ela mesma esconde.
    $profile->refresh();
    $profile->update(['is_verified' => false]);

    $this->get(route('performers.public.show', $oldSlug))->assertNotFound();
});

it('does not recycle a slug that still redirects to another performer', function () {
    $profile = u1Performer('Ana');
    $oldSlug = $profile->slug;
    app(PerformerProfileService::class)->update($profile, ['stage_name' => 'Bianca']);

    // 200 gerações de slug para o mesmo nome-base nunca podem cair no slug
    // histórico (unique + o guard do generateSlug).
    for ($i = 0; $i < 200; $i++) {
        expect(PerformerProfile::generateSlug(Str::before($oldSlug, '-')))->not->toBe($oldSlug);
    }
});

it('purges previous slugs on hard delete', function () {
    $profile = u1Performer('Ana');
    $oldSlug = $profile->slug;
    app(PerformerProfileService::class)->update($profile, ['stage_name' => 'Bianca']);

    expect(PerformerProfilePreviousSlug::where('slug', $oldSlug)->exists())->toBeTrue();

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    expect(PerformerProfilePreviousSlug::where('slug', $oldSlug)->exists())->toBeFalse();
});

// ── Fix 3: upload de capa (destrava o checklist "Adicionar foto de capa") ────

it('lets an active performer upload a cover photo, setting cover_path', function () {
    Storage::fake('local');
    $profile = u1Performer('Ana');

    $this->actingAs($profile->user)
        ->post(route('performer.profile.cover-photo'), [
            'file' => UploadedFile::fake()->image('capa.jpg', 1200, 400),
        ])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->cover_path)->not->toBeNull();
    Storage::disk('local')->assertExists($profile->cover_path);
});

it('exposes cover_url on the edit screen once a cover exists', function () {
    Storage::fake('local');
    $profile = u1Performer('Ana');

    $this->actingAs($profile->user)->post(route('performer.profile.cover-photo'), [
        'file' => UploadedFile::fake()->image('capa.jpg', 1200, 400),
    ]);

    $this->actingAs($profile->user->fresh())
        ->get(route('performer.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Profile/Edit')
            ->whereNot('profile.cover_url', null)
        );
});

it('reports has_cover true to the dashboard checklist after a cover upload', function () {
    Storage::fake('local');
    $profile = u1Performer('Ana');

    $this->actingAs($profile->user)->post(route('performer.profile.cover-photo'), [
        'file' => UploadedFile::fake()->image('capa.jpg', 1200, 400),
    ]);

    $this->actingAs($profile->user->fresh())
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('profileProgress.has_cover', true));
});

// ── Fix 4: acesso à gestão de conteúdo permanente ───────────────────────────

it('renders the permanent-content management page for an active performer', function () {
    $profile = u1Performer('Ana');

    $this->actingAs($profile->user)
        ->get(route('performer.content'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Content/Index')
            ->has('levels')
            ->where('minPrice', 5)
        );
});

it('lists the performer own pieces on the management page', function () {
    $profile = u1Performer('Ana');
    app(PerformerContentService::class)->publish(
        $profile,
        UploadedFile::fake()->image('c.jpg', 800, 600),
        PerformerContent::LEVEL_OPEN,
        20,
    );

    $this->actingAs($profile->user)
        ->get(route('performer.content'))
        ->assertInertia(fn (Assert $page) => $page->has('content', 1));
});

it('does not let a member reach the content management page', function () {
    $this->actingAs(u1Member())
        ->get(route('performer.content'))
        ->assertForbidden();
});
