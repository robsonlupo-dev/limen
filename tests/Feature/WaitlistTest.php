<?php

use App\Enums\WaitlistTier;
use App\Mail\WaitlistConfirmationMail;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;
use App\Services\Waitlist\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** Persist a signup through the real service and return the entry. */
function joinWaitlist(array $overrides = [], ?WaitlistEntry $referrer = null, ?string $ip = '127.0.0.1'): WaitlistEntry
{
    $data = array_merge([
        'name' => 'Maria Silva', 'email' => 'maria@example.com', 'role' => 'member',
    ], $overrides);

    return app(WaitlistService::class)->join($data, $referrer, $ip)['entry'];
}

// ─── Landing ─────────────────────────────────────────────────────────────────

it('renders the landing page as Inertia Landing component for guests', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Landing'));
});

it('redirects logged-in users away from the landing to the catalog', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('catalog'));
});

// ─── Waitlist capture ────────────────────────────────────────────────────────

it('stores a member signup, generates an invite code/token, and flashes success', function () {
    Mail::fake();

    $this->post('/interesse', [
        'name' => 'Maria Silva', 'email' => 'MARIA@Example.com ', 'role' => 'member', 'age_confirmed' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $entry = WaitlistEntry::firstWhere('email', 'maria@example.com');
    expect($entry)->not->toBeNull()
        ->and($entry->invite_code)->toMatch('/^LIMEN-[A-Z]{3}-\d{4}$/')
        ->and($entry->invite_token)->toHaveLength(40)
        ->and($entry->tier)->toBe(WaitlistTier::Curious)
        ->and($entry->confirmed_at)->toBeNull();
});

it('is idempotent per email and role (no duplicate rows, keeps the same invite code)', function () {
    Mail::fake();
    $this->post('/interesse', ['name' => 'Jo', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true]);
    $code = WaitlistEntry::firstWhere('email', 'jo@example.com')->invite_code;

    $this->post('/interesse', ['name' => 'Joana', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true]);

    expect(WaitlistEntry::where('email', 'jo@example.com')->count())->toBe(1);
    expect(WaitlistEntry::firstWhere('email', 'jo@example.com')->invite_code)->toBe($code);
});

it('requires explicit 18+ confirmation', function () {
    $this->post('/interesse', ['name' => 'Nina', 'email' => 'nina@example.com', 'role' => 'member', 'age_confirmed' => false])
        ->assertSessionHasErrors('age_confirmed');
    expect(WaitlistEntry::count())->toBe(0);
});

it('silently swallows honeypot submissions without persisting or mailing', function () {
    Mail::fake();
    $this->post('/interesse', [
        'name' => 'Bot', 'email' => 'bot@example.com', 'role' => 'member', 'age_confirmed' => true,
        'website' => 'http://spam.example',
    ])->assertSessionHas('success');

    expect(WaitlistEntry::count())->toBe(0);
    Mail::assertNothingQueued();
});

// ─── Confirmation email ──────────────────────────────────────────────────────

it('queues the confirmation email with position and tier on a new signup', function () {
    Mail::fake();
    joinWaitlist(['email' => 'early@example.com']); // seed one earlier entry (via service, no mail)

    $this->post('/interesse', ['name' => 'Bianca', 'email' => 'bia@example.com', 'role' => 'member', 'age_confirmed' => true]);

    Mail::assertQueued(WaitlistConfirmationMail::class, fn ($m) => $m->hasTo('bia@example.com') && $m->position === 2);
});

it('does not resend the confirmation email on an idempotent re-submit', function () {
    Mail::fake();
    $payload = ['name' => 'Jo', 'email' => 'jo@example.com', 'role' => 'member', 'age_confirmed' => true];
    $this->post('/interesse', $payload);
    $this->post('/interesse', ['name' => 'Joana'] + $payload);

    Mail::assertQueued(WaitlistConfirmationMail::class, 1);
});

// ─── Email confirmation (double opt-in) ──────────────────────────────────────

it('confirms an email via the token link and lands on the founder panel', function () {
    $entry = joinWaitlist();

    $this->get('/waitlist/confirmar?t=' . $entry->invite_token)
        ->assertRedirect(route('waitlist.founder', ['invite_code' => $entry->invite_code]))
        ->assertSessionHas('success');

    expect($entry->fresh()->confirmed_at)->not->toBeNull();
});

it('is idempotent on confirm and bounces an invalid token to the landing', function () {
    $entry = joinWaitlist();
    $this->get('/waitlist/confirmar?t=' . $entry->invite_token);
    $first = $entry->fresh()->confirmed_at;
    $this->get('/waitlist/confirmar?t=' . $entry->invite_token); // second hit (e.g. prefetch)
    expect($entry->fresh()->confirmed_at->eq($first))->toBeTrue();

    $this->get('/waitlist/confirmar?t=bogus')->assertRedirect(route('landing'));
});

// ─── Referral attribution ────────────────────────────────────────────────────

it('shows the referral banner and attributes the signup through an invite link', function () {
    Mail::fake();
    $referrer = joinWaitlist(['name' => 'Rafael Souza', 'email' => 'raf@example.com']);

    // Visiting the invite link shows the banner and stashes the referrer.
    $this->get('/convite/' . $referrer->invite_code)
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Landing')->where('referral.name', 'Rafael'));

    // A signup in the same session is attributed to the referrer.
    $this->post('/interesse', ['name' => 'Novo', 'email' => 'novo@example.com', 'role' => 'member', 'age_confirmed' => true]);

    $referred = WaitlistEntry::firstWhere('email', 'novo@example.com');
    expect($referred->referred_by)->toBe($referrer->id);
    expect(WaitlistReferral::where('referred_id', $referred->id)->exists())->toBeTrue();
});

it('increments referral_count only after the referred person confirms', function () {
    $referrer = joinWaitlist(['email' => 'ref@example.com']);
    $referred = joinWaitlist(['email' => 'friend@example.com'], $referrer);

    expect($referrer->fresh()->referral_count)->toBe(0); // not yet confirmed

    app(WaitlistService::class)->confirm($referred);

    expect($referrer->fresh()->referral_count)->toBe(1);
});

it('promotes the tier as confirmed referrals cross thresholds', function () {
    $referrer = joinWaitlist(['email' => 'boss@example.com']);

    foreach (range(1, 5) as $i) {
        $friend = joinWaitlist(['email' => "f{$i}@example.com"], $referrer, "8.8.8.{$i}");
        app(WaitlistService::class)->confirm($friend);
    }

    $referrer->refresh();
    expect($referrer->referral_count)->toBe(5);
    expect($referrer->tier)->toBe(WaitlistTier::Founder); // 5 => founder
});

it('never attributes a self-referral', function () {
    $referrer = joinWaitlist(['email' => 'self@example.com']);
    // Same email re-joining through their own link must not create a referral.
    $again = joinWaitlist(['email' => 'self@example.com'], $referrer);

    expect($again->referred_by)->toBeNull();
    expect(WaitlistReferral::count())->toBe(0);
});

// ─── Anti-fraud ──────────────────────────────────────────────────────────────

it('caps referrals from the same IP at 3 per 24h', function () {
    $referrer = joinWaitlist(['email' => 'farm@example.com']);
    $svc = app(WaitlistService::class);

    foreach (range(1, 5) as $i) {
        $svc->join(['name' => "Fake$i", 'email' => "fake{$i}@example.com", 'role' => 'member'], $referrer, '6.6.6.6');
    }

    // All 5 entries exist, but only the first 3 are attributed as referrals.
    expect(WaitlistEntry::where('email', 'like', 'fake%')->count())->toBe(5);
    expect(WaitlistReferral::count())->toBe(3);
});

// ─── Founder panel ───────────────────────────────────────────────────────────

it('renders the public founder panel for a valid invite code', function () {
    $entry = joinWaitlist(['name' => 'Rafael Souza', 'email' => 'raf@example.com']);

    $this->get('/f/' . $entry->invite_code)
        ->assertOk()
        ->assertSee('Painel de Rafael')
        ->assertSee($entry->invite_code)
        ->assertSee('Curioso');
});

it('404s the founder panel for an unknown invite code', function () {
    $this->get('/f/LIMEN-XXX-0000')->assertNotFound();
});

it('does not expose referred emails on the founder panel (only masked names)', function () {
    $referrer = joinWaitlist(['email' => 'ref@example.com']);
    joinWaitlist(['name' => 'Carla Menezes', 'email' => 'carla@example.com'], $referrer);

    $this->get('/f/' . $referrer->invite_code)
        ->assertSee('Carla M.')          // masked
        ->assertDontSee('carla@example.com');
});

// ─── Admin ───────────────────────────────────────────────────────────────────

it('blocks the admin waitlist page for guests and non-admins', function () {
    $this->get('/admin/waitlist')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'consumer']))->get('/admin/waitlist')->assertForbidden();
});

