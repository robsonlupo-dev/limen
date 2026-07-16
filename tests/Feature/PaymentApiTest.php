<?php

use App\Models\Payment;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;

function createActivePackage(array $overrides = []): TokenPackage
{
    return TokenPackage::create(array_merge([
        'slug' => 'test-' . uniqid(),
        'name' => 'Test Package',
        'tokens' => 500,
        'price_cents' => 4990,
        'active' => true,
        'sort_order' => 1,
    ], $overrides));
}

function authenticatedUser(): array
{
    $user = User::factory()->create();
    TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);
    $token = $user->createToken('api')->plainTextToken;

    return [$user, $token];
}

// 1. List packages -> only active
it('lists only active token packages', function () {
    createActivePackage(['slug' => 'active-pkg', 'active' => true]);
    createActivePackage(['slug' => 'inactive-pkg', 'active' => false]);

    [$user, $token] = authenticatedUser();

    $response = $this->getJson('/api/v1/token-packages', ['Authorization' => "Bearer $token"]);

    $response->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug');
    expect($slugs)->toContain('active-pkg');
    expect($slugs)->not->toContain('inactive-pkg');
});

// 2. Create payment -> pending + QR + values from server
it('creates a pending payment with QR code using server-side values', function () {
    $package = createActivePackage(['tokens' => 500, 'price_cents' => 4990]);
    [$user, $token] = authenticatedUser();

    $response = $this->postJson('/api/v1/payments', [
        'token_package_id' => $package->id,
        'cpf' => '529.982.247-25',
        'tokens' => 99999,
        'amount_cents' => 1,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.tokens', 500)
        ->assertJsonPath('data.amount_cents', 4990);

    expect($response->json('data.pix_qr_code'))->not->toBeEmpty();
    expect($response->json('data.pix_copy_paste'))->not->toBeEmpty();

    $this->assertDatabaseHas('payments', [
        'user_id' => $user->id,
        'tokens' => 500,
        'amount_cents' => 4990,
        'status' => 'pending',
    ]);
});

// 3. Webhook with wrong/missing auth token -> 401, nothing credited
it('rejects webhook with wrong auth token', function () {
    config(['asaas.webhook_token' => 'correct-token']);

    $response = $this->postJson('/api/v1/webhooks/asaas', [
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => 'pay_123'],
    ], ['asaas-access-token' => 'wrong-token']);

    $response->assertStatus(401);

    expect(TokenLedger::count())->toBe(0);
});

// 4. Valid PAYMENT_RECEIVED webhook -> payment confirmed, tokens credited, audit log
it('confirms payment and credits tokens on valid webhook', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $fake = app(AsaasClientInterface::class);
    $charge = $fake->createPixCharge([
        'customer' => 'cus_fake',
        'billingType' => 'PIX',
        'value' => $package->price_cents / 100,
        'dueDate' => now()->addDay()->format('Y-m-d'),
    ]);
    $fake->simulatePaymentReceived($charge['id']);

    $payment = Payment::create([
        'user_id' => $user->id,
        'token_package_id' => $package->id,
        'provider' => 'asaas',
        'provider_charge_id' => $charge['id'],
        'method' => 'pix',
        'amount_cents' => $package->price_cents,
        'tokens' => $package->tokens,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    config(['asaas.webhook_token' => 'valid-token']);

    $response = $this->postJson('/api/v1/webhooks/asaas', [
        'id' => 'evt_unique_001',
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => $charge['id']],
    ], ['asaas-access-token' => 'valid-token']);

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe('confirmed');
    expect($payment->confirmed_at)->not->toBeNull();

    $wallet = TokenWallet::where('user_id', $user->id)->first();
    expect($wallet->balance)->toBe($package->tokens);

    $ledger = TokenLedger::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->entry_type)->toBe('purchase');
    expect($ledger->amount)->toBe($package->tokens);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment.confirmed',
    ]);
});

