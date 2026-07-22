<?php

use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeWebPerformer(array $userAttrs = [], array $profileAttrs = []): array
{
    $user = User::factory()->create(array_merge([
        'role' => 'performer',
        'status' => 'active',
    ], $userAttrs));

    $profile = $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana Lima '.Str::random(4),
        'slug' => 'ana-lima-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ], $profileAttrs));

    return [$user, $profile];
}

function makeWebConsumer(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
    ], $attrs));
}

// ─── 1. Guest is redirected to login ─────────────────────────────────────────

it('redirects unauthenticated access to the catalog to the login page', function () {
    $this->get('/catalogo')->assertRedirect(route('login'));
});

// ─── 2. Authenticated consumer renders Catalog/Index ─────────────────────────

it('renders Catalog/Index for an authenticated consumer', function () {
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Catalog/Index'));
});

// ─── 3. Pending performers do not appear ─────────────────────────────────────

it('does not list pending performers in the catalog', function () {
    [, $pending] = makeWebPerformer(['status' => 'pending']);
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/catalogo')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => ! $data->pluck('slug')->contains($pending->slug))
        );
});

// ─── 4. Active + verified performers appear ──────────────────────────────────

it('lists active and verified performers in the catalog', function () {
    [, $visible] = makeWebPerformer();
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/catalogo')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => $data->pluck('slug')->contains($visible->slug))
        );
});

// ─── 5. Category filter works ────────────────────────────────────────────────

it('filters the catalog by category', function () {
    [, $mulheres] = makeWebPerformer([], ['category' => 'mulheres']);
    [, $homens] = makeWebPerformer([], ['category' => 'homens']);
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/catalogo?category=mulheres')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => $data->pluck('slug')->contains($mulheres->slug)
                && ! $data->pluck('slug')->contains($homens->slug))
        );
});

// ─── 6. Search by stage_name works ───────────────────────────────────────────

it('searches the catalog by stage_name', function () {
    [, $match] = makeWebPerformer([], ['stage_name' => 'Unique Name XYZ']);
    [, $noMatch] = makeWebPerformer([], ['stage_name' => 'Completely Different']);
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/catalogo?search=Unique+Name')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => $data->pluck('slug')->contains($match->slug)
                && ! $data->pluck('slug')->contains($noMatch->slug))
        );
});

// ─── 7. Show page renders correct data ───────────────────────────────────────

it('renders Catalog/Show with correct performer data', function () {
    [, $profile] = makeWebPerformer(['status' => 'active'], ['stage_name' => 'Bela Show']);
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get("/catalogo/{$profile->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Show')
            ->where('performer.slug', $profile->slug)
            ->where('performer.stage_name', 'Bela Show')
        );
});

// ─── 8. Pending performer returns 404 on show ────────────────────────────────

it('returns 404 for a pending performer profile', function () {
    [, $pending] = makeWebPerformer(['status' => 'pending']);
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get("/catalogo/{$pending->slug}")
        ->assertNotFound();
});

// ─── 9. Follow creates a record and redirects with flash ────────────────────

it('follows a performer and redirects with a success flash message', function () {
    [, $profile] = makeWebPerformer();
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->post("/catalogo/{$profile->slug}/seguir")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Follow::where('user_id', $consumer->id)->where('performer_profile_id', $profile->id)->exists())->toBeTrue();

    $profile->refresh();
    expect($profile->followers_count)->toBe(1);
});

// ─── 10. Unfollow removes the record ─────────────────────────────────────────

it('unfollows a performer and removes the follow record', function () {
    [, $profile] = makeWebPerformer();
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)->post("/catalogo/{$profile->slug}/seguir");

    $this->actingAs($consumer)
        ->delete("/catalogo/{$profile->slug}/seguir")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Follow::where('user_id', $consumer->id)->where('performer_profile_id', $profile->id)->exists())->toBeFalse();

    $profile->refresh();
    expect($profile->followers_count)->toBe(0);
});

// ─── 11. Consumer cannot follow itself ───────────────────────────────────────

it('prevents a consumer from following its own performer profile', function () {
    $user = makeWebConsumer();
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Self Performer',
        'slug' => 'self-performer-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    $this->actingAs($user)
        ->post("/catalogo/{$profile->slug}/seguir")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Follow::where('user_id', $user->id)->where('performer_profile_id', $profile->id)->exists())->toBeFalse();
});

// ─── 12. Public profile page does not expose internal fields ────────────────

it('does not expose user_id, email, or CPF in the public profile page data', function () {
    [, $profile] = makeWebPerformer();
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get("/catalogo/{$profile->slug}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer', fn ($performer) => ! $performer->has('user_id')
                && ! $performer->has('email')
                && ! $performer->has('cpf')
                && ! $performer->has('split_pct'))
        );
});
