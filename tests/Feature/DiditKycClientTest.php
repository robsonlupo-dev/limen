<?php

use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Kyc\DiditKycClient;
use App\Services\KycService;
use Illuminate\Support\Facades\Http;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function diditConfig(): void
{
    config([
        'kyc.provider' => 'didit',
        'kyc.api_key' => 'test-api-key',
        'kyc.workflow_id' => 'wf-123',
        'kyc.base_url' => 'https://verification.didit.me',
    ]);
}

function makePendingVerification(string $reference): IdentityVerification
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $user->performerProfile()->create([
        'stage_name' => 'Didit Performer',
        'slug' => 'didit-' . strtolower(\Illuminate\Support\Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    return $user->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Maria Teste Silva',
        'date_of_birth' => '1998-01-01',
        'provider' => 'didit',
        'provider_reference' => $reference,
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);
}

/** Mirrors how postJson serializes the body so the HMAC matches server-side. */
function diditSignature(array $payload): string
{
    return hash_hmac('sha256', json_encode($payload), (string) config('kyc.webhook_secret'));
}

// ─── 1. submitVerification → reference + url + pending ────────────────────────

it('submitVerification returns reference, url and pending status', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/' => Http::response([
            'session_id' => 'sess_abc',
            'url' => 'https://verify.didit.me/sess_abc',
        ], 201),
    ]);

    $result = (new DiditKycClient())->submitVerification(['vendor_data' => '42']);

    expect($result['reference'])->toBe('sess_abc');
    expect($result['status'])->toBe('pending');
    expect($result['url'])->toBe('https://verify.didit.me/sess_abc');

    // Session request authenticates with the API key and carries workflow + vendor data.
    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/session/')
        && $req->hasHeader('x-api-key', 'test-api-key')
        && $req['workflow_id'] === 'wf-123'
        && $req['vendor_data'] === '42'
        && str_contains((string) $req['callback'], '/api/v1/webhooks/kyc'));
});

// ─── 2. getVerification maps Approved → approved ──────────────────────────────

it('getVerification maps Approved to approved', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response(['status' => 'Approved'], 200),
    ]);

    $result = (new DiditKycClient())->getVerification('sess_abc');

    expect($result['reference'])->toBe('sess_abc');
    expect($result['status'])->toBe('approved');

    Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'test-api-key'));
});

// ─── 3. getVerification maps Declined → rejected ──────────────────────────────

it('getVerification maps Declined to rejected', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response(['status' => 'Declined'], 200),
    ]);

    $result = (new DiditKycClient())->getVerification('sess_abc');

    expect($result['status'])->toBe('rejected');
});

// ─── 4. getVerification maps unknown status → pending ─────────────────────────

it('getVerification maps an unknown status to pending', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response(['status' => 'In Review'], 200),
    ]);

    $result = (new DiditKycClient())->getVerification('sess_abc');

    expect($result['status'])->toBe('pending');
});

// ─── 4b. Failed decision fetch throws without leaking the response body ───────

it('throws a body-free exception when Didit returns an error', function () {
    diditConfig();
    Http::fake([
        // Body carries PII/error detail that must never surface in the exception.
        'verification.didit.me/v3/session/*/decision/' => Http::response(
            ['full_legal_name' => 'Maria Teste Silva', 'error' => 'boom'],
            500,
        ),
    ]);

    expect(fn () => (new DiditKycClient())->getVerification('sess_abc'))
        ->toThrow(RuntimeException::class);

    try {
        (new DiditKycClient())->getVerification('sess_abc');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('Maria');
        expect($e->getMessage())->toContain('500');
    }
});

// ─── 5. Webhook Approved → KycService::approve ────────────────────────────────

it('webhook with status Approved calls KycService::approve', function () {
    config(['kyc.webhook_secret' => 'test-kyc-secret']);

    $verification = makePendingVerification('sess_hook_ok');
    $spy = $this->spy(KycService::class);

    $payload = [
        'session_id' => 'sess_hook_ok',
        'status' => 'Approved',
        'vendor_data' => (string) $verification->user_id,
    ];

    $this->postJson('/api/v1/webhooks/kyc', $payload, [
        'x-signature' => diditSignature($payload),
    ])->assertOk();

    $spy->shouldHaveReceived('approve')->once();
    $spy->shouldNotHaveReceived('reject');
});

// ─── 6. Webhook Declined → KycService::reject ─────────────────────────────────

it('webhook with status Declined calls KycService::reject', function () {
    config(['kyc.webhook_secret' => 'test-kyc-secret']);

    $verification = makePendingVerification('sess_hook_no');
    $spy = $this->spy(KycService::class);

    $payload = [
        'session_id' => 'sess_hook_no',
        'status' => 'Declined',
        'vendor_data' => (string) $verification->user_id,
    ];

    $this->postJson('/api/v1/webhooks/kyc', $payload, [
        'x-signature' => diditSignature($payload),
    ])->assertOk();

    $spy->shouldHaveReceived('reject')->once();
    $spy->shouldNotHaveReceived('approve');
});

// ─── 7. Webhook with invalid HMAC → 401 (secret configured) ───────────────────

it('webhook with an invalid HMAC signature returns 401', function () {
    config(['kyc.webhook_secret' => 'test-kyc-secret']);

    $verification = makePendingVerification('sess_hook_bad');
    $spy = $this->spy(KycService::class);

    $payload = [
        'session_id' => 'sess_hook_bad',
        'status' => 'Approved',
        'vendor_data' => (string) $verification->user_id,
    ];

    $this->postJson('/api/v1/webhooks/kyc', $payload, [
        'x-signature' => 'deadbeef-not-a-valid-signature',
    ])->assertStatus(401);

    $spy->shouldNotHaveReceived('approve');
    $spy->shouldNotHaveReceived('reject');

    expect($verification->fresh()->status)->toBe('pending');
});
