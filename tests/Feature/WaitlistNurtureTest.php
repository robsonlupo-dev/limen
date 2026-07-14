<?php

use App\Mail\WaitlistNurtureMail;
use App\Models\WaitlistEmailLog;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistNurtureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Join, confirm, and backdate confirmed_at so the entry looks like it confirmed
 * $daysAgo days ago — the anchor the drip measures cadence from.
 */
function confirmedEntry(string $role, string $email, int $daysAgo): WaitlistEntry
{
    $svc = app(App\Services\Waitlist\WaitlistService::class);
    $extra = $role === 'performer' ? ['world' => 'mulheres'] : [];
    $entry = $svc->join(array_merge(['name' => 'Nura Teste', 'email' => $email, 'role' => $role], $extra), null, '127.0.0.1')['entry'];
    $svc->confirm($entry);
    $entry->forceFill(['confirmed_at' => now()->subDays($daysAgo)])->save();

    return $entry->fresh();
}

function dispatchNurture(): int
{
    return app(WaitlistNurtureService::class)->dispatchDue();
}

// ─── Sending & cadence ───────────────────────────────────────────────────────

it('queues the first step to a confirmed member one day after confirmation', function () {
    Mail::fake();
    $entry = confirmedEntry('member', 'ana@example.com', daysAgo: 1);

    $sent = dispatchNurture();

    expect($sent)->toBe(1);
    Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->hasTo('ana@example.com') && $m->stepKey === 'nurture_1');
    expect(WaitlistEmailLog::where('waitlist_entry_id', $entry->id)->where('email_key', 'nurture_1')->exists())->toBeTrue();
});

it('does not send anything before the first step cadence has elapsed', function () {
    Mail::fake();
    confirmedEntry('member', 'fresh@example.com', daysAgo: 0);

    expect(dispatchNurture())->toBe(0);
    Mail::assertNothingQueued();
});

it('never sends the drip to an unconfirmed entry', function () {
    Mail::fake();
    // Joined long ago but never confirmed → no double opt-in, no drip.
    $svc = app(App\Services\Waitlist\WaitlistService::class);
    $entry = $svc->join(['name' => 'No Confirm', 'email' => 'no@example.com', 'role' => 'member'], null, '127.0.0.1')['entry'];
    $entry->forceFill(['created_at' => now()->subDays(60)])->save();

    expect(dispatchNurture())->toBe(0);
    Mail::assertNothingQueued();
});

it('queues every step whose cadence has elapsed', function () {
    Mail::fake();
    // 8 days in: steps at 1, 3 and 7 days are all due; 14+ are not.
    confirmedEntry('member', 'week@example.com', daysAgo: 8);

    expect(dispatchNurture())->toBe(3);
    foreach (['nurture_1', 'nurture_2', 'nurture_3'] as $key) {
        Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->stepKey === $key);
    }
    Mail::assertNotQueued(WaitlistNurtureMail::class, fn ($m) => $m->stepKey === 'nurture_4');
});

// ─── Idempotency ─────────────────────────────────────────────────────────────

it('is idempotent — a second run does not resend an already-sent step', function () {
    Mail::fake();
    $entry = confirmedEntry('member', 'once@example.com', daysAgo: 3);

    expect(dispatchNurture())->toBe(2);   // nurture_1 + nurture_2
    expect(dispatchNurture())->toBe(0);   // nothing new
    Mail::assertQueued(WaitlistNurtureMail::class, 2);
    expect(WaitlistEmailLog::where('waitlist_entry_id', $entry->id)->count())->toBe(2);
});

it('sends a newly-due step on a later run without resending earlier ones', function () {
    Mail::fake();
    $entry = confirmedEntry('member', 'grow@example.com', daysAgo: 1);
    expect(dispatchNurture())->toBe(1); // nurture_1

    // Three more days pass (now 4 days in): nurture_2 becomes due.
    $entry->forceFill(['confirmed_at' => now()->subDays(4)])->save();
    expect(dispatchNurture())->toBe(1); // only nurture_2

    Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->stepKey === 'nurture_2');
    Mail::assertQueued(WaitlistNurtureMail::class, 2);
});

// ─── Unsubscribe halts the sequence ──────────────────────────────────────────

it('stops the drip for an entry that unsubscribed (deleted)', function () {
    Mail::fake();
    $entry = confirmedEntry('member', 'gone@example.com', daysAgo: 10);
    $entry->delete(); // unsubscribe deletes the entry (cascade removes its log)

    expect(dispatchNurture())->toBe(0);
    Mail::assertNothingQueued();
});

// ─── Config toggles ──────────────────────────────────────────────────────────