it('shows the admin waitlist dashboard to an admin', function () {
    $referrer = joinWaitlist(['email' => 'a@example.com']);
    $friend = joinWaitlist(['email' => 'b@example.com'], $referrer);
    app(WaitlistService::class)->confirm($friend);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/admin/waitlist')
        ->assertOk()
        ->assertSee('Founding Members')
        ->assertSee('Coeficiente viral');
});

// ─── Unsubscribe (via stored invite_token) ───────────────────────────────────

it('renders the unsubscribe confirmation on GET without deleting', function () {
    $entry = joinWaitlist(['email' => 'rita@example.com']);

    $this->get('/waitlist/cancelar?t=' . $entry->invite_token)
        ->assertOk()
        ->assertSee('rita@example.com');

    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(1);
});

it('deletes the entry only on the confirmed POST', function () {
    $entry = joinWaitlist(['email' => 'rita@example.com']);

    $this->post('/waitlist/cancelar', ['token' => $entry->invite_token])
        ->assertRedirect(route('landing'))->assertSessionHas('success');

    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(0);
});

it('does nothing on POST with a forged token', function () {
    joinWaitlist(['email' => 'rita@example.com']);
    $this->post('/waitlist/cancelar', ['token' => 'deadbeef'])->assertRedirect(route('landing'));
    expect(WaitlistEntry::where('email', 'rita@example.com')->count())->toBe(1);
});

it('recomputes the referrer tier when a confirmed referred person unsubscribes', function () {
    $referrer = joinWaitlist(['email' => 'boss@example.com']);
    $friend = joinWaitlist(['email' => 'friend@example.com'], $referrer);
    app(WaitlistService::class)->confirm($friend);
    expect($referrer->fresh()->referral_count)->toBe(1);

    // Unsubscribing the referred person must not leave the referrer inflated.
    $this->post('/waitlist/cancelar', ['token' => $friend->invite_token]);

    expect($referrer->fresh()->referral_count)->toBe(0);
    expect(WaitlistReferral::count())->toBe(0);
});
