<?php

use App\Models\Payment;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\PaymentService;
use App\Services\TokenService;
use Inertia\Testing\AssertableInertia as Assert;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeWalletConsumer(array $attrs = []): User
{
    $consumer = makeWebConsumer($attrs);
    $consumer->asaas_customer_id = 'cus_test_' . $consumer->id;
    $consumer->save();

    TokenWallet::firstOrCreate(['user_id' => $consumer->id], ['balance' => 0]);

    return $consumer;
}

function makeWalletPackage(array $overrides = []): TokenPackage
{
    return TokenPackage::create(array_merge([
        'slug' => 'wallet-test-' . uniqid(),
        'name' => 'Teste',
        'tokens' => 500,
        'bonus' => 75,
        'price_cents' => 4990,
        'active' => true,
        'sort_order' => 1,
    ], $overrides));
}

// ─── Acesso ─────────────────────────────────────────────────────────────────

it('consumer autenticado ve a wallet', function () {
    $consumer = makeWalletConsumer();

    $this->actingAs($consumer)
        ->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Consumer/Wallet/Index'));
});

it('performer nao acessa rota de consumer wallet', function () {
    [$performer] = makeWebPerformer();

    $this->actingAs($performer)
        ->get('/wallet')
        ->assertForbidden();
});

it('visitante e redirecionado para login', function () {
    $this->get('/wallet')
        ->assertRedirect(route('login'));
});

// ─── Pacotes ────────────────────────────────────────────────────────────────

it('lista apenas pacotes ativos', function () {
    $active = makeWalletPackage(['slug' => 'ativo-teste', 'active' => true]);
    makeWalletPackage(['slug' => 'inativo-teste', 'active' => false]);

    $consumer = makeWalletConsumer();

    $this->actingAs($consumer)
        ->get('/wallet')
        ->assertInertia(fn (Assert $page) => $page
            ->has('packages', 1)
            ->where('packages.0.slug', $active->slug)
        );
});

it('purchase rejeita package inativo', function () {
    $package = makeWalletPackage(['active' => false]);
    $consumer = makeWalletConsumer();

    $this->actingAs($consumer)
        ->postJson("/wallet/purchase/{$package->id}")
        ->assertStatus(404);
});

it('purchase retorna pix_code e qr_base64', function () {
    $package = makeWalletPackage();
    $consumer = makeWalletConsumer();

    $response = $this->actingAs($consumer)
        ->postJson("/wallet/purchase/{$package->id}");

    $response->assertOk()
        ->assertJsonStructure(['payment_id', 'pix_code', 'pix_qr_base64', 'expires_at']);

    expect($response->json('pix_code'))->not->toBeEmpty();
    expect($response->json('pix_qr_base64'))->not->toBeEmpty();
});

it('purchase nao aceita valor do request', function () {
    $package = makeWalletPackage(['tokens' => 500, 'price_cents' => 4990]);
    $consumer = makeWalletConsumer();

    $this->actingAs($consumer)
        ->postJson("/wallet/purchase/{$package->id}", [
            'tokens' => 999999,
            'amount_cents' => 1,
            'price_cents' => 1,
        ])
        ->assertOk();

    $this->assertDatabaseHas('payments', [
        'user_id' => $consumer->id,
        'tokens' => 500,
        'amount_cents' => 4990,
    ]);
});

it('purchase retorna mesmo charge se ja existe pending recente', function () {
    $package = makeWalletPackage();
    $consumer = makeWalletConsumer();

    $first = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");
    $second = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");

    $first->assertOk();
    $second->assertOk();

    expect($second->json('payment_id'))->toBe($first->json('payment_id'));
    expect(Payment::where('user_id', $consumer->id)->count())->toBe(1);
});