it('skips a step that is disabled in config', function () {
    Mail::fake();
    config(['waitlist.nurture' => [
        ['key' => 'nurture_1', 'after_days' => 1, 'enabled' => false],
        ['key' => 'nurture_2', 'after_days' => 3, 'enabled' => true],
    ]]);
    confirmedEntry('member', 'cfg@example.com', daysAgo: 5);

    expect(dispatchNurture())->toBe(1);
    Mail::assertNotQueued(WaitlistNurtureMail::class, fn ($m) => $m->stepKey === 'nurture_1');
    Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->stepKey === 'nurture_2');
});

// ─── Backfill guard & throttle ───────────────────────────────────────────────

it('does not enroll entries confirmed before the start_at floor', function () {
    Mail::fake();
    config(['waitlist.nurture_start_at' => now()->subDays(5)->toDateTimeString()]);
    // Confirmed 10 days ago — before the floor, so it must stay out of the drip.
    confirmedEntry('member', 'old@example.com', daysAgo: 10);

    expect(dispatchNurture())->toBe(0);
    Mail::assertNothingQueued();
});

it('enrolls entries confirmed at or after the start_at floor', function () {
    Mail::fake();
    config(['waitlist.nurture_start_at' => now()->subDays(5)->toDateTimeString()]);
    confirmedEntry('member', 'recent@example.com', daysAgo: 2); // after the floor

    expect(dispatchNurture())->toBe(1);
    Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->hasTo('recent@example.com'));
});

it('caps the number of emails queued per step per run', function () {
    Mail::fake();
    config(['waitlist.nurture_max_per_run' => 1]);
    confirmedEntry('member', 'a@example.com', daysAgo: 1);
    confirmedEntry('member', 'b@example.com', daysAgo: 1);

    expect(dispatchNurture())->toBe(1); // only one of the two this run
    expect(dispatchNurture())->toBe(1); // the other one next run
    Mail::assertQueued(WaitlistNurtureMail::class, 2);
});

// ─── Role-specific copy ──────────────────────────────────────────────────────

it('renders performer-specific copy and subject for a performer entry', function () {
    $entry = confirmedEntry('performer', 'perf@example.com', daysAgo: 1);
    $rendered = (new WaitlistNurtureMail($entry, 'nurture_1'))->render();

    expect($rendered)->toContain('Sua candidatura está guardada') // performer dia1 subject
        ->and($rendered)->toContain('Sua reserva está ativa');    // performer dia1 body
});

it('renders member-specific copy and subject for a member entry', function () {
    $entry = confirmedEntry('member', 'memb@example.com', daysAgo: 1);
    $rendered = (new WaitlistNurtureMail($entry, 'nurture_1'))->render();

    expect($rendered)->toContain('Você está dentro')          // member dia1 subject
        ->and($rendered)->toContain('Seu lugar está reservado'); // member dia1 body
});

// The feminine founder label appears only in the performer copy (dia 14/45).
it('uses the feminine founder label only for performers', function () {
    $perf = confirmedEntry('performer', 'pf@example.com', daysAgo: 1);
    $memb = confirmedEntry('member', 'mb@example.com', daysAgo: 1);

    expect((new WaitlistNurtureMail($perf, 'nurture_4'))->render())->toContain('painel de fundadora');
    expect((new WaitlistNurtureMail($memb, 'nurture_4'))->render())->not->toContain('fundadora');
});

// ─── CTA link (panel + role/day UTM) ─────────────────────────────────────────

it('points the CTA at the founder panel with a role- and day-specific UTM', function () {
    $member = confirmedEntry('member', 'mutm@example.com', daysAgo: 1);
    $perf = confirmedEntry('performer', 'putm@example.com', daysAgo: 1);

    // Day is the configured cadence (nurture_2 → dia3, nurture_3 → dia7).
    $memberHtml = (new WaitlistNurtureMail($member, 'nurture_2'))->render();
    expect($memberHtml)->toContain('/f/' . $member->invite_code)
        ->and($memberHtml)->toContain('utm_source=nurturing')
        ->and($memberHtml)->toContain('utm_medium=email')
        ->and($memberHtml)->toContain('utm_campaign=membro_dia3');

    expect((new WaitlistNurtureMail($perf, 'nurture_3'))->render())
        ->toContain('utm_campaign=performer_dia7');
});

// ─── Command wiring ──────────────────────────────────────────────────────────

it('dispatches due emails through the console command', function () {
    Mail::fake();
    confirmedEntry('member', 'cli@example.com', daysAgo: 1);

    $this->artisan('waitlist:send-nurture')->assertSuccessful();

    Mail::assertQueued(WaitlistNurtureMail::class, fn ($m) => $m->hasTo('cli@example.com'));
});
