<?php

use App\Events\CallReservationPerformerEntered;
use App\Events\CallReservationReminder;
use App\Events\CallReservationResolved;
use App\Exceptions\CallReservationException;
use App\Models\CallReservation;
use App\Models\CallSession;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\CallReservationService;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * Agendamento de chamada (feat/scheduled-call-v1). Evolução da chamada 1:1 do
 * PR #140: o membro reserva performer + horário, o sistema TRAVA um depósito, e na
 * hora os dois entram e a cobrança dos minutos 2+ é 100% o motor do #140.
 *
 * Reusa os helpers globais do PrivateCallTest: `schedKit()` (LiveKit parcial —
 * JWT real, rede stubada), `schedPerformer()` e `schedMember()`.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.call_enabled' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Helpers próprios (nome `sched*`) para não colidir com os globais do PrivateCallTest
 * quando a suíte inteira roda — mesma disciplina do PublicLiveTest vs PrivateCallTest.
 * LiveKit parcial: JWT/room name locais rodam de verdade, a REDE é stubada.
 */
function schedKit(): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function schedPerformer(int $price = 10, ?int $maxDuration = null, ?int $slotMinutes = null): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    $profile->forceFill([
        'call_price_per_minute' => $price,
        'call_max_duration_minutes' => $maxDuration,
        'call_slot_minutes' => $slotMinutes,
    ])->save();

    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function schedMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

/** Cria uma reserva pela via oficial (service), com o horário default seguro. */
function reserveVia(User $member, User $performer, ?Carbon $at = null): CallReservation
{
    return app(CallReservationService::class)->reserve(
        $member,
        $performer->performerProfile,
        $at ?? now()->addHours(3),
    );
}

// ── Reserva: trava do depósito ────────────────────────────────────────────────

it('reserva trava o depósito: debita o preço de 1 min e cria a reserva pending', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);

    $reservation = reserveVia($member, $performer);

    expect($reservation->status)->toBe('pending')
        ->and($reservation->deposit_tokens)->toBe(10)
        ->and($reservation->price_per_min_locked)->toBe(10);

    // Saldo caiu (tokens indisponíveis para outros gastos) e há linha no ledger.
    expect(app(TokenService::class)->balance($member))->toBe(90);
    $this->assertDatabaseHas('token_ledger', [
        'entry_type' => 'spend_call_reservation',
        'amount' => -10,
        'reference_type' => CallReservation::class,
        'reference_id' => $reservation->id,
    ]);
});

it('reserva com saldo insuficiente é rejeitada e nada é criado', function () {
    schedKit();
    $performer = schedPerformer(price: 50);
    $member = schedMember(balance: 10);

    expect(fn () => reserveVia($member, $performer))
        ->toThrow(CallReservationException::class);

    expect(CallReservation::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(10);
});

it('reserva para performer sem preço de chamada → unavailable', function () {
    schedKit();
    $performer = schedPerformer();
    $performer->performerProfile->forceFill(['call_price_per_minute' => null])->save();
    $member = schedMember(balance: 100);

    try {
        reserveVia($member, $performer);
        $this->fail('esperava CallReservationException');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::UNAVAILABLE);
    }
});

it('reserva fora da antecedência mínima/máxima → invalid_time', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);

    // Cedo demais (< min_lead 45min).
    try {
        reserveVia($member, $performer, now()->addMinutes(10));
        $this->fail('esperava invalid_time (cedo)');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::INVALID_TIME);
    }

    // Tarde demais (> max_lead 30 dias).
    try {
        reserveVia($member, $performer, now()->addDays(40));
        $this->fail('esperava invalid_time (tarde)');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::INVALID_TIME);
    }

    expect(CallReservation::count())->toBe(0);
});

// ── Teto de 5 por membro ──────────────────────────────────────────────────────

it('teto de 5 agendamentos ativos por membro é duro', function () {
    schedKit();
    $member = schedMember(balance: 1000);

    // 5 performers distintas em horários folgados → 5 reservas ativas.
    for ($i = 0; $i < 5; $i++) {
        $p = schedPerformer(price: 10);
        reserveVia($member, $p, now()->addDays(2)->addHours($i));
    }
    expect(CallReservation::where('member_id', $member->id)->active()->count())->toBe(5);

    $extra = schedPerformer(price: 10);
    try {
        reserveVia($member, $extra, now()->addDays(3));
        $this->fail('esperava limit');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::LIMIT);
    }
});

