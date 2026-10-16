<?php

use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEmailLog;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistNurtureService;
use App\Services\Waitlist\WaitlistStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Lembrete de 24h para quem não confirmou + reenviar confirmação + contagem no
 * admin (feat/waitlist-email). Prefixo `wcr` nos helpers.
 */
function wcrEntry(array $attrs = []): WaitlistEntry
{
    // confirmed_at e created_at NÃO são mass-assignable (program-controlled);
    // setados direto. O resto vai pelo fillable.
    $entry = new WaitlistEntry([
        'name' => $attrs['name'] ?? 'Lead Teste',
        'email' => $attrs['email'] ?? 'lead'.uniqid().'@example.com',
        'role' => $attrs['role'] ?? 'member',
        'age_confirmed' => true,
        'source' => 'landing',
    ]);
    $entry->invite_code = WaitlistEntry::generateInviteCode();
    $entry->invite_token = WaitlistEntry::generateInviteToken();
    $entry->position_in_role = 1;
    if (array_key_exists('confirmed_at', $attrs)) {
        $entry->confirmed_at = $attrs['confirmed_at'];
    }
    $entry->save();

    if (isset($attrs['created_at'])) {
        // created_at é gerenciado pelo Eloquent; forçar para simular a idade.
        WaitlistEntry::where('id', $entry->id)->update(['created_at' => $attrs['created_at']]);
        $entry->refresh();
    }

    return $entry;
}

it('manda UM lembrete para inscrição não confirmada com mais de 24h', function () {
    Mail::fake();
    $entry = wcrEntry(['created_at' => now()->subHours(30)]);

    $sent = app(WaitlistNurtureService::class)->dispatchConfirmationReminders();

    expect($sent)->toBe(1);
    Mail::assertQueued(WaitlistConfirmationMail::class, fn ($m) => $m->entry->is($entry) && $m->reminder === true);
    expect(WaitlistEmailLog::where('waitlist_entry_id', $entry->id)->where('email_key', 'confirm_reminder')->count())->toBe(1);
});

it('é idempotente: rodar de novo não manda o lembrete duas vezes', function () {
    Mail::fake();
    wcrEntry(['created_at' => now()->subHours(30)]);

    $svc = app(WaitlistNurtureService::class);
    expect($svc->dispatchConfirmationReminders())->toBe(1);
    expect($svc->dispatchConfirmationReminders())->toBe(0);
    Mail::assertQueued(WaitlistConfirmationMail::class, 1);
});

it('não cutuca quem já confirmou, quem tem menos de 24h, nem inscrição velha demais', function () {
    Mail::fake();
    wcrEntry(['created_at' => now()->subHours(30), 'confirmed_at' => now()]); // confirmado
    wcrEntry(['created_at' => now()->subHours(2)]);                            // recente
    wcrEntry(['created_at' => now()->subDays(10)]);                            // velho demais

    expect(app(WaitlistNurtureService::class)->dispatchConfirmationReminders())->toBe(0);
    Mail::assertNothingQueued();
});

it('respeita o interruptor de config', function () {
    Mail::fake();
    config()->set('waitlist.confirm_reminder.enabled', false);
    wcrEntry(['created_at' => now()->subHours(30)]);

    expect(app(WaitlistNurtureService::class)->dispatchConfirmationReminders())->toBe(0);
    Mail::assertNothingQueued();
});

// ── Reenviar confirmação ─────────────────────────────────────────────────────

it('reenvia a confirmação para uma inscrição não confirmada', function () {
    Mail::fake();
    $entry = wcrEntry(['email' => 'quero@example.com']);

    $this->post(route('waitlist.resend'), ['email' => 'quero@example.com'])
        ->assertRedirect(route('landing'))
        ->assertSessionHas('success');

    Mail::assertQueued(WaitlistConfirmationMail::class, fn ($m) => $m->entry->is($entry) && $m->reminder === false);
});

it('não reenvia para quem já confirmou, mas responde igual (anti-oráculo)', function () {
    Mail::fake();
    wcrEntry(['email' => 'ja@example.com', 'confirmed_at' => now()]);

    $this->post(route('waitlist.resend'), ['email' => 'ja@example.com'])
        ->assertRedirect(route('landing'))
        ->assertSessionHas('success');

    Mail::assertNothingQueued();
});

it('responde neutro para e-mail inexistente e não envia nada', function () {
    Mail::fake();

    $this->post(route('waitlist.resend'), ['email' => 'ninguem@example.com'])
        ->assertRedirect(route('landing'))
        ->assertSessionHas('success');

    Mail::assertNothingQueued();
});

it('o honeypot bloqueia o reenvio', function () {
    Mail::fake();
    wcrEntry(['email' => 'bot@example.com']);

    $this->post(route('waitlist.resend'), ['email' => 'bot@example.com', 'website' => 'http://spam'])
        ->assertRedirect(route('landing'));

    Mail::assertNothingQueued();
});

it('o formulário público de reenvio abre', function () {
    $this->get(route('waitlist.resend.show'))->assertOk()->assertSee('Reenviar');
});

// ── Admin ────────────────────────────────────────────────────────────────────

it('o resumo admin conta não confirmados, pendentes +24h e lembretes', function () {
    wcrEntry(['created_at' => now()->subHours(30)]);          // pendente +24h
    wcrEntry(['created_at' => now()->subHours(2)]);           // pendente recente
    wcrEntry(['created_at' => now()->subHours(30), 'confirmed_at' => now()]); // confirmado

    $summary = app(WaitlistStats::class)->adminSummary();

    expect($summary['unconfirmed'])->toBe(2)
        ->and($summary['pending_over_24h'])->toBe(1)
        ->and($summary)->toHaveKey('reminders_sent');
});
