<?php

use App\Services\Kyc\DiditKycClient;
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

// ─── 1. submitVerification → reference + url + pending ────────────────────────

it('submitVerification returns reference, url and pending status', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/' => Http::response([
            'session_id' => 'sess_abc',
            'url' => 'https://verify.didit.me/sess_abc',
        ], 201),
    ]);

    $result = (new DiditKycClient)->submitVerification(['vendor_data' => '42']);

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

    $result = (new DiditKycClient)->getVerification('sess_abc');

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

    $result = (new DiditKycClient)->getVerification('sess_abc');

    expect($result['status'])->toBe('rejected');
});

// ─── 4. getVerification maps unknown status → pending ─────────────────────────

it('getVerification maps an unknown status to pending', function () {
    diditConfig();
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response(['status' => 'In Review'], 200),
    ]);

    $result = (new DiditKycClient)->getVerification('sess_abc');

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

    expect(fn () => (new DiditKycClient)->getVerification('sess_abc'))
        ->toThrow(RuntimeException::class);

    try {
        (new DiditKycClient)->getVerification('sess_abc');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('Maria');
        expect($e->getMessage())->toContain('500');
    }
});
