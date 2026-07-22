<?php

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\SubscriptionService;

// ─── Helpers ────────────────────────────────────────────────────────────────

function expiryFake(): FakeAsaasClient
{
    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);

    return $fake;
}

/**
 * Assinatura local espelhada por uma assinatura de verdade no fake, para dar
 * para conferir se o cancelamento chegou ao gateway (e não só ao nosso banco).
 */
function subscriptionAtGateway(array $overrides = []): Subscription
{
    $remote = expiryFake()->createSubscription(['value' => 389.90, 'cycle' => 'MONTHLY']);

    return Subscription::factory()->create(array_merge([
        'asaas_subscription_id' => $remote['id'],
    ], $overrides));
}

function runExpiry(): void
{
    test()->artisan('subscriptions:expire')->assertSuccessful();
}

// ─── 1. O caso que a flag prometia e ninguém cumpria ─────────────────────────

it('encerra a assinatura cancelada cujo periodo pago ja acabou', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'status' => 'active',
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    runExpiry();

    $sub->refresh();
    expect($sub->status)->toBe('canceled')
        ->and($sub->canceled_at)->not->toBeNull();

    // E, principalmente, morreu no gateway: é isso que para de cobrar o membro.
    expect(expiryFake()->getSubscription($sub->asaas_subscription_id)['status'])->toBe('INACTIVE');
});

// ─── 2. Ainda dentro do período pago ─────────────────────────────────────────

it('NAO encerra enquanto o periodo pago nao terminou', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'status' => 'active',
        'current_period_end' => now()->addDays(10),
        'next_due_date' => now()->addDays(10),
    ]);

    runExpiry();

    // Cancelou, mas pagou até lá: o acesso é dele até a próxima cobrança.
    expect($sub->fresh()->status)->toBe('active');
    expect(expiryFake()->getSubscription($sub->asaas_subscription_id)['status'])->toBe('ACTIVE');
});

// ─── 3. Quem não cancelou não é tocado ───────────────────────────────────────

it('NAO toca em assinatura sem cancel_at_period_end mesmo com periodo vencido', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => false,
        'status' => 'active',
        'current_period_end' => now()->subDays(3),
        'next_due_date' => now()->subDays(3),
    ]);

    runExpiry();

    // Período vencido sem pedido de cancelamento é assunto da renovação
    // (webhook/past_due), não deste comando.
    expect($sub->fresh()->status)->toBe('active');
    expect(expiryFake()->getSubscription($sub->asaas_subscription_id)['status'])->toBe('ACTIVE');
});

// ─── 4. Idempotência ─────────────────────────────────────────────────────────

it('nao reprocessa assinatura ja cancelada', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'status' => 'active',
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    runExpiry();
    $canceledAt = $sub->fresh()->canceled_at;

    // Segunda rodada: a linha não pode ser pega de novo nem ter o carimbo mexido.
    $this->travelTo(now()->addHour());
    runExpiry();

    expect($sub->fresh()->canceled_at->eq($canceledAt))->toBeTrue();
    expect(AuditLog::where('action', 'subscription.expired')
        ->where('subject_id', $sub->id)->count())->toBe(1);

    $this->travelBack();
});

// ─── 5. Falha no gateway ─────────────────────────────────────────────────────

it('falha no Asaas nao cancela localmente e a rodada seguinte resolve', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'status' => 'active',
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    expiryFake()->forceNextSubscriptionCancelFailure();
    runExpiry();

    // Marcar cancelado aqui sem ter cancelado lá é o pior desfecho: o membro
    // perde o acesso E continua sendo cobrado. Melhor não fazer nada.
    expect($sub->fresh()->status)->toBe('active');
    expect($sub->fresh()->canceled_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', [
        'action' => 'subscription.expired',
        'subject_id' => $sub->id,
    ]);

    // O comando não explode: erra a linha, segue o lote, tenta de novo em 1h.
    runExpiry();
    expect($sub->fresh()->status)->toBe('canceled');
});

it('uma linha com erro nao impede as outras do lote', function () {
    $bad = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);
    $good = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    expiryFake()->forceNextSubscriptionCancelFailure(); // derruba só a primeira

    runExpiry();

    $canceled = collect([$bad, $good])->map(fn ($s) => $s->fresh()->status)->filter(
        fn ($status) => $status === 'canceled',
    );

    expect($canceled)->toHaveCount(1);
});

