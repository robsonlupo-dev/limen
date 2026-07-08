<?php

use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// ─── Landing renders for guests ──────────────────────────────────────────────

it('renders the landing page as Inertia Landing component for guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Landing'));
});

it('redirects logged-in users away from the landing to the catalog', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('catalog'));
});

// ─── Waitlist capture ────────────────────────────────────────────────────────

it('stores a member waitlist entry and flashes success', function () {
    $this->post('/interesse', [
        'name' => 'Maria Silva',
        'email' => 'MARIA@Example.com ',
        'role' => 'member',
        'age_confirmed' => true,
    ])->assertRedirect()->assertSessionHas('success');

    // Email is normalized (lowercased/trimmed) before persisting.
    $this->assertDatabaseHas('waitlist_entries', [
        'email' => 'maria@example.com',
        'role' => 'member',
        'name' => 'Maria Silva',
        'source' => 'landing',
        'age_confirmed' => true,
    ]);
});

it('stores a performer waitlist entry with an optional world', function () {
    $this->post('/interesse', [
        'name' => 'Alex',
        'email' => 'alex@example.com',
        'role' => 'performer',
        'world' => 'trans',
        'age_confirmed' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('waitlist_entries', [
        'email' => 'alex@example.com',
        'role' => 'performer',
        'world' => 'trans',
    ]);
});

it('is idempotent per email and role (no duplicate rows)', function () {
    $this->post('/interesse', ['name' => 'Jo', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true])
        ->assertSessionHas('success');
    $this->post('/interesse', ['name' => 'Joana', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true])
        ->assertSessionHas('success');

    expect(WaitlistEntry::where('email', 'jo@example.com')->where('role', 'member')->count())->toBe(1);
    // The latest submission updates the stored name.
    $this->assertDatabaseHas('waitlist_entries', ['email' => 'jo@example.com', 'name' => 'Joana']);
});

it('allows the same email for both member and performer roles', function () {
    $this->post('/interesse', ['name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'member', 'age_confirmed' => true]);
    $this->post('/interesse', ['name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'performer', 'age_confirmed' => true]);

    expect(WaitlistEntry::where('email', 'sam@example.com')->count())->toBe(2);
});

// ─── Validation & anti-abuse ─────────────────────────────────────────────────

it('rejects a waitlist submission with missing fields', function () {
    $this->post('/interesse', ['name' => '', 'email' => 'not-an-email', 'role' => 'admin'])
        ->assertSessionHasErrors(['name', 'email', 'role']);

    expect(WaitlistEntry::count())->toBe(0);
});

it('requires explicit 18+ confirmation', function () {
    $this->post('/interesse', [
        'name' => 'Nina',
        'email' => 'nina@example.com',
        'role' => 'member',
        'age_confirmed' => false,
    ])->assertSessionHasErrors('age_confirmed');

    expect(WaitlistEntry::count())->toBe(0);
});

it('rejects an invalid world value', function () {
    $this->post('/interesse', [
        'name' => 'Kim',
        'email' => 'kim@example.com',
        'role' => 'member',
        'age_confirmed' => true,
        'world' => 'invalid',
    ])->assertSessionHasErrors('world');
});

it('silently swallows honeypot (bot) submissions without persisting', function () {
    $this->post('/interesse', [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'role' => 'member',
        'age_confirmed' => true,
        'website' => 'http://spam.example',
    ])->assertRedirect()->assertSessionHas('success');

    expect(WaitlistEntry::count())->toBe(0);
});
