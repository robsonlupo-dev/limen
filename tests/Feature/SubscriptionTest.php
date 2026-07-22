<?php

use App\Exceptions\AlreadySubscribedException;
use App\Models\Circle;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

function fakeCard(): array
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

function subService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

function balanceOf(User $user): int
{
    return TokenWallet::where('user_id', $user->id)->value('balance') ?? 0;
}

it('seeds the five circles with the locked values', function () {
    expect(Circle::count())->toBe(5);

    $explorador = Circle::where('slug', 'explorador')->first();
    expect($explorador->price_cents)->toBe(8990)
        ->and($explorador->monthly_tokens)->toBe(75)
        ->and($explorador->discount_pct)->toBe(10)
        ->and($explorador->seat_limit)->toBeNull()
        ->and($explorador->invite_only)->toBeFalse();

    $fc = Circle::where('slug', 'founders_circle')->first();
    expect($fc->price_cents)->toBe(149000)
        ->and($fc->monthly_tokens)->toBe(2500)
        ->and($fc->discount_pct)->toBe(50)
        ->and($fc->seat_limit)->toBe(100)
        ->and($fc->invite_only)->toBeTrue();
});

it('subscribes a user, grants the first month and records one charge', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();

    $sub = subService()->subscribe($user, $prestige, fakeCard());

    expect($sub->status)->toBe('active')
        ->and($sub->circle_id)->toBe($prestige->id)
        ->and($sub->asaas_subscription_id)->not->toBeNull();

    // Monthly tokens granted via the append-only ledger, entry_type subscription_grant.
    expect(balanceOf($user))->toBe(500);
    $entry = TokenLedger::where('entry_type', 'subscription_grant')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->amount)->toBe(500)
        ->and($entry->balance_after)->toBe(500);

    expect(SubscriptionCharge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('stores only card token + last4 + brand, never the PAN, and encrypts the token at rest', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();

    $sub = subService()->subscribe($user, $prestige, fakeCard());

    expect($sub->card_last4)->toBe('8829')
        ->and($sub->card_brand)->toBe('VISA')
        ->and($sub->card_token)->toStartWith('cctok_fake_');

    // The full PAN is never persisted anywhere on the row.
    $raw = DB::table('subscriptions')->where('id', $sub->id)->first();
    expect($raw->card_token)->not->toContain('5162306219378829') // not the PAN
        ->and($raw->card_token)->not->toBe($sub->card_token);     // encrypted at rest
    expect(json_encode($raw))->not->toContain('5162306219378829');
});

it('blocks a second active subscription for the same user', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $insider = Circle::where('slug', 'insider')->firstOrFail();

    subService()->subscribe($user, $prestige, fakeCard());

    expect(fn () => subService()->subscribe($user, $insider, fakeCard()))
        ->toThrow(AlreadySubscribedException::class);
});

it('enforces one-active-per-user at the database level', function () {
    $user = User::factory()->create();

    Subscription::factory()->for($user)->create(['status' => 'active']);

    expect(fn () => Subscription::factory()->for($user)->create(['status' => 'active']))
        ->toThrow(QueryException::class);
});

it('grants again on a renewal webhook and never double-grants a replayed event', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    expect(balanceOf($user))->toBe(500);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $payload = $fake->simulateSubscriptionCharged($sub->asaas_subscription_id);

    subService()->handleWebhook($payload);
    expect(balanceOf($user))->toBe(1000); // second month granted

    // Replaying the exact same webhook must not grant a third time.
    subService()->handleWebhook($payload);
    expect(balanceOf($user))->toBe(1000)
        ->and(SubscriptionCharge::where('subscription_id', $sub->id)->count())->toBe(2);
});

it('does not double-grant month 1 when the first charge webhook arrives (production path)', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    expect(balanceOf($user))->toBe(500);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    // The FIRST charge id (the one the initial grant was anchored on). Its own
    // PAYMENT_CONFIRMED webhook must not grant a second time.
    $firstChargeId = $fake->getSubscriptionPayments($sub->asaas_subscription_id)['data'][0]['id'];

    subService()->handleWebhook([
        'event' => 'PAYMENT_CONFIRMED',
        'id' => 'evt_first',
        'payment' => ['id' => $firstChargeId, 'subscription' => $sub->asaas_subscription_id, 'value' => 389.90],
    ]);

    expect(balanceOf($user))->toBe(500)
        ->and(SubscriptionCharge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('does not grant when the gateway does not confirm the charge (forged/unverified webhook)', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    // A charge id the gateway does not know as confirmed → getPayment() returns
    // PENDING → no grant, even though the webhook body claims CONFIRMED.
    subService()->handleWebhook([
        'event' => 'PAYMENT_CONFIRMED',
        'id' => 'evt_forged',
        'payment' => ['id' => 'pay_unknown_999', 'subscription' => $sub->asaas_subscription_id, 'value' => 389.90],
    ]);

    expect(balanceOf($user))->toBe(500)
        ->and(SubscriptionCharge::where('provider_event_id', 'pay_unknown_999')->exists())->toBeFalse();
});

it('supersedes a late charge on a lapsed subscription when the user already has a new active one', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $insider = Circle::where('slug', 'insider')->firstOrFail();

    $old = subService()->subscribe($user, $prestige, fakeCard());
    $old->update(['status' => 'past_due']); // lapsed; frees the active_lock

    $new = subService()->subscribe($user, $insider, fakeCard()); // now allowed
    $balanceAfterNew = balanceOf($user);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    // A late renewal payment lands on the OLD (past_due) subscription.
    subService()->handleWebhook($fake->simulateSubscriptionCharged($old->asaas_subscription_id));

    // No revive, no collision, no grant — the charge is recorded as superseded.
    $old->refresh();
    expect($old->status)->toBe('past_due')
        ->and($new->refresh()->status)->toBe('active')
        ->and(balanceOf($user))->toBe($balanceAfterNew)
        ->and(SubscriptionCharge::where('subscription_id', $old->id)->where('status', 'superseded')->exists())->toBeTrue();
});

