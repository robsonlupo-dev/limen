<?php

use App\Exceptions\AlreadySubscribedException;
use App\Models\Circle;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\TokenLedger;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\SubscriptionService;

// O corte de founder é fail-closed: sem data configurada ninguém ganha trial.
// As entradas dos helpers confirmam 30 dias atrás, portanto dentro do corte.
beforeEach(function () {
    config(['waitlist.founder_cutoff_at' => now()->subDay()->toIso8601String()]);
});

// ─── Helpers ────────────────────────────────────────────────────────────────
// Nomes próprios do arquivo: os helpers de SubscriptionTest são funções globais
// e colidiriam ao rodar a suíte inteira.

function founderCard(): array
{
    return [
        'holderName' => 'Fulano de Tal',
        'number' => '5162306219378829',
        'expiryMonth' => '12',
        'expiryYear' => '2030',
        'ccv' => '123',
        'holder' => [
            'name' => 'Fulano de Tal',
            'email' => 'fulano@teste.com',
            'cpfCnpj' => '24971563792',
            'postalCode' => '01310000',
            'addressNumber' => '100',
            'phone' => '11999999999',
        ],
    ];
}

/** Entrada de waitlist para o email do usuário. $confirmed=false simula quem se
 *  cadastrou mas nunca clicou no link de confirmação. */
function waitlistEntryFor(User $user, bool $confirmed = true): WaitlistEntry
{
    $entry = new WaitlistEntry([
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'member',
        'age_confirmed' => true,
    ]);

    // confirmed_at não é fillable de propósito (ninguém forja founder por input).
    $entry->confirmed_at = $confirmed ? now()->subDays(30) : null;
    $entry->save();

    return $entry;
}

function trialCircle(): Circle
{
    return Circle::where('slug', 'prestige')->firstOrFail();
}

function lastSubscriptionPayload(): array
{
    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $payloads = $fake->getCreatedSubscriptionPayloads();

    return end($payloads) ?: [];
}

// ─── 1. Founding Member ganha o trial ────────────────────────────────────────

it('da 7 dias de trial ao Founding Member na primeira assinatura', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect($sub->trial_ends_at)->not->toBeNull()
        ->and($sub->trial_ends_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($sub->isInTrial())->toBeTrue();

    // O trial não atrasa os tokens: o primeiro mês entra na criação, como sempre.
    expect($sub->status)->toBe('active');
    expect($user->fresh()->tokenWallet->balance)->toBe(trialCircle()->monthly_tokens);
});

// ─── 2. O adiamento chega ao Asaas ───────────────────────────────────────────

it('envia nextDueDate 7 dias a frente ao Asaas quando e founder', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    $payload = lastSubscriptionPayload();

    // É ASSIM que o trial existe de verdade: a API de assinaturas do Asaas não
    // tem campo de trial — adiar a primeira cobrança é o mecanismo documentado.
    // Uma flag só nossa deixaria o cartão sendo debitado no dia 0.
    expect($payload['nextDueDate'])->toBe(now()->addDays(7)->format('Y-m-d'));
    expect($payload)->not->toHaveKey('trialPeriodDays');
});

// ─── 3. Não-founder segue como antes ─────────────────────────────────────────

it('NAO da trial a quem nao esta na waitlist e cobra hoje', function () {
    $user = User::factory()->create();

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect($sub->trial_ends_at)->toBeNull()
        ->and($sub->isInTrial())->toBeFalse();

    expect(lastSubscriptionPayload()['nextDueDate'])->toBe(now()->format('Y-m-d'));
});

// ─── 4. isInTrial() ao longo do tempo ────────────────────────────────────────

it('isInTrial retorna true durante e false depois do fim do trial', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    $this->travelTo(now()->addDays(6));
    expect($sub->fresh()->isInTrial())->toBeTrue();

    // Um minuto depois do fim: acabou.
    $this->travelTo($sub->trial_ends_at->copy()->addMinute());
    expect($sub->fresh()->isInTrial())->toBeFalse();

    $this->travelBack();
});

it('trial_ends_at nulo nunca conta como trial', function () {
    $sub = new Subscription(['trial_ends_at' => null]);

    expect($sub->isInTrial())->toBeFalse();
});

// ─── 5. Um trial por pessoa ──────────────────────────────────────────────────

it('founder com assinatura ativa continua bloqueado de assinar de novo', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect(fn () => app(SubscriptionService::class)
        ->subscribe($user->fresh(), Circle::where('slug', 'explorador')->firstOrFail(), founderCard()))
        ->toThrow(AlreadySubscribedException::class);

    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
});

it('founder que cancelou e volta NAO ganha um segundo trial', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $first = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());
    expect($first->trial_ends_at)->not->toBeNull();

    // Encerra de fato a assinatura (libera o active_lock) e reassina.
    $first->update(['status' => 'canceled', 'canceled_at' => now()]);

    $second = app(SubscriptionService::class)->subscribe($user->fresh(), trialCircle(), founderCard());

    // Sem esta regra, cancelar e reassinar seria trial infinito.
    expect($second->trial_ends_at)->toBeNull()
        ->and($second->isInTrial())->toBeFalse();
    expect(lastSubscriptionPayload()['nextDueDate'])->toBe(now()->format('Y-m-d'));
});

