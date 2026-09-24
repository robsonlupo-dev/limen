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

// ─── 6c. Transação falha → a reserva do event_id é devolvida ─────────────────
// O Cache::add marca o evento ANTES da transação. Sem devolver a reserva no
// catch, a retentativa da Didit (mesmo event_id) seria descartada como duplicada
// e a verificação ficaria presa em `pending`.

it('releases the event_id reservation when processing fails, so the retry is not swallowed', function () {
    makePendingVerification('sess_v3_retry');

    $payload = v3Payload(['session_id' => 'sess_v3_retry', 'event_id' => 'evt_retry_1']);
    $headers = kycV3Headers($payload);

    // UM único mock com DUAS expectativas ORDENADAS de approve — sem trocar o
    // dublê no meio do teste (o que falhava): a 1ª chamada explode (banco/e-mail
    // fora), a 2ª passa. Sem withoutExceptionHandling: deixamos o handler
    // renderizar o 500, que é o sinal (não-2xx) que faz a Didit reenviar.
    $this->mock(KycService::class, function ($mock) {
        $mock->shouldReceive('approve')->once()->ordered()->andThrow(new RuntimeException('transient'));
        $mock->shouldReceive('approve')->once()->ordered()->andReturnNull();
    });

    // 1ª entrega: o approve explode. O controller, antes de propagar, devolve a
    // reserva do event_id no catch.
    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers)->assertStatus(500);

    // 2ª entrega (retry da Didit, MESMO event_id): só chega ao approve porque a
    // reserva foi devolvida (senão o Cache::add barraria e approve NÃO seria
    // chamado — a 2ª expectativa `->once()` ficaria insatisfeita e o teste
    // falharia no teardown do Mockery). É isso que prova a devolução.
    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers)->assertOk();
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
