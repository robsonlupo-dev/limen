<?php

use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TipService;
use App\Services\TokenService;
use Illuminate\Support\Str;

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeVerifiedPerformerWithLevel(string $level, int $splitPct, array $userAttrs = []): array
{
    $user = User::factory()->create(array_merge([
        'role' => 'performer',
        'status' => 'active',
    ], $userAttrs));

    $profile = $user->performerProfile()->create([
        'stage_name' => 'Performer '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => $level,
        'split_pct' => $splitPct,
    ]);

    $token = $user->createToken('api')->plainTextToken;

    return [$user, $profile, $token];
}

function makeConsumerWithBalance(int $balance): array
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $token = $user->createToken('api')->plainTextToken;

    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return [$user, $token];
}

function tipPayload(string $slug, int $amount, ?string $key = null, ?string $message = null): array
{
    return [
        'performer_slug' => $slug,
        'amount' => $amount,
        'idempotency_key' => $key ?? (string) Str::uuid(),
        'message' => $message,
    ];
}

// ─── 1. Valid tip: debit consumer, credit performer with correct split ───────

it('sends a valid tip and applies correct split', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(100);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 50), [
        'Authorization' => "Bearer $consumerToken",
    ]);

    $response->assertCreated()->assertJsonFragment([
        'amount' => 50,
        'performer_amount' => 32, // floor(50 * 65 / 100)
        'platform_amount' => 18,
        'new_balance' => 50,
    ]);

    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(50);
    expect(TokenWallet::where('user_id', $profile->user_id)->value('balance'))->toBe(32);
    expect(Tip::count())->toBe(1);
});

// ─── 2. Split by level ───────────────────────────────────────────────────────

it('applies correct split for each performer level', function (string $level, int $splitPct, int $amount, int $expectedPerformer) {
    [$consumer, $consumerToken] = makeConsumerWithBalance(1000);
    [, $profile] = makeVerifiedPerformerWithLevel($level, $splitPct);

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, $amount), [
        'Authorization' => "Bearer $consumerToken",
    ]);

    $response->assertCreated()->assertJsonFragment([
        'performer_amount' => $expectedPerformer,
        'platform_amount' => $amount - $expectedPerformer,
    ]);
})->with([
    'iniciante 65%' => ['iniciante', 65, 100, 65],
    'estrela 70%' => ['estrela',   70, 100, 70],
    'premium 75%' => ['premium',   75, 100, 75],
    'vip 80%' => ['vip',       80, 100, 80],
]);

// ─── 3. Insufficient balance returns 422, nothing debited ────────────────────

it('returns 422 on insufficient balance and makes no ledger entries', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(10);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);

    $ledgerBefore = TokenLedger::count();

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 50), [
        'Authorization' => "Bearer $consumerToken",
    ]);

    $response->assertUnprocessable();
    expect(TokenLedger::count())->toBe($ledgerBefore);
    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(10);
});

// ─── 4. Pending / non-verified performer → 422 ──────────────────────────────

it('rejects tip to a non-verified performer', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(100);

    $performerUser = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $performerUser->performerProfile()->create([
        'stage_name' => 'Unverified',
        'slug' => 'unverified-'.strtolower(Str::random(4)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), [
        'Authorization' => "Bearer $consumerToken",
    ]);

    $response->assertStatus(404);
});

it('rejects tip when performer user is not active', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(100);

    $performerUser = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $profile = $performerUser->performerProfile()->create([
        'stage_name' => 'Pending Perf',
        'slug' => 'pending-'.strtolower(Str::random(4)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), [
        'Authorization' => "Bearer $consumerToken",
    ]);

    $response->assertStatus(404);
});

// ─── 5. Amount limits → 422 ─────────────────────────────────────────────────

it('rejects amount below 1', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(100);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 0), [
        'Authorization' => "Bearer $consumerToken",
    ])->assertUnprocessable();
});

it('rejects amount above 1000', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(2000);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 1001), [
        'Authorization' => "Bearer $consumerToken",
    ])->assertUnprocessable();
});

// ─── 6. Idempotency: same key returns existing tip, no duplicate ledger ──────