// 5. Same event.id twice (idempotency) -> credits only once
it('does not double-credit on duplicate webhook event', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $fake = app(AsaasClientInterface::class);
    $charge = $fake->createPixCharge([
        'customer' => 'cus_fake',
        'billingType' => 'PIX',
        'value' => $package->price_cents / 100,
        'dueDate' => now()->addDay()->format('Y-m-d'),
    ]);
    $fake->simulatePaymentReceived($charge['id']);

    $payment = Payment::create([
        'user_id' => $user->id,
        'token_package_id' => $package->id,
        'provider' => 'asaas',
        'provider_charge_id' => $charge['id'],
        'method' => 'pix',
        'amount_cents' => $package->price_cents,
        'tokens' => $package->tokens,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    config(['asaas.webhook_token' => 'valid-token']);
    $headers = ['asaas-access-token' => 'valid-token'];

    $payload = [
        'id' => 'evt_idem_001',
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => $charge['id']],
    ];

    $this->postJson('/api/v1/webhooks/asaas', $payload, $headers)->assertOk();

    app(\Illuminate\Contracts\Auth\Guard::class);
    auth()->forgetGuards();

    $this->postJson('/api/v1/webhooks/asaas', $payload, $headers)->assertOk();

    $wallet = TokenWallet::where('user_id', $user->id)->first();
    expect($wallet->balance)->toBe($package->tokens);

    $ledgerCount = TokenLedger::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->count();
    expect($ledgerCount)->toBe(1);
});

// 6. Webhook for unknown charge -> handled gracefully
it('handles webhook for unknown charge without error', function () {
    config(['asaas.webhook_token' => 'valid-token']);

    $response = $this->postJson('/api/v1/webhooks/asaas', [
        'id' => 'evt_unknown_001',
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => 'pay_nonexistent'],
    ], ['asaas-access-token' => 'valid-token']);

    $response->assertOk();

    $this->assertDatabaseHas('payment_events', [
        'provider_event_id' => 'evt_unknown_001',
    ]);
});

// 7. GET /payments/{id} of another user -> 403
it('blocks viewing another users payment', function () {
    [$owner, $ownerToken] = authenticatedUser();
    [$other, $otherToken] = authenticatedUser();

    $payment = Payment::create([
        'user_id' => $owner->id,
        'provider' => 'asaas',
        'provider_charge_id' => 'pay_other_user',
        'method' => 'pix',
        'amount_cents' => 4990,
        'tokens' => 500,
        'status' => 'pending',
    ]);

    auth()->forgetGuards();

    $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => "Bearer $otherToken"])
        ->assertStatus(403);
});

