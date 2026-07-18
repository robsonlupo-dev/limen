<?php

use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\Kyc\FakeKycClient;
use App\Services\Kyc\KycClientInterface;
use App\Services\Kyc\KycDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// ─── Helpers ────────────────────────────────────────────────────────────────

function makePendingPerformer(): array
{
    $user = User::factory()->create([
        'role'   => 'performer',
        'status' => 'pending',
    ]);
    $user->performerProfile()->create([
        'stage_name' => 'Test Performer',
        'slug'       => 'test-performer-' . strtolower(\Illuminate\Support\Str::random(4)),
        'category'   => 'mulheres',
        'is_verified' => false,
    ]);
    $token = $user->createToken('api')->plainTextToken;

    return [$user, $token];
}

function makeAdminUser(): array
{
    $user  = User::factory()->admin()->create();
    $token = $user->createToken('api')->plainTextToken;

    return [$user, $token];
}

function validKycPayload(): array
{
    return [
        'document_type'   => 'rg',
        'cpf'             => '529.982.247-25',
        'full_legal_name' => 'Maria Teste Silva',
        'date_of_birth'   => now()->subYears(25)->format('Y-m-d'),
    ];
}

function kycFiles(): array
{
    return [
        'document_front' => UploadedFile::fake()->create('front.jpg', 500, 'image/jpeg'),
        'selfie'         => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
    ];
}

function postKyc(mixed $test, array $payload = [], array $files = [], string $token = ''): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/v1/performer/kyc/submit',
        array_merge($payload, $files),
        ['Authorization' => "Bearer $token"]
    );
}

// ─── Test 1: Valid KYC submission → 201, verification created pending ────────

it('performer submits KYC with valid CPF and receives 201 with pending status', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    $response = postKyc($this, validKycPayload(), kycFiles(), $token);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'pending');

    $this->assertDatabaseHas('identity_verifications', [
        'user_id' => $user->id,
        'status'  => 'pending',
    ]);
});

// ─── KycDocumentStore: encryption at rest ───────────────────────────────────

it('KycDocumentStore writes ciphertext at rest and decrypts on retrieve', function () {
    Storage::fake('kyc');

    $store = app(KycDocumentStore::class);
    $content = 'CONFIDENTIAL-IDENTITY-DOC-' . str_repeat('Z', 300);
    $file = UploadedFile::fake()->createWithContent('doc.jpg', $content);

    $path = $store->store(42, $file, 'document_front');

    // Path lands on the private disk and is marked as encrypted.
    expect($path)->toStartWith('kyc/42/');
    expect($path)->toEndWith('.enc');

    // On-disk bytes are NOT the plaintext.
    $onDisk = Storage::disk('kyc')->get($path);
    expect($onDisk)->not->toBe($content);
    expect($onDisk)->not->toContain('CONFIDENTIAL');

    // …and round-trip back to the original.
    expect($store->retrieve($path))->toBe($content);
    expect(Crypt::decryptString($onDisk))->toBe($content);
});

it('KYC submission stores the documents encrypted (paths .enc, disk ciphertext)', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->firstOrFail();

    expect($verification->document_front_path)->toEndWith('.enc');
    expect($verification->selfie_path)->toEndWith('.enc');
    Storage::disk('kyc')->assertExists($verification->document_front_path);

    // Stored bytes were encrypted on write (decrypt succeeds; raw filename absent).
    $onDisk = Storage::disk('kyc')->get($verification->document_front_path);
    expect(fn () => Crypt::decryptString($onDisk))->not->toThrow(Exception::class);
});

// ─── Test 2: Invalid CPF → 422 ───────────────────────────────────────────────

it('returns 422 when CPF is invalid in KYC submission', function () {
    Storage::fake('kyc');

    [, $token] = makePendingPerformer();

    $payload = array_merge(validKycPayload(), ['cpf' => '111.111.111-11']);

    postKyc($this, $payload, kycFiles(), $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors('cpf');
});

// ─── Test 3: Under 18 → 422 ──────────────────────────────────────────────────

it('returns 422 when performer is under 18 in KYC submission', function () {
    Storage::fake('kyc');

    [, $token] = makePendingPerformer();

    $payload = array_merge(validKycPayload(), [
        'date_of_birth' => now()->subYears(17)->format('Y-m-d'),
    ]);

    postKyc($this, $payload, kycFiles(), $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors('date_of_birth');
});

// ─── Test 4: Invalid file type or >10MB → 422 ────────────────────────────────

it('returns 422 for invalid file type (pdf) or file exceeding 10MB', function () {
    Storage::fake('kyc');

    [, $token] = makePendingPerformer();

    // PDF instead of jpeg/png
    postKyc($this, validKycPayload(), [
        'document_front' => UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf'),
        'selfie'         => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
    ], $token)->assertStatus(422)->assertJsonValidationErrors('document_front');

    // File >10MB
    postKyc($this, validKycPayload(), [
        'document_front' => UploadedFile::fake()->create('big.jpg', 11000, 'image/jpeg'),
        'selfie'         => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
    ], $token)->assertStatus(422)->assertJsonValidationErrors('document_front');
});

// ─── Test 5: Webhook approved → performer active + verified, audit logged ────

it('webhook approved transitions performer to active and verified and logs audit', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    $payload = [
        'session_id'   => $verification->provider_reference,
        'status'       => 'Approved',
        'webhook_type' => 'status.updated',
        'event_id'     => 'evt_' . uniqid(),
    ];
    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload));

    $user->refresh();
    expect($user->status)->toBe('active');
    expect($user->age_verified_at)->not->toBeNull();

    $verification->refresh();
    expect($verification->status)->toBe('approved');
    expect($verification->age_confirmed)->toBeTrue();

    $user->performerProfile->refresh();
    expect($user->performerProfile->is_verified)->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.approved']);
});

