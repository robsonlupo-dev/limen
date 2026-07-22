<?php

use App\Models\Payment;
use App\Models\TokenPackage;
use App\Models\User;

function ipAllowlistCharge(): array
{
    $user = User::factory()->create();
    $package = TokenPackage::create([
        'slug' => 'ip-'.uniqid(),
        'name' => 'IP Package',
        'tokens' => 500,
        'price_cents' => 4990,
        'active' => true,
        'sort_order' => 1,
    ]);

    $payment = Payment::create([
        'user_id' => $user->id,
        'token_package_id' => $package->id,
        'provider' => 'asaas',
        'provider_charge_id' => 'chg_ip_'.uniqid(),
        'method' => 'pix',
        'amount_cents' => $package->price_cents,
        'tokens' => $package->tokens,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    return [$payment, [
        'id' => 'evt_ip_'.uniqid(),
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => $payment->provider_charge_id],
    ]];
}

// Disabled allowlist: any source IP passes straight through to the controller.
it('passes the webhook through when the allowlist is disabled', function () {
    config([
        'asaas.webhook_ip_allowlist_enabled' => false,
        'asaas.webhook_token' => 'valid-token',
    ]);

    [, $payload] = ipAllowlistCharge();

    $this->postJson('/api/v1/webhooks/asaas', $payload, [
        'asaas-access-token' => 'valid-token',
    ])->assertOk();
});

// Enabled allowlist, source IP not on the list: blocked before the controller.
it('blocks the webhook when the source IP is not allowlisted', function () {
    config([
        'asaas.webhook_ip_allowlist_enabled' => true,
        'asaas.webhook_allowed_ips' => ['203.0.113.10'],
        'asaas.webhook_token' => 'valid-token',
    ]);

    [, $payload] = ipAllowlistCharge();

    // Test requests originate from 127.0.0.1, which is not on the list above.
    $this->postJson('/api/v1/webhooks/asaas', $payload, [
        'asaas-access-token' => 'valid-token',
    ])->assertStatus(403);
});

// Enabled allowlist, source IP on the list (incl. CIDR): passes through.
it('allows the webhook when the source IP is allowlisted via CIDR', function () {
    config([
        'asaas.webhook_ip_allowlist_enabled' => true,
        'asaas.webhook_allowed_ips' => ['127.0.0.0/8'],
        'asaas.webhook_token' => 'valid-token',
    ]);

    [, $payload] = ipAllowlistCharge();

    $this->postJson('/api/v1/webhooks/asaas', $payload, [
        'asaas-access-token' => 'valid-token',
    ])->assertOk();
});
