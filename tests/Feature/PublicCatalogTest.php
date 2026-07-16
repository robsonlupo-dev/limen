<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makePublicPerformer(array $userAttrs = [], array $profileAttrs = []): PerformerProfile
{
    $user = User::factory()->create(array_merge([
        'role' => 'performer',
        'status' => 'active',
    ], $userAttrs));

    return $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana Lima ' . Str::random(4),
        'slug' => 'ana-lima-' . strtolower(Str::random(6)),
        'bio' => 'Bio pública da performer.',
        'category' => 'mulheres',
        'is_verified' => true,
    ], $profileAttrs));
}

// ─── 1. Public access without auth ───────────────────────────────────────────

it('renders the public catalog for a guest with 200', function () {
    makePublicPerformer();

    $this->get('/performers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performers/Index'));
});

it('renders a public performer profile for a guest with 200', function () {
    $profile = makePublicPerformer();

    $this->get("/performers/{$profile->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performers/Show')
            ->where('performer.slug', $profile->slug)
        );
});

// ─── 2. World filter ─────────────────────────────────────────────────────────

it('filters the public catalog by world', function () {
    $mulher = makePublicPerformer([], ['category' => 'mulheres', 'stage_name' => 'Mulher A']);
    $homem = makePublicPerformer([], ['category' => 'homens', 'stage_name' => 'Homem B']);

    $this->get('/performers?mundo=mulheres')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.mundo', 'mulheres')
            ->where('performers.data', fn ($data) => $data->pluck('slug')->contains($mulher->slug)
                && ! $data->pluck('slug')->contains($homem->slug))
        );
});

it('rejects an unknown world value', function () {
    $this->get('/performers?mundo=aliens')
        ->assertRedirect();
});

// ─── 3. Only active + verified are exposed ───────────────────────────────────

it('does not list pending performers publicly', function () {
    $pending = makePublicPerformer(['status' => 'pending']);

    $this->get('/performers')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => ! $data->pluck('slug')->contains($pending->slug))
        );
});

it('does not list unverified performers publicly', function () {
    $unverified = makePublicPerformer([], ['is_verified' => false]);

    $this->get('/performers')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', fn ($data) => ! $data->pluck('slug')->contains($unverified->slug))
        );
});

it('404s the public profile of a pending performer even with a known slug', function () {
    $pending = makePublicPerformer(['status' => 'pending']);

    $this->get("/performers/{$pending->slug}")->assertNotFound();
});

it('404s an unknown public profile slug', function () {
    $this->get('/performers/does-not-exist')->assertNotFound();
});

// ─── 4. No PII leaks into the page props ─────────────────────────────────────

it('exposes no PII in the public catalog props', function () {
    makePublicPerformer();

    $this->get('/performers')
        ->assertInertia(fn (Assert $page) => $page
            ->where('performers.data', function ($data) {
                foreach ($data as $item) {
                    expect($item)->not->toHaveKeys([
                        'user_id', 'email', 'split_pct', 'rate_public',
                        'rate_private', 'rate_camera', 'asaas_customer_id',
                    ]);
                }

                return true;
            })
        );
});

it('exposes no PII in the public profile props', function () {
    $profile = makePublicPerformer();

    $this->get("/performers/{$profile->slug}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer', function ($performer) {
                expect($performer)->not->toHaveKeys([
                    'user_id', 'email', 'split_pct', 'rate_public',
                    'rate_private', 'rate_camera', 'asaas_customer_id',
                ]);

                return true;
            })
        );
});
