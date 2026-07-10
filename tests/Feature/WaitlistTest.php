<?php

use App\Mail\WaitlistConfirmationMail;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

// ─── Confirmation email ──────────────────────────────────────────────────────

it('queues a confirmation email to a new waitlist signup with its position', function () {
    Mail::fake();

    // Seed one earlier entry so the new signup is #2 in line.
    WaitlistEntry::create([
        'name' => 'Early Bird', 'email' => 'early@example.com', 'role' => 'member',
        'age_confirmed' => true, 'source' => 'landing',
    ]);

    $this->post('/interesse', [
        'name' => 'Maria Silva',
        'email' => 'maria@example.com',
        'role' => 'member',
        'age_confirmed' => true,
    ])->assertSessionHas('success');

    Mail::assertQueued(WaitlistConfirmationMail::class, function ($mail) {
        return $mail->hasTo('maria@example.com')
            && $mail->entry->name === 'Maria Silva'
            && $mail->position === 2;
    });
});

it('does not resend the confirmation email on an idempotent re-submit', function () {
    Mail::fake();

    $payload = ['name' => 'Jo', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true];
    $this->post('/interesse', $payload)->assertSessionHas('success');
    $this->post('/interesse', ['name' => 'Joana'] + $payload)->assertSessionHas('success');

    Mail::assertQueued(WaitlistConfirmationMail::class, 1);
});

it('does not send a confirmation email for a honeypot submission', function () {
    Mail::fake();

    $this->post('/interesse', [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'role' => 'member',
        'age_confirmed' => true,
        'website' => 'http://spam.example',
    ]);

    Mail::assertNothingQueued();
});

// ─── Unsubscribe ─────────────────────────────────────────────────────────────

it('removes the email from the waitlist with a valid token', function () {
    $entry = WaitlistEntry::create([
        'name' => 'Rita', 'email' => 'rita@example.com', 'role' => 'member',
        'age_confirmed' => true, 'source' => 'landing',
    ]);

    $this->get('/waitlist/cancelar?email=rita@example.com&token=' . $entry->unsubscribeToken())
        ->assertRedirect(route('landing'))
        ->assertSessionHas('success');

    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(0);
});

it('removes every role for the email on unsubscribe', function () {
    foreach (['member', 'performer'] as $role) {
        WaitlistEntry::create([
            'name' => 'Sam', 'email' => 'sam@example.com', 'role' => $role,
            'age_confirmed' => true, 'source' => 'landing',
        ]);
    }

    $token = WaitlistEntry::makeUnsubscribeToken('sam@example.com');
    $this->get('/waitlist/cancelar?email=sam@example.com&token=' . $token);

    expect(WaitlistEntry::where('email', 'sam@example.com')->count())->toBe(0);
});

it('does not remove anything with a forged or missing token', function () {
    WaitlistEntry::create([
        'name' => 'Rita', 'email' => 'rita@example.com', 'role' => 'member',
        'age_confirmed' => true, 'source' => 'landing',
    ]);

    // Forged token — must not delete.
    $this->get('/waitlist/cancelar?email=rita@example.com&token=deadbeef')
        ->assertRedirect(route('landing'));
    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(1);

    // Missing token — must not delete.
    $this->get('/waitlist/cancelar?email=rita@example.com');
    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(1);
});

it('normalizes the email before matching on unsubscribe', function () {
    WaitlistEntry::create([
        'name' => 'Rita', 'email' => 'rita@example.com', 'role' => 'member',
        'age_confirmed' => true, 'source' => 'landing',
    ]);

    // Token is defined over the normalized email; an uppercase query still works.
    $token = WaitlistEntry::makeUnsubscribeToken('rita@example.com');
    $this->get('/waitlist/cancelar?email=' . urlencode('RITA@example.com ') . '&token=' . $token);

    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(0);
});