// 8. Reconcile credits pending that Asaas reports paid (missed webhook)
it('reconciles pending payment that Asaas reports as received', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $fake = app(AsaasClientInterface::class);
    $charge = $fake->createPixCharge([
        'customer' => 'cus_fake',
        'billingType' => 'PIX',
        'value' => $package->price_cents / 100,
        'dueDate' => now()->addDay()->format('Y-m-d'),
    ]);

    $payment = Payment::create([
        'user_id' => $user->id,
        'token_package_id' => $package->id,
        'provider' => 'asaas',
        'provider_charge_id' => $charge['id'],
        'method' => 'pix',
        'amount_cents' => $package->price_cents,
        'tokens' => $package->tokens,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $fake->simulatePaymentReceived($charge['id']);

    $this->artisan('payments:reconcile')->assertSuccessful();

    $payment->refresh();
    expect($payment->status)->toBe('confirmed');

    $wallet = TokenWallet::where('user_id', $user->id)->first();
    expect($wallet->balance)->toBe($package->tokens);
});

// 9. Pending expired -> marked expired by reconcile
it('marks expired pending payments during reconcile', function () {
    [$user, $token] = authenticatedUser();

    $fake = app(AsaasClientInterface::class);
    $charge = $fake->createPixCharge([
        'customer' => 'cus_fake',
        'billingType' => 'PIX',
        'value' => 49.90,
        'dueDate' => now()->subDay()->format('Y-m-d'),
    ]);

    $fake->simulatePaymentOverdue($charge['id']);

    $payment = Payment::create([
        'user_id' => $user->id,
        'provider' => 'asaas',
        'provider_charge_id' => $charge['id'],
        'method' => 'pix',
        'amount_cents' => 4990,
        'tokens' => 500,
        'status' => 'pending',
        'expires_at' => now()->subHour(),
    ]);
    $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $this->artisan('payments:reconcile')->assertSuccessful();

    $payment->refresh();
    expect($payment->status)->toBe('expired');
});

// 10. Create payment for inactive/nonexistent package -> 422
it('rejects payment for inactive or nonexistent package', function () {
    $inactive = createActivePackage(['active' => false]);
    [$user, $token] = authenticatedUser();

    $this->postJson('/api/v1/payments', [
        'token_package_id' => $inactive->id,
    ], ['Authorization' => "Bearer $token"])->assertStatus(422);

    auth()->forgetGuards();

    $this->postJson('/api/v1/payments', [
        'token_package_id' => 99999,
    ], ['Authorization' => "Bearer $token"])->assertStatus(422);
});

// --- Fase 3.1: CPF para Asaas ---

// 11. Primeira compra sem CPF -> 422
it('rejects first purchase without cpf when user has no asaas_customer_id', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $this->postJson('/api/v1/payments', [
        'token_package_id' => $package->id,
    ], ['Authorization' => "Bearer $token"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cpf']);
});

// 12. CPF inválido -> 422
it('rejects invalid cpf on first purchase', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $this->postJson('/api/v1/payments', [
        'token_package_id' => $package->id,
        'cpf' => '111.111.111-11',
    ], ['Authorization' => "Bearer $token"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cpf']);
});

// 13. CPF válido -> cria customer no Asaas e cobrança; CPF não vaza em resposta nem em log
it('creates asaas customer and charge with valid cpf without leaking cpf', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $loggedMessages = [];
    \Illuminate\Support\Facades\Log::listen(function (\Illuminate\Log\Events\MessageLogged $e) use (&$loggedMessages) {
        $loggedMessages[] = json_encode([$e->message, $e->context]);
    });

    $validCpf = '529.982.247-25';
    $cleanCpf = '52998224725';

    $response = $this->postJson('/api/v1/payments', [
        'token_package_id' => $package->id,
        'cpf' => $validCpf,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201);

    $responseBody = json_encode($response->json());
    expect($responseBody)->not->toContain($validCpf);
    expect($responseBody)->not->toContain($cleanCpf);

    expect($user->fresh()->asaas_customer_id)->not->toBeNull();

    $this->assertDatabaseHas('payments', [
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    foreach ($loggedMessages as $msg) {
        expect($msg)->not->toContain($cleanCpf);
        expect($msg)->not->toContain($validCpf);
    }

    /** @var \App\Services\Asaas\FakeAsaasClient $fake */
    $fake = app(\App\Services\Asaas\AsaasClientInterface::class);
    $customers = $fake->getCreatedCustomers();
    expect($customers)->toHaveCount(1);
    expect($customers[0]['cpfCnpj'])->toBe($cleanCpf);
});

// 14. Segunda compra reutiliza customer sem exigir CPF
it('reuses existing asaas customer on second purchase without requiring cpf', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    $user->asaas_customer_id = 'cus_existing_123';
    $user->save();

    $response = $this->postJson('/api/v1/payments', [
        'token_package_id' => $package->id,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201);

    expect($user->fresh()->asaas_customer_id)->toBe('cus_existing_123');

    /** @var \App\Services\Asaas\FakeAsaasClient $fake */
    $fake = app(\App\Services\Asaas\AsaasClientInterface::class);
    expect($fake->getCreatedCustomers())->toHaveCount(0);
});

// 15. Falha transitória do getPayment no webhook não engole o crédito: o evento
// fica sem processar e o reconcile credita depois (regressão da Etapa 3 — cliente HTTP real).
it('leaves the event unprocessed when confirm fails at the gateway so reconcile recovers', function () {
    $package = createActivePackage();
    [$user, $token] = authenticatedUser();

    // Charge conhecido pelo fake e marcado como pago (usado só no reconcile).
    $fake = new \App\Services\Asaas\FakeAsaasClient();
    $charge = $fake->createPixCharge([
        'value' => $package->price_cents / 100,
        'dueDate' => now()->addDay()->format('Y-m-d'),
    ]);
    $fake->simulatePaymentReceived($charge['id']);

    $payment = Payment::create([
        'user_id' => $user->id,
        'token_package_id' => $package->id,
        'provider' => 'asaas',
        'provider_charge_id' => $charge['id'],
        'method' => 'pix',
        'amount_cents' => $package->price_cents,
        'tokens' => $package->tokens,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    // Age it past the reconcile threshold (bypass Eloquent timestamp handling).
    Payment::where('id', $payment->id)->update(['created_at' => now()->subMinutes(10)]);

    // Cliente que estoura ao reconsultar a cobrança (getPayment), como um timeout do Asaas.
    $throwing = new class implements \App\Services\Asaas\AsaasClientInterface {
        public function createCustomer(array $data): array { return ['id' => 'cus_x']; }
        public function createPixCharge(array $data): array { return ['id' => 'pay_x']; }
        public function getPixQrCode(string $chargeId): array { return ['encodedImage' => '', 'payload' => '']; }
        public function getPayment(string $chargeId): array { throw new \RuntimeException('Asaas API error: HTTP 503'); }
        public function createTransfer(array $data): array { return ['id' => 'tr_x']; }
        public function getTransfer(string $transferId): array { return ['id' => $transferId, 'status' => 'PENDING']; }
        public function findTransfersByExternalReference(string $externalReference): array { return ['data' => []]; }
        public function createSubscription(array $data): array { return ['id' => 'sub_x']; }
        public function getSubscription(string $subscriptionId): array { return ['id' => $subscriptionId]; }
        public function getSubscriptionPayments(string $subscriptionId): array { return ['data' => []]; }
        public function cancelSubscription(string $subscriptionId): array { return ['id' => $subscriptionId, 'deleted' => true]; }
    };
    app()->instance(\App\Services\Asaas\AsaasClientInterface::class, $throwing);

    config(['asaas.webhook_token' => 'valid-token']);

    $response = $this->postJson('/api/v1/webhooks/asaas', [
        'id' => 'evt_transient_001',
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => $charge['id']],
    ], ['asaas-access-token' => 'valid-token']);

    // Webhook responde 200 (não força retries do Asaas), mas nada foi creditado
    // e o evento fica sem processed_at para o reconcile assumir.
    $response->assertOk();
    expect($payment->fresh()->status)->toBe('pending');
    expect(TokenLedger::where('reference_type', 'payment')->where('reference_id', $payment->id)->count())->toBe(0);

    $this->assertDatabaseHas('payment_events', [
        'provider_event_id' => 'evt_transient_001',
        'processed_at' => null,
    ]);

    // Reconcile com o gateway saudável credita — uma única vez.
    app()->instance(\App\Services\Asaas\AsaasClientInterface::class, $fake);
    app(\App\Services\PaymentService::class)->reconcile();

    expect($payment->fresh()->status)->toBe('confirmed');
    expect(TokenLedger::where('reference_type', 'payment')->where('reference_id', $payment->id)->count())->toBe(1);
    expect(TokenWallet::where('user_id', $user->id)->first()->balance)->toBe($package->tokens);
});