it('purchase cria novo charge quando pending anterior tem mais de 2 horas', function () {
    $package = makeWalletPackage();
    $consumer = makeWalletConsumer();

    $first = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");
    $first->assertOk();

    Payment::where('id', $first->json('payment_id'))
        ->update(['created_at' => now()->subHours(3)]);

    $second = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");
    $second->assertOk();

    expect($second->json('payment_id'))->not->toBe($first->json('payment_id'));
    expect(Payment::where('user_id', $consumer->id)->count())->toBe(2);
});

it('purchase nao credita tokens imediatamente', function () {
    $package = makeWalletPackage();
    $consumer = makeWalletConsumer();

    $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}")->assertOk();

    expect(TokenLedger::count())->toBe(0);
    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(0);
});

// ─── Polling ────────────────────────────────────────────────────────────────

it('pending retorna status correto para payment do proprio user', function () {
    $package = makeWalletPackage();
    $consumer = makeWalletConsumer();

    $purchase = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");
    $paymentId = $purchase->json('payment_id');

    $this->actingAs($consumer)
        ->getJson("/wallet/pending?payment_id={$paymentId}")
        ->assertOk()
        ->assertJson(['status' => 'pending']);
});

it('pending nao expoe payment de outro user', function () {
    $package = makeWalletPackage();
    $owner = makeWalletConsumer();
    $intruder = makeWalletConsumer();

    $purchase = $this->actingAs($owner)->postJson("/wallet/purchase/{$package->id}");
    $paymentId = $purchase->json('payment_id');

    $this->actingAs($intruder)
        ->getJson("/wallet/pending?payment_id={$paymentId}")
        ->assertStatus(404);
});

it('pending retorna balance atualizado quando status=paid', function () {
    $package = makeWalletPackage(['tokens' => 500]);
    $consumer = makeWalletConsumer();

    $purchase = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}");
    $paymentId = $purchase->json('payment_id');
    $payment = Payment::findOrFail($paymentId);

    $fake = app(AsaasClientInterface::class);
    $fake->simulatePaymentReceived($payment->provider_charge_id);
    app(PaymentService::class)->confirmPayment($payment);

    $response = $this->actingAs($consumer)
        ->getJson("/wallet/pending?payment_id={$paymentId}");

    $response->assertOk()->assertJson([
        'status' => 'paid',
        'balance' => 500,
    ]);
});

// ─── Histórico ──────────────────────────────────────────────────────────────

it('history lista entradas do ledger do consumer paginadas', function () {
    $consumer = makeWalletConsumer();

    for ($i = 0; $i < 18; $i++) {
        app(TokenService::class)->credit($consumer, 10, 'purchase');
    }

    $this->actingAs($consumer)
        ->get('/wallet/history')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Wallet/History')
            ->has('entries.data', 15)
            ->where('entries.total', 18)
        );
});

it('history nao expoe entradas de outro user', function () {
    $consumer = makeWalletConsumer();
    $other = makeWalletConsumer();

    app(TokenService::class)->credit($consumer, 77, 'purchase');
    app(TokenService::class)->credit($other, 999, 'purchase');

    $this->actingAs($consumer)
        ->get('/wallet/history')
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.amount', 77)
        );
});

// ─── Segurança do crédito ───────────────────────────────────────────────────

it('tokens creditados sao sempre do package nao do request', function () {
    $package = makeWalletPackage(['tokens' => 500, 'price_cents' => 4990]);
    $consumer = makeWalletConsumer();

    $purchase = $this->actingAs($consumer)->postJson("/wallet/purchase/{$package->id}", [
        'tokens' => 9999999,
        'amount_cents' => 1,
    ]);

    $payment = Payment::findOrFail($purchase->json('payment_id'));

    $fake = app(AsaasClientInterface::class);
    $fake->simulatePaymentReceived($payment->provider_charge_id);
    app(PaymentService::class)->confirmPayment($payment);

    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(500);

    $ledger = TokenLedger::where('reference_type', 'payment')->where('reference_id', $payment->id)->first();
    expect($ledger->amount)->toBe(500);
});