it('marks the subscription past_due on an overdue webhook', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    subService()->handleWebhook($fake->simulateSubscriptionOverdue($sub->asaas_subscription_id));

    expect($sub->refresh()->status)->toBe('past_due');
    // No tokens granted on an overdue event.
    expect(balanceOf($user))->toBe(500);
});

it('cancels the subscription on a subscription-deleted webhook', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    subService()->handleWebhook($fake->simulateSubscriptionCanceled($sub->asaas_subscription_id));

    $sub->refresh();
    expect($sub->status)->toBe('canceled')
        ->and($sub->canceled_at)->not->toBeNull();
});

it('cancel() flags cancel_at_period_end without wiping the current period', function () {
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = subService()->subscribe($user, $prestige, fakeCard());

    subService()->cancel($sub);

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeTrue()
        ->and($sub->status)->toBe('active') // still active until period end
        ->and($sub->current_period_end->isFuture())->toBeTrue();
});

it('applies the active circle discount to token package price, not the token amount', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->circle('prestige')->create(); // 30% off

    $package = TokenPackage::create([
        'slug' => 'test-pack', 'name' => 'Test', 'tokens' => 300,
        'price_cents' => 1000, 'active' => true, 'sort_order' => 1,
    ]);

    $payment = app(PaymentService::class)->createPayment($user, $package);

    expect($payment->amount_cents)->toBe(700) // 1000 - 30%
        ->and($payment->tokens)->toBe(300);   // tokens unchanged
});

it('charges full price for a user with no active circle', function () {
    $user = User::factory()->create();
    $package = TokenPackage::create([
        'slug' => 'test-pack-2', 'name' => 'Test', 'tokens' => 300,
        'price_cents' => 1000, 'active' => true, 'sort_order' => 1,
    ]);

    $payment = app(PaymentService::class)->createPayment($user, $package);

    expect($payment->amount_cents)->toBe(1000);
});

// ── Middleware + gate ────────────────────────────────────────────────────────

it('circle middleware allows the required tier or higher and blocks below', function () {
    Route::middleware('circle:prestige')->get('/_t/circle', fn () => 'ok');

    $prestige = User::factory()->create();
    Subscription::factory()->for($prestige)->circle('prestige')->create();

    $black = User::factory()->create();
    Subscription::factory()->for($black)->circle('black')->create();

    $insider = User::factory()->create();
    Subscription::factory()->for($insider)->circle('insider')->create();

    $none = User::factory()->create();

    $this->actingAs($prestige)->get('/_t/circle')->assertOk();
    $this->actingAs($black)->get('/_t/circle')->assertOk();
    $this->actingAs($insider)->get('/_t/circle')->assertForbidden();
    $this->actingAs($none)->get('/_t/circle')->assertForbidden();
});

it('circle middleware treats an expired period as no active circle', function () {
    Route::middleware('circle')->get('/_t/any-circle', fn () => 'ok');

    $expired = User::factory()->create();
    Subscription::factory()->for($expired)->circle('prestige')->expired()->create();

    $this->actingAs($expired)->get('/_t/any-circle')->assertForbidden();
});

it('circle-active gate mirrors the middleware tiering', function () {
    $prestige = User::factory()->create();
    Subscription::factory()->for($prestige)->circle('prestige')->create();

    expect(Gate::forUser($prestige)->allows('circle-active'))->toBeTrue()
        ->and(Gate::forUser($prestige)->allows('circle-active', 'prestige'))->toBeTrue()
        ->and(Gate::forUser($prestige)->allows('circle-active', 'black'))->toBeFalse();

    $none = User::factory()->create();
    expect(Gate::forUser($none)->allows('circle-active'))->toBeFalse();
});