// ─── 6. Waitlist não confirmada não é founder ────────────────────────────────

it('entrada de waitlist sem confirmed_at nao e Founding Member', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user, confirmed: false);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect($sub->trial_ends_at)->toBeNull()
        ->and($sub->isInTrial())->toBeFalse();
    expect(lastSubscriptionPayload()['nextDueDate'])->toBe(now()->format('Y-m-d'));
});

it('waitlist confirmada de OUTRO email nao da trial', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    waitlistEntryFor($other);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect($sub->trial_ends_at)->toBeNull();
});

// ─── Travas anti-fraude (revisão de segurança) ───────────────────────────────

it('sem FOUNDER_CUTOFF_AT configurado ninguem ganha trial', function () {
    config(['waitlist.founder_cutoff_at' => null]);

    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    // Fail-closed: melhor não dar o brinde do que abrir torneira de tokens.
    expect($sub->trial_ends_at)->toBeNull();
});

it('quem confirmou a waitlist DEPOIS do corte nao e founder', function () {
    $user = User::factory()->create();
    $entry = waitlistEntryFor($user);
    $entry->confirmed_at = now(); // depois do corte (ontem)
    $entry->save();

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    expect($sub->trial_ends_at)->toBeNull();
    expect(lastSubscriptionPayload()['nextDueDate'])->toBe(now()->format('Y-m-d'));
});

it('email nao verificado nao ganha o trial do founder', function () {
    $user = User::factory()->unverified()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    // Sem isto, registrar com o email de um founder levaria o trial dele.
    expect($sub->trial_ends_at)->toBeNull();
});

// ─── Trial que não converte ──────────────────────────────────────────────────

it('cobranca do trial vencida cancela a assinatura em vez de deixar past_due', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());
    $granted = $user->fresh()->tokenWallet->balance;

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    app(SubscriptionService::class)->handleWebhook(
        $fake->simulateSubscriptionOverdue($sub->asaas_subscription_id),
    );

    $sub->refresh();
    expect($sub->status)->toBe('canceled')
        ->and($sub->canceled_at)->not->toBeNull()
        // PCI: o token reusável do cartão é expurgado no encerramento.
        ->and($sub->card_token)->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.trial_payment_failed']);

    // Os tokens do dia 0 não voltam (ledger append-only), mas não vêm mais.
    expect($user->fresh()->tokenWallet->balance)->toBe($granted);
});

it('confirmacao atrasada NAO ressuscita assinatura cancelada nem concede tokens', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());
    $granted = $user->fresh()->tokenWallet->balance;

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    app(SubscriptionService::class)->handleWebhook(
        $fake->simulateSubscriptionOverdue($sub->asaas_subscription_id),
    );

    // Cobrança confirmada chegando depois do corte: não pode reabrir a torneira.
    app(SubscriptionService::class)->handleWebhook(
        $fake->simulateSubscriptionCharged($sub->asaas_subscription_id),
    );

    expect($sub->fresh()->status)->toBe('canceled');
    expect($user->fresh()->tokenWallet->balance)->toBe($granted);

    $late = SubscriptionCharge::where('subscription_id', $sub->id)->latest('id')->first();
    expect($late->status)->toBe('superseded');

    // Um único grant no ledger: o do dia 0.
    expect(TokenLedger::where('wallet_id', $user->fresh()->tokenWallet->id)
        ->where('entry_type', 'subscription_grant')->count())->toBe(1);
});

it('renovacao vencida fora do trial continua indo para past_due', function () {
    $user = User::factory()->create(); // não-founder: sem trial
    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    app(SubscriptionService::class)->handleWebhook(
        $fake->simulateSubscriptionOverdue($sub->asaas_subscription_id),
    );

    // Comportamento pré-existente preservado: past_due se recupera na renovação.
    expect($sub->fresh()->status)->toBe('past_due');
});

// ─── Janela de acesso durante o trial ────────────────────────────────────────

it('o periodo pago do founder comeca no fim do trial, sem buraco de acesso', function () {
    $user = User::factory()->create();
    waitlistEntryFor($user);

    $sub = app(SubscriptionService::class)->subscribe($user, trialCircle(), founderCard());

    // Tokens do 1o mês entram hoje, mas a 1a cobrança é no dia 7 e a renovação
    // seguinte no dia 37: o período tem de ir até lá, senão isActive() cairia
    // entre o dia 30 e o dia 37.
    expect($sub->current_period_end->isSameDay(now()->addDays(7)->addMonthNoOverflow()))->toBeTrue();
    expect($sub->next_due_date->format('Y-m-d'))->toBe(now()->addDays(7)->format('Y-m-d'));

    $this->travelTo(now()->addDays(31));
    expect($sub->fresh()->isActive())->toBeTrue();
    $this->travelBack();
});