it('returns existing tip on duplicate idempotency key without creating duplicates', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(200);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 50, $key), [
        'Authorization' => "Bearer $consumerToken",
    ]);
    $first->assertCreated();

    $ledgerCount = TokenLedger::count();
    $tipCount = Tip::count();

    $second = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 50, $key), [
        'Authorization' => "Bearer $consumerToken",
    ]);
    $second->assertCreated()->assertJsonFragment(['tip_id' => $first->json('tip_id')]);

    expect(TokenLedger::count())->toBe($ledgerCount);
    expect(Tip::count())->toBe($tipCount);
    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(150);
});

// ─── 7. Self-tip rejected ────────────────────────────────────────────────────

it('rejects a tip to yourself', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    app(TokenService::class)->credit($user, 100, 'purchase');
    $token = $user->createToken('api')->plainTextToken;

    // Give this user also a performer profile
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Self',
        'slug' => 'self-'.strtolower(Str::random(4)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    $response = $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertUnprocessable();
});

// ─── 8. Rate limit: 11th tip in the same minute → 429 ───────────────────────

it('rate limits tips to 10 per minute', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(10000);
    [, $profile] = makeVerifiedPerformerWithLevel('vip', 80);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), [
            'Authorization' => "Bearer $consumerToken",
        ])->assertCreated();
    }

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), [
        'Authorization' => "Bearer $consumerToken",
    ])->assertStatus(429);
});

// ─── 9. GET /tips returns consumer's tip history ─────────────────────────────

it('returns consumer tip history in descending order', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(500);
    [, $profile] = makeVerifiedPerformerWithLevel('estrela', 70);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), ['Authorization' => "Bearer $consumerToken"])->assertCreated();
    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 20), ['Authorization' => "Bearer $consumerToken"])->assertCreated();

    $response = $this->getJson('/api/v1/tips', ['Authorization' => "Bearer $consumerToken"]);

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['amount'])->toBe(20);
    expect($data[1]['amount'])->toBe(10);
});

// ─── 10. GET /performer/tips returns received tips ───────────────────────────

it('returns performer received tip history', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(500);
    [$performerUser, $profile, $performerToken] = makeVerifiedPerformerWithLevel('premium', 75);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 40), ['Authorization' => "Bearer $consumerToken"])->assertCreated();

    // Use actingAs to isolate potential token lookup issues
    $response = $this->actingAs($performerUser, 'sanctum')
        ->getJson('/api/v1/performer/tips');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['amount'])->toBe(40);
    expect($data[0]['performer_amount'])->toBe(30); // floor(40 * 75 / 100)
});

// ─── 11. Transaction atomic: credit failure reverts debit ────────────────────

it('rolls back consumer debit when performer credit fails', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(100);
    [, $profile] = makeVerifiedPerformerWithLevel('iniciante', 65);

    $consumerBalanceBefore = TokenWallet::where('user_id', $consumer->id)->value('balance');
    $ledgerBefore = TokenLedger::count();

    // Force performer credit to fail by giving performer an immutable wallet state
    // Simulated by making TokenService::credit throw inside a transaction
    $mock = Mockery::mock(TokenService::class)->makePartial();
    $mock->shouldReceive('credit')->once()->andThrow(new RuntimeException('Simulated credit failure'));
    app()->instance(TokenService::class, $mock);
    app()->instance(TipService::class, new TipService($mock));

    try {
        $this->postJson('/api/v1/tips', tipPayload($profile->slug, 50), [
            'Authorization' => "Bearer $consumerToken",
        ]);
    } catch (Throwable) {
        // May surface as 500; we just check DB state
    }

    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe($consumerBalanceBefore);
    expect(TokenLedger::count())->toBe($ledgerBefore);
    expect(Tip::count())->toBe(0);
});

// ─── 12. tips_count increments correctly ────────────────────────────────────

it('increments performer tips_count for each tip received', function () {
    [$consumer, $consumerToken] = makeConsumerWithBalance(500);
    [, $profile] = makeVerifiedPerformerWithLevel('vip', 80);

    expect($profile->fresh()->tips_count)->toBe(0);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), ['Authorization' => "Bearer $consumerToken"])->assertCreated();
    expect($profile->fresh()->tips_count)->toBe(1);

    $this->postJson('/api/v1/tips', tipPayload($profile->slug, 10), ['Authorization' => "Bearer $consumerToken"])->assertCreated();
    expect($profile->fresh()->tips_count)->toBe(2);
});