// ─── 6. Rastro ───────────────────────────────────────────────────────────────

it('registra o audit log subscription.expired', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    runExpiry();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'subscription.expired',
        'subject_type' => (new Subscription)->getMorphClass(),
        'subject_id' => $sub->id,
    ]);
});

// ─── PCI: token do cartão ────────────────────────────────────────────────────

it('expurga o token do cartao ao encerrar', function () {
    $sub = subscriptionAtGateway([
        'cancel_at_period_end' => true,
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
        'card_token' => 'cctok_fake_expiry',
    ]);

    runExpiry();

    $sub->refresh();
    // Encerrada no gateway, o token nunca mais pode ser cobrado.
    expect($sub->card_token)->toBeNull()
        ->and($sub->card_last4)->toBe('1234'); // histórico permanece

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'card_token.purged',
        'subject_id' => $sub->id,
    ]);
});

// ─── Assinatura sem id no gateway ────────────────────────────────────────────

it('encerra localmente quando nao ha assinatura no Asaas para cancelar', function () {
    $sub = Subscription::factory()->create([
        'asaas_subscription_id' => null,
        'cancel_at_period_end' => true,
        'current_period_end' => now()->subDay(),
        'next_due_date' => now()->subDay(),
    ]);

    runExpiry();

    expect($sub->fresh()->status)->toBe('canceled');
});

// ─── Trial: o motivo de o corte ser next_due_date ────────────────────────────

it('founder que cancela no trial e encerrado na data da cobranca, nao no fim do periodo', function () {
    // Assinatura de founder no dia 0: cobrança no dia 7, período pago até o 37.
    $sub = subscriptionAtGateway([
        'trial_ends_at' => now()->addDays(7),
        'next_due_date' => now()->addDays(7),
        'current_period_end' => now()->addDays(7)->addMonthNoOverflow(),
        'cancel_at_period_end' => true, // cancelou no dia 0
    ]);

    // Dia 6: ainda não é a data da cobrança, nada acontece.
    $this->travelTo(now()->addDays(6));
    runExpiry();
    expect($sub->fresh()->status)->toBe('active');

    // Dia 8: passou a data da cobrança. Tem de estar morta no gateway — se
    // esperássemos o current_period_end (dia 37), o cartão já teria sido debitado.
    $this->travelTo(now()->addDays(2));
    runExpiry();

    expect($sub->fresh()->status)->toBe('canceled');
    expect(expiryFake()->getSubscription($sub->asaas_subscription_id)['status'])->toBe('INACTIVE');

    $this->travelBack();
});

it('assinatura normal nao muda de comportamento: as duas datas coincidem', function () {
    // Fora do trial next_due_date == current_period_end, então o corte novo é o
    // mesmo que o antigo. Este teste existe para travar essa equivalência.
    $sub = subscriptionAtGateway([
        'trial_ends_at' => null,
        'current_period_end' => now()->addDays(30),
        'next_due_date' => now()->addDays(30),
        'cancel_at_period_end' => true,
    ]);

    $this->travelTo(now()->addDays(29));
    runExpiry();
    expect($sub->fresh()->status)->toBe('active');

    $this->travelTo(now()->addDays(2));
    runExpiry();
    expect($sub->fresh()->status)->toBe('canceled');

    $this->travelBack();
});

// ─── Fim a fim: cancelar e ver a assinatura morrer ───────────────────────────

it('fluxo completo: membro cancela, mantem acesso, e o periodo vira encerramento', function () {
    $user = User::factory()->create();
    $sub = subscriptionAtGateway([
        'user_id' => $user->id,
        'current_period_end' => now()->addDays(5),
        'next_due_date' => now()->addDays(5),
    ]);

    app(SubscriptionService::class)->cancel($sub);

    // Durante o período pago nada muda: acesso preservado.
    runExpiry();
    expect($sub->fresh()->status)->toBe('active');
    expect($user->fresh()->activeSubscription())->not->toBeNull();

    // Passado o período, o comando encerra dos dois lados.
    $this->travelTo(now()->addDays(6));
    runExpiry();

    expect($sub->fresh()->status)->toBe('canceled');
    expect($user->fresh()->activeSubscription())->toBeNull();
    expect(expiryFake()->getSubscription($sub->asaas_subscription_id)['status'])->toBe('INACTIVE');

    $this->travelBack();
});
