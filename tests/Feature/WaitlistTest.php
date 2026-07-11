<?php

use App\Enums\MemberTier;
use App\Enums\PerformerTier;
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

/** Confirm (and optionally convert) a referred signup, varying the IP to dodge the cap. */
function referAndConfirm(WaitlistEntry $referrer, string $email, string $role, string $ip, bool $convert = false): WaitlistEntry
{
    $svc = app(WaitlistService::class);
    $e = $svc->join(['name' => 'Ref '.$email, 'email' => $email, 'role' => $role], $referrer, $ip)['entry'];
    $svc->confirm($e);
    if ($convert) {
        $svc->convert($e);
    }

    return $e;
}

// ─── Landing ─────────────────────────────────────────────────────────────────

it('renders the landing page as Inertia Landing component for guests', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Landing'));
});

it('redirects logged-in users away from the landing to the catalog', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('catalog'));
});

// ─── Waitlist capture ────────────────────────────────────────────────────────

it('stores a member signup with invite code/token, base member tier, and role position', function () {
    Mail::fake();

    $this->post('/interesse', [
        'name' => 'Maria Silva', 'email' => 'MARIA@Example.com ', 'role' => 'member', 'age_confirmed' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $entry = WaitlistEntry::firstWhere('email', 'maria@example.com');
    expect($entry)->not->toBeNull()
        ->and($entry->invite_code)->toMatch('/^LIMEN-[A-Z]{3}-\d{4}$/')
        ->and($entry->invite_token)->toHaveLength(40)
        ->and($entry->tier_member)->toBe(MemberTier::Curious)
        ->and($entry->tier_performer)->toBeNull()
        ->and($entry->position_in_role)->toBe(1)
        ->and($entry->confirmed_at)->toBeNull();
});

it('seeds the base performer tier for a performer signup', function () {
    $entry = joinWaitlist(['email' => 'lia@example.com', 'role' => 'performer']);

    expect($entry->tier_performer)->toBe(PerformerTier::Candidate)
        ->and($entry->tier_member)->toBeNull();
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

// ─── Anti-fraud: disposable email domains ────────────────────────────────────

it('rejects signups from disposable email domains', function () {
    foreach (['x@mailinator.com', 'y@guerrillamail.com', 'z@yopmail.com'] as $email) {
        $this->post('/interesse', ['name' => 'Burner', 'email' => $email, 'role' => 'member', 'age_confirmed' => true])
            ->assertSessionHasErrors('email');
    }

    expect(WaitlistEntry::count())->toBe(0);
});

it('accepts a normal email domain', function () {
    Mail::fake();
    $this->post('/interesse', ['name' => 'Ok', 'email' => 'ok@gmail.com', 'role' => 'member', 'age_confirmed' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WaitlistEntry::where('email', 'ok@gmail.com')->count())->toBe(1);
});

// ─── Confirmation email ──────────────────────────────────────────────────────

it('queues the confirmation email on a new signup', function () {
    Mail::fake();
    $this->post('/interesse', ['name' => 'Bianca', 'email' => 'bia@example.com', 'role' => 'member', 'age_confirmed' => true]);

    Mail::assertQueued(WaitlistConfirmationMail::class, fn ($m) => $m->hasTo('bia@example.com'));
});

it('renders the founder title and per-role position in the email', function () {
    $member = joinWaitlist(['email' => 'm@example.com', 'role' => 'member']);
    $performer = joinWaitlist(['email' => 'p@example.com', 'role' => 'performer']);

    expect((new WaitlistConfirmationMail($member))->render())->toContain('Membro Fundador')->toContain('#1');
    expect((new WaitlistConfirmationMail($performer))->render())->toContain('Performer Fundadora')->toContain('#1');
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
    $this->get('/waitlist/confirmar?t=' . $entry->invite_token);
    expect($entry->fresh()->confirmed_at->eq($first))->toBeTrue();

    $this->get('/waitlist/confirmar?t=bogus')->assertRedirect(route('landing'));
});

// ─── Referral attribution & suggested role ───────────────────────────────────

it('shows the referral banner, suggests the referrer role, and attributes the signup', function () {
    Mail::fake();
    $referrer = joinWaitlist(['name' => 'Rafael Souza', 'email' => 'raf@example.com', 'role' => 'performer']);

    $this->get('/convite/' . $referrer->invite_code)
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Landing')
            ->where('referral.name', 'Rafael')
            ->where('referral.suggestedRole', 'performer'));

    $this->post('/interesse', ['name' => 'Novo', 'email' => 'novo@example.com', 'role' => 'member', 'age_confirmed' => true]);

    $referred = WaitlistEntry::firstWhere('email', 'novo@example.com');
    expect($referred->referred_by)->toBe($referrer->id);
    // performer→member is a cross-role referral.
    expect(WaitlistReferral::where('referred_id', $referred->id)->value('referral_type'))->toBe('cross_role');
});

it('increments referral_count only after the referred person confirms', function () {
    $referrer = joinWaitlist(['email' => 'ref@example.com']);
    $referred = joinWaitlist(['email' => 'friend@example.com'], $referrer);

    expect($referrer->fresh()->referral_count)->toBe(0);
    app(WaitlistService::class)->confirm($referred);
    expect($referrer->fresh()->referral_count)->toBe(1);
});

it('never attributes a self-referral', function () {
    $referrer = joinWaitlist(['email' => 'self@example.com']);
    $again = joinWaitlist(['email' => 'self@example.com'], $referrer);

    expect($again->referred_by)->toBeNull();
    expect(WaitlistReferral::count())->toBe(0);
});

// ─── Member tiers ────────────────────────────────────────────────────────────

it('promotes a member tier by confirmed then converted same-role referrals', function () {
    $m = joinWaitlist(['email' => 'boss@example.com', 'role' => 'member']);

    // 3 confirmed same-role members => Supporter (threshold: 3 confirmed).
    foreach (range(1, 3) as $i) {
        referAndConfirm($m, "mem{$i}@example.com", 'member', "10.0.0.{$i}");
    }
    expect($m->fresh()->tier_member)->toBe(MemberTier::Supporter);

    // 1 of them converts to a real registration => Founder (threshold: 1 converted).
    app(WaitlistService::class)->convert(WaitlistEntry::firstWhere('email', 'mem1@example.com'));
    expect($m->fresh()->tier_member)->toBe(MemberTier::Founder);
});

it('promotes a member to Patron at 3 converted same-role referrals', function () {
    $m = joinWaitlist(['email' => 'patron@example.com', 'role' => 'member']);
    foreach (range(1, 3) as $i) {
        referAndConfirm($m, "c{$i}@example.com", 'member', "11.0.0.{$i}", convert: true);
    }

    expect($m->fresh()->tier_member)->toBe(MemberTier::Patron);
});

// ─── Performer tiers ─────────────────────────────────────────────────────────

it('promotes a performer tier by confirmed same-role performers', function () {
    $p = joinWaitlist(['email' => 'perf@example.com', 'role' => 'performer']);

    foreach (range(1, 2) as $i) {
        referAndConfirm($p, "perf{$i}@example.com", 'performer', "12.0.0.{$i}");
    }
    expect($p->fresh()->tier_performer)->toBe(PerformerTier::Pioneer);

    foreach (range(3, 5) as $i) {
        referAndConfirm($p, "perf{$i}@example.com", 'performer', "12.0.0.{$i}");
    }
    expect($p->fresh()->tier_performer)->toBe(PerformerTier::Founder);
});

it('promotes a performer to Ambassador via a converted cross-role member', function () {
    $p = joinWaitlist(['email' => 'amb@example.com', 'role' => 'performer']);
    referAndConfirm($p, 'themember@example.com', 'member', '13.0.0.1', convert: true);

    expect($p->fresh()->tier_performer)->toBe(PerformerTier::Ambassador);
});

// ─── Anti-fraud (IP cap) ─────────────────────────────────────────────────────

it('caps referrals from the same IP at 3 per 24h', function () {
    $referrer = joinWaitlist(['email' => 'farm@example.com']);
    $svc = app(WaitlistService::class);

    foreach (range(1, 5) as $i) {
        $svc->join(['name' => "Fake$i", 'email' => "fake{$i}@example.com", 'role' => 'member'], $referrer, '6.6.6.6');
    }

    expect(WaitlistEntry::where('email', 'like', 'fake%')->count())->toBe(5);
    expect(WaitlistReferral::count())->toBe(3);
});

// ─── Position separated by role ──────────────────────────────────────────────

it('numbers positions independently per role', function () {
    $m1 = joinWaitlist(['email' => 'm1@example.com', 'role' => 'member']);
    $p1 = joinWaitlist(['email' => 'p1@example.com', 'role' => 'performer']);
    $m2 = joinWaitlist(['email' => 'm2@example.com', 'role' => 'member']);
    $p2 = joinWaitlist(['email' => 'p2@example.com', 'role' => 'performer']);

    expect($m1->position_in_role)->toBe(1);
    expect($p1->position_in_role)->toBe(1);
    expect($m2->position_in_role)->toBe(2);
    expect($p2->position_in_role)->toBe(2);
});

// ─── Founder panel ───────────────────────────────────────────────────────────

it('renders the member founder panel with role title and tier', function () {
    $entry = joinWaitlist(['name' => 'Rafael Souza', 'email' => 'raf@example.com', 'role' => 'member']);

    $this->get('/f/' . $entry->invite_code)
        ->assertOk()
        ->assertSee('Painel de Rafael')
        ->assertSee('Membro Fundador')
        ->assertSee('Curioso')
        ->assertSee($entry->invite_code);
});

it('renders the performer founder panel with the feminine title', function () {
    $entry = joinWaitlist(['name' => 'Lia', 'email' => 'lia@example.com', 'role' => 'performer']);

    $this->get('/f/' . $entry->invite_code)->assertOk()->assertSee('Performer Fundadora');
});

it('404s the founder panel for an unknown invite code', function () {
    $this->get('/f/LIMEN-XXX-0000')->assertNotFound();
});

it('does not expose referred emails on the founder panel (only masked names)', function () {
    $referrer = joinWaitlist(['email' => 'ref@example.com']);
    joinWaitlist(['name' => 'Carla Menezes', 'email' => 'carla@example.com'], $referrer);

    $this->get('/f/' . $referrer->invite_code)
        ->assertSee('Carla M.')
        ->assertDontSee('carla@example.com');
});

// ─── Admin ───────────────────────────────────────────────────────────────────

it('blocks the admin waitlist page for guests and non-admins', function () {
    $this->get('/admin/waitlist')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'consumer']))->get('/admin/waitlist')->assertForbidden();
});

it('shows the admin waitlist dashboard to an admin', function () {
    $referrer = joinWaitlist(['email' => 'a@example.com']);
    referAndConfirm($referrer, 'b@example.com', 'member', '14.0.0.1');

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/admin/waitlist')
        ->assertOk()
        ->assertSee('Founding Members')
        ->assertSee('Coeficiente viral');
});

// ─── Unsubscribe (via stored invite_token) ───────────────────────────────────

it('renders the unsubscribe confirmation on GET without deleting', function () {
    $entry = joinWaitlist(['email' => 'rita@example.com']);

    $this->get('/waitlist/cancelar?t=' . $entry->invite_token)->assertOk()->assertSee('rita@example.com');
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
    $referrer = joinWaitlist(['email' => 'boss@example.com', 'role' => 'member']);
    $friend = referAndConfirm($referrer, 'friend@example.com', 'member', '15.0.0.1');
    expect($referrer->fresh()->referral_count)->toBe(1);

    $this->post('/waitlist/cancelar', ['token' => $friend->invite_token]);

    expect($referrer->fresh()->referral_count)->toBe(0);
    expect(WaitlistReferral::count())->toBe(0);
});