// ─── Test 6: Webhook rejected → performer stays pending, audit logged ─────────

it('webhook rejected keeps performer pending and logs audit', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    $payload = [
        'session_id'   => $verification->provider_reference,
        'status'       => 'Declined',
        'webhook_type' => 'status.updated',
        'event_id'     => 'evt_' . uniqid(),
        'decision'     => ['reason' => 'Document unclear'],
    ];
    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload));

    $user->refresh();
    expect($user->status)->toBe('pending');

    $verification->refresh();
    expect($verification->status)->toBe('rejected');

    $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.rejected']);
});

// ─── Test 7: Webhook with invalid signature → 401, nothing changed ───────────

it('webhook with invalid signature returns 401 and leaves verification unchanged', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    $payload = [
        'session_id'   => $verification->provider_reference,
        'status'       => 'Approved',
        'webhook_type' => 'status.updated',
        'event_id'     => 'evt_' . uniqid(),
    ];
    $this->postJson('/api/v1/webhooks/kyc', $payload, [
        'X-Timestamp'    => (string) now()->getTimestamp(),
        'X-Signature-V2' => 'wrong-signature',
    ])->assertStatus(401);

    $verification->refresh();
    expect($verification->status)->toBe('pending');

    $user->refresh();
    expect($user->status)->toBe('pending');
});

// ─── Test 8: Idempotency — same webhook twice → processed only once ──────────

it('sending the same webhook twice processes only once (idempotent)', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();
    $payload = [
        'session_id'   => $verification->provider_reference,
        'status'       => 'Approved',
        'webhook_type' => 'status.updated',
        'event_id'     => 'evt_idem_once',
    ];
    $headers = kycV3Headers($payload);

    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers);
    $this->postJson('/api/v1/webhooks/kyc', $payload, $headers);

    $this->assertDatabaseCount('audit_logs', 2); // kyc.submitted + kyc.approved (once)

    $user->refresh();
    expect($user->status)->toBe('active');
});

// ─── Test 9: Admin approves manually → same result as webhook approved ────────

it('admin can manually approve a pending verification', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    [$admin] = makeAdminUser();

    // actingAs resets the Sanctum guard state (guard caches previous user within same test)
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/kyc/{$verification->id}/approve")
        ->assertOk();

    $user->refresh();
    expect($user->status)->toBe('active');
    expect($user->age_verified_at)->not->toBeNull();

    $verification->refresh();
    expect($verification->status)->toBe('approved');
    expect($verification->reviewed_by)->not->toBeNull();
});

// ─── Test 10: Admin rejects → same result as webhook rejected ────────────────

it('admin can manually reject a pending verification', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    [$admin] = makeAdminUser();

    // actingAs resets the Sanctum guard state (guard caches previous user within same test)
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/kyc/{$verification->id}/reject", ['reason' => 'Photo too dark'])
        ->assertOk();

    $user->refresh();
    expect($user->status)->toBe('pending');

    $verification->refresh();
    expect($verification->status)->toBe('rejected');
    expect($verification->reviewed_by)->not->toBeNull();
});

// ─── Test 11: GET /performer/kyc/status returns status, no provider data ─────

it('performer can check KYC status without seeing provider internals', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    $response = $this->getJson('/api/v1/performer/kyc/status', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'pending');

    $data = $response->json();
    expect($data)->not->toHaveKey('provider_reference');
    expect($data)->not->toHaveKey('provider_status');
    expect($data)->not->toHaveKey('document_front_path');
    expect($data)->not->toHaveKey('selfie_path');
});

// ─── Test 12: Approved performer appears in public catalog ───────────────────

it('performer appears in public catalog after KYC approval', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();
    $slug = $user->performerProfile->slug;

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);

    // Not in catalog while pending
    $slugsBefore = collect($this->getJson('/api/v1/performers')->json('data'))->pluck('slug')->all();
    expect($slugsBefore)->not->toContain($slug);

    $verification = IdentityVerification::where('user_id', $user->id)->latest()->first();

    $payload = [
        'session_id'   => $verification->provider_reference,
        'status'       => 'Approved',
        'webhook_type' => 'status.updated',
        'event_id'     => 'evt_' . uniqid(),
    ];
    $this->postJson('/api/v1/webhooks/kyc', $payload, kycV3Headers($payload));

    // Now in catalog
    $slugsAfter = collect($this->getJson('/api/v1/performers')->json('data'))->pluck('slug')->all();
    expect($slugsAfter)->toContain($slug);
});

// ─── Test 13: Invalid CPF in performer registration → 422 ───────────────────

it('returns 422 when performer registers with invalid CPF', function () {
    $this->postJson('/api/v1/auth/register/performer', [
        'name'         => 'Performer Test',
        'email'        => 'perf@test.com',
        'password'     => 'Secret123',
        'password_confirmation' => 'Secret123',
        'birthdate'    => now()->subYears(25)->format('Y-m-d'),
        'accept_terms' => true,
        'lgpd_consent' => true,
        'terms_version' => '1.0',
        'stage_name'   => 'Test Stage',
        'cpf'          => '000.000.000-00',
    ])->assertStatus(422)->assertJsonValidationErrors('cpf');
});

// ─── Test 14: Pending performer cannot resubmit without prior rejection ───────

it('performer cannot submit KYC twice while still pending', function () {
    Storage::fake('kyc');
    Queue::fake();

    [$user, $token] = makePendingPerformer();

    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(201);
    postKyc($this, validKycPayload(), kycFiles(), $token)->assertStatus(422);
});