// ── Buffer de 5min entre slots da mesma performer ─────────────────────────────

it('buffer de 5min bloqueia horário colado e libera com folga', function () {
    schedKit();
    $performer = schedPerformer(price: 10); // slot default 15min
    $member = schedMember(balance: 1000);

    $base = now()->addHours(4)->startOfHour();
    reserveVia($member, $performer, $base); // bloco [base, base+15]

    // +18min: gap de 3min do fim do 1º bloco (<5) → colisão.
    try {
        reserveVia($member, $performer, $base->copy()->addMinutes(18));
        $this->fail('esperava slot_unavailable');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::SLOT_UNAVAILABLE);
    }

    // +20min: gap de 5min → livre.
    $ok = reserveVia($member, $performer, $base->copy()->addMinutes(20));
    expect($ok->status)->toBe('pending');
});

// ── Confirmação / recusa da performer ─────────────────────────────────────────

it('performer confirma a própria reserva pending', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer);

    app(CallReservationService::class)->confirm($performer, $reservation);

    expect($reservation->fresh()->status)->toBe('confirmed')
        ->and($reservation->fresh()->confirmed_at)->not->toBeNull();
});

it('confirmar reserva de OUTRA performer → 404 (anti-oráculo)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $other = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer);

    try {
        app(CallReservationService::class)->confirm($other, $reservation);
        $this->fail('esperava not_found');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::NOT_FOUND);
    }
});

it('performer recusa → refund integral ao membro, sem strike', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer); // saldo 90

    app(CallReservationService::class)->decline($performer, $reservation);

    expect($reservation->fresh()->status)->toBe('cancelled')
        ->and(app(TokenService::class)->balance($member))->toBe(100)
        ->and($performer->performerProfile->fresh()->noshow_strike_count)->toBe(0);
    $this->assertDatabaseHas('token_ledger', [
        'entry_type' => 'call_reservation_refund',
        'amount' => 10,
    ]);
});

// ── Cancelamento pelo membro ──────────────────────────────────────────────────

it('membro cancela pending → refund grátis', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer);

    app(CallReservationService::class)->cancel($member, $reservation);

    expect($reservation->fresh()->status)->toBe('cancelled')
        ->and(app(TokenService::class)->balance($member))->toBe(100);
});

it('membro cancela confirmada APÓS T-2h → forfeit à performer (no-show do membro)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    // Depois do compromisso (T-2h = agendado-2h): viaja para dentro de 1h do horário.
    Carbon::setTestNow(now()->addHours(2)->addMinutes(30));
    app(CallReservationService::class)->cancel($member, $reservation);

    expect($reservation->fresh()->status)->toBe('no_show_member')
        // Membro NÃO é reembolsado; a performer recebe 100% do depósito.
        ->and(app(TokenService::class)->balance($member))->toBe(90)
        ->and(app(TokenService::class)->balance($performer))->toBe(10);
    $this->assertDatabaseHas('token_ledger', [
        'entry_type' => 'call_noshow_credit',
        'amount' => 10,
        'applied_rate' => 100,
    ]);
});

// ── Cron: confirmação vencida ─────────────────────────────────────────────────

it('cron cancela+refund reserva não confirmada após a janela (idempotente)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3)); // deadline T-2h

    Carbon::setTestNow(now()->addHours(1)->addMinute()); // passou de T-2h sem confirmar
    $svc = app(CallReservationService::class);

    expect($svc->expireUnconfirmed())->toBe(1);
    expect($reservation->fresh()->status)->toBe('cancelled')
        ->and(app(TokenService::class)->balance($member))->toBe(100);

    // Reprocessar não refunda de novo.
    expect($svc->expireUnconfirmed())->toBe(0);
    expect(TokenLedger::where('entry_type', 'call_reservation_refund')->count())->toBe(1);
});

// ── Cron: no-show da performer (refund + strike) ──────────────────────────────

it('cron: performer confirma e não entra → refund + strike', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3)->addMinutes(3)); // scheduled + 3min, nenhuma entrada
    expect(app(CallReservationService::class)->resolveNoShows())->toBe(1);

    expect($reservation->fresh()->status)->toBe('no_show_performer')
        ->and(app(TokenService::class)->balance($member))->toBe(100)
        ->and($performer->performerProfile->fresh()->noshow_strike_count)->toBe(1);

    // Trilha auditável do strike (subject = performer, sem PII de membro).
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'reservation.performer_noshow_strike',
        'subject_type' => $performer->performerProfile->getMorphClass(),
        'subject_id' => $performer->performerProfile->id,
    ]);
});

