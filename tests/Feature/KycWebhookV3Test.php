<?php

use App\Services\KycService;

// Shared helpers (makePendingVerification, kycV3Headers, kycV3SimpleHeaders)
// live in tests/Pest.php. The webhook secret is set in phpunit.xml but pinned
// here so signatures are deterministic regardless of environment.
beforeEach(function () {
    config(['kyc.webhook_secret' => 'test-kyc-secret']);
});

function v3Payload(array $overrides = []): array
{
    return array_merge([
        'session_id' => 'sess_v3',
        'status' => 'Approved',
        'webhook_type' => 'status.updated',
        'event_id' => 'evt_'.uniqid(),
    ], $overrides);
}

// ─── 1. Approved + valid X-Signature-V2 → approve ─────────────────────────────

it('processes an Approved webhook with a valid X-Signature-V2', function () {
    makePendingVerification('sess_v3_ok');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_ok', 'status' => 'Approved']);

    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload))
        ->assertOk();

    $spy->shouldHaveReceived('approve')->once();
    $spy->shouldNotHaveReceived('reject');
});

// ─── 2. Declined + valid X-Signature-V2 → reject ──────────────────────────────

it('processes a Declined webhook with a valid X-Signature-V2', function () {
    makePendingVerification('sess_v3_no');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_no', 'status' => 'Declined']);

    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload))
        ->assertOk();

    $spy->shouldHaveReceived('reject')->once();
    $spy->shouldNotHaveReceived('approve');
});

// ─── 3. Invalid X-Signature-V2 → 401 ──────────────────────────────────────────

it('rejects a webhook with an invalid X-Signature-V2', function () {
    $verification = makePendingVerification('sess_v3_badsig');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_badsig']);

    $this->postJson('/api/v1/webhooks/kyc', $payload, [
        'X-Timestamp' => (string) now()->getTimestamp(),
        'X-Signature-V2' => 'not-a-valid-signature',
    ])->assertStatus(401);

    $spy->shouldNotHaveReceived('approve');
    expect($verification->fresh()->status)->toBe('pending');
});

// ─── 4. Expired timestamp (> 300s) → 401 ──────────────────────────────────────

it('rejects a webhook whose timestamp is older than the tolerance', function () {
    makePendingVerification('sess_v3_stale');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_stale']);
    // Signature is valid, but the timestamp is 301s in the past → replay guard.
    $staleTs = now()->getTimestamp() - 301;

    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload, $staleTs))
        ->assertStatus(401);

    $spy->shouldNotHaveReceived('approve');
});

// ─── 5. webhook_type != status.updated → ignored (200) ────────────────────────

it('ignores a webhook whose type is not status.updated', function () {
    makePendingVerification('sess_v3_type');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_type', 'webhook_type' => 'session.created']);

    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload))
        ->assertOk();

    $spy->shouldNotHaveReceived('approve');
    $spy->shouldNotHaveReceived('reject');
});

// ─── 6. Duplicate event_id → idempotent (200, processed once) ─────────────────

it('processes a duplicated event_id only once', function () {
    makePendingVerification('sess_v3_idem');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_idem', 'event_id' => 'evt_dup']);
    $headers = kycV3Headers($payload);

    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers)->assertOk();
    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers)->assertOk();

    $spy->shouldHaveReceived('approve')->once();
});

// ─── 6b. Different event_id, same session, already terminal → no re-process ────
// A retentativa da Didit chega com event_id NOVO (o Cache::add não pega), então é
// o guardião de estado terminal — agora sob lockForUpdate — que barra o reprocesso.
// Roda com o KycService REAL para exercitar a transição + o guardião de verdade.

it('does not re-process a different event_id once the verification is terminal', function () {
    makePendingVerification('sess_v3_terminal');

    $p1 = v3Payload(['session_id' => 'sess_v3_terminal', 'event_id' => 'evt_term_a']);
    $this->postJson('/api/v1/webhooks/kyc', $p1, kycV3Headers($p1))->assertOk();

    // event_id DIFERENTE, mesma sessão: passa o Cache::add, mas a verificação já
    // está 'approved' → no-op.
    $p2 = v3Payload(['session_id' => 'sess_v3_terminal', 'event_id' => 'evt_term_b']);
    $this->postJson('/api/v1/webhooks/kyc', $p2, kycV3Headers($p2))->assertOk();

    // approve() rodou UMA vez só (o Audit é gravado a cada transição real).
    expect(\App\Models\AuditLog::where('action', 'kyc.approved')->count())->toBe(1);
});

// ─── 7. X-Signature-Simple fallback → processed ───────────────────────────────

it('accepts the X-Signature-Simple fallback signature', function () {
    makePendingVerification('sess_v3_simple');
    $spy = $this->spy(KycService::class);

    $payload = v3Payload(['session_id' => 'sess_v3_simple']);

    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3SimpleHeaders($payload))
        ->assertOk();

    $spy->shouldHaveReceived('approve')->once();
});