it('3 strikes de no-show da performer chegam ao limiar de review', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $svc = app(CallReservationService::class);

    for ($i = 0; $i < 3; $i++) {
        $member = schedMember(balance: 100);
        $r = $svc->reserve($member, $performer->performerProfile, now()->addHours(3 + $i));
        $svc->confirm($performer, $r);
        Carbon::setTestNow(now()->addHours(3 + $i)->addMinutes(3));
        $svc->resolveNoShows();
        Carbon::setTestNow(); // reset para a próxima iteração agendar no futuro
    }

    expect($performer->performerProfile->fresh()->noshow_strike_count)->toBe(3)
        ->and((int) config('scheduled_call.strike_review_threshold'))->toBe(3);
});

// ── Cron: no-show do membro (depósito 100% performer) ─────────────────────────

it('cron: performer entra e membro não → no-show do membro (depósito à performer)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    // A performer entra na hora; o membro não aparece na janela de 2min.
    Carbon::setTestNow(now()->addHours(3));
    app(CallReservationService::class)->performerEnter($performer, $reservation);

    Carbon::setTestNow(now()->addMinutes(3)); // passou de scheduled + 2min
    expect(app(CallReservationService::class)->resolveNoShows())->toBe(1);

    expect($reservation->fresh()->status)->toBe('no_show_member')
        ->and(app(TokenService::class)->balance($performer))->toBe(10)
        ->and(app(TokenService::class)->balance($member))->toBe(90);
});

// ── Entrada dos dois lados + integração com a cobrança do #140 ────────────────

it('performer entra, membro entra: minuto 1 pago pelo depósito, chamada ativa', function () {
    schedKit();
    Event::fake([CallReservationPerformerEntered::class]);
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100); // saldo 90 após reserva
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3));
    app(CallReservationService::class)->performerEnter($performer, $reservation);
    Event::assertDispatched(CallReservationPerformerEntered::class);

    $bundle = app(CallReservationService::class)->memberEnter($member, $reservation);

    // Uma call_session ATIVA foi criada e ligada; o minuto 1 já está billed.
    $session = CallSession::find($bundle['call_id']);
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe('active')
        ->and($session->minutes_billed)->toBe(1)
        ->and($session->type)->toBe('private')
        ->and($reservation->fresh()->status)->toBe('completed')
        ->and($reservation->fresh()->call_session_id)->toBe($session->id);

    // Minuto 1: performer creditada 70% (call_credit), SEM novo débito do membro
    // (o saldo dele segue 90 — o depósito pagou o minuto 1).
    expect(app(TokenService::class)->balance($member))->toBe(90)
        ->and(app(TokenService::class)->balance($performer))->toBe(7);
    $this->assertDatabaseHas('token_ledger', [
        'entry_type' => 'call_credit',
        'amount' => 7,
        'applied_rate' => 70,
        'reference_type' => CallSession::class,
        'reference_id' => $session->id,
    ]);
});

it('após entrar, o heartbeat do #140 cobra o minuto 2 (70/30) na call_session ligada', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3));
    app(CallReservationService::class)->performerEnter($performer, $reservation);
    $bundle = app(CallReservationService::class)->memberEnter($member, $reservation);

    // 60s depois: o front do membro bate o heartbeat da ROTA EXISTENTE do #140.
    Carbon::setTestNow(now()->addSeconds(61));
    $this->actingAs($member)
        ->postJson(route('call.heartbeat', $bundle['call_id']))
        ->assertOk();

    // Minuto 2 cobrado: débito spend_call -10 do membro, +7 à performer.
    expect(app(TokenService::class)->balance($member))->toBe(80)
        ->and(app(TokenService::class)->balance($performer))->toBe(14);
    $this->assertDatabaseHas('token_ledger', [
        'entry_type' => 'spend_call',
        'amount' => -10,
        'reference_id' => $bundle['call_id'],
    ]);
});

it('membro entra duas vezes → mesma call_session, depósito creditado uma vez (idempotente)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3));
    app(CallReservationService::class)->performerEnter($performer, $reservation);
    $first = app(CallReservationService::class)->memberEnter($member, $reservation);
    $second = app(CallReservationService::class)->memberEnter($member, $reservation);

    expect($second['call_id'])->toBe($first['call_id']);
    expect(TokenLedger::where('entry_type', 'call_credit')->count())->toBe(1);
});

it('membro ocupado em outra chamada não entra no agendamento', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 200);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3));
    app(CallReservationService::class)->performerEnter($performer, $reservation);

    // O membro está numa OUTRA chamada 1:1 ativa (occupying).
    $busy = new CallSession([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $busy->forceFill(['type' => 'private', 'status' => 'active', 'minutes_billed' => 1, 'started_at' => now()])->save();

    try {
        app(CallReservationService::class)->memberEnter($member, $reservation);
        $this->fail('esperava not_joinable');
    } catch (CallReservationException $e) {
        expect($e->reason)->toBe(CallReservationException::NOT_JOINABLE);
    }
});

// ── Gates de rota (HTTP) ──────────────────────────────────────────────────────

it('só membro agenda: performer no endpoint de agendar → 403', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $target = schedPerformer(price: 10);

    $this->actingAs($performer)
        ->postJson(route('reservations.store', $target->performerProfile->id), [
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ])
        ->assertForbidden();
});

it('membro agenda pelo endpoint web → 201 e reserva pending', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);

    $res = $this->actingAs($member)
        ->postJson(route('reservations.store', $performer->performerProfile->id), [
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonStructure(['reservation_id', 'deposit_tokens', 'scheduled_at']);

    $this->assertDatabaseHas('call_reservations', [
        'id' => $res->json('reservation_id'),
        'member_id' => $member->id,
        'status' => 'pending',
    ]);
});

it('cancelar reserva de OUTRO membro → 404 (anti-oráculo)', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $owner = schedMember(balance: 100);
    $intruder = schedMember(balance: 100);
    $reservation = reserveVia($owner, $performer);

    $this->actingAs($intruder)
        ->postJson(route('reservations.cancel', $reservation->id))
        ->assertNotFound();
});

it('performer não confirma reserva pela porta errada: consumer no confirmar → 403', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer);

    $this->actingAs($member)
        ->postJson(route('performer.reservations.confirm', $reservation->id))
        ->assertForbidden();
});

// ── FanAlias na fila da performer (M.13.10) ───────────────────────────────────

it('a fila da performer mostra o membro só por FanAlias, nunca o id', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    reserveVia($member, $performer);

    $label = FanAlias::label($performer->performerProfile->id, $member->id);
    $items = \App\Support\CallReservationPresenter::forPerformer(
        CallReservation::where('member_id', $member->id)->first()
    );

    // A performer vê SÓ o FanAlias label; sem chave member_id/tier/balance/name.
    expect($items['member_label'])->toBe($label)
        ->and($items)->not->toHaveKey('member_id')
        ->and($items)->not->toHaveKey('member')
        ->and(array_keys($items))->not->toContain('tier');
});

// ── Reminder T-5min ───────────────────────────────────────────────────────────

it('cron dispara o aviso T-5min uma vez para os dois lados', function () {
    schedKit();
    Event::fake([CallReservationReminder::class]);
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    $reservation = reserveVia($member, $performer, now()->addHours(3));
    app(CallReservationService::class)->confirm($performer, $reservation);

    Carbon::setTestNow(now()->addHours(3)->subMinutes(4)); // dentro de T-5min
    expect(app(CallReservationService::class)->sendReminders())->toBe(1);
    Event::assertDispatchedTimes(CallReservationReminder::class, 2); // membro + performer

    // Reprocessar não reenvia.
    expect(app(CallReservationService::class)->sendReminders())->toBe(0);
});

// ── Telas Inertia (index) ─────────────────────────────────────────────────────

it('a tela do membro lista suas reservas e o saldo', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    reserveVia($member, $performer);

    $this->actingAs($member)
        ->get(route('reservations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Consumer/ScheduledCalls')
            ->has('reservations', 1)
            ->where('walletBalance', 90));
});

it('a tela da performer lista a fila e os strikes', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    reserveVia($member, $performer);

    $this->actingAs($performer)
        ->get(route('performer.reservations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Performer/ScheduledCalls')
            ->has('reservations', 1)
            ->where('strike_count', 0)
            ->where('strike_threshold', 3));
});

// ── Hard Delete varre as reservas nos dois sentidos ───────────────────────────

it('hard delete do membro apaga as reservas dele', function () {
    schedKit();
    $performer = schedPerformer(price: 10);
    $member = schedMember(balance: 100);
    reserveVia($member, $performer);

    app(\App\Services\DeletionService::class)->executeDeletion($member);

    expect(CallReservation::where('member_id', $member->id)->count())->toBe(0);
});
