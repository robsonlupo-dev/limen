<?php

use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Etapa 2 (Fase 0): a UI de gorjeta agora dispara uma rota WEB com sessão + CSRF
 * (POST /gorjetas → tips.send), não a API Sanctum. Estes testes cobrem esse
 * caminho — o que o frontend realmente usa.
 *
 * Helpers locais (prefixo tipWeb*) para o arquivo ser autossuficiente rodando
 * isolado ou na suíte completa.
 */

function tipWebConsumer(int $balance = 0): User
{
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($balance > 0) {
        app(TokenService::class)->credit($consumer, $balance, 'purchase');
    }

    return $consumer;
}

function tipWebPerformer(int $splitPct = 65): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => $splitPct,
    ]);
}

it('sends a tip through the web route with session auth', function () {
    $consumer = tipWebConsumer(100);
    $profile = tipWebPerformer(65);

    $response = $this->actingAs($consumer)->postJson(route('tips.send'), [
        'performer_slug' => $profile->slug,
        'amount' => 50,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertCreated()->assertJsonFragment([
        'amount' => 50,
        'performer_amount' => 32, // floor(50 * 65 / 100)
        'new_balance' => 50,
        'tips_count' => 1,
    ]);

    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(50);
    expect(Tip::count())->toBe(1);
});

it('returns insufficient_balance reason so the UI can redirect to the wallet', function () {
    $consumer = tipWebConsumer(10);
    $profile = tipWebPerformer(65);

    $ledgerBefore = TokenLedger::count();

    $this->actingAs($consumer)->postJson(route('tips.send'), [
        'performer_slug' => $profile->slug,
        'amount' => 50,
        'idempotency_key' => (string) Str::uuid(),
    ])
        ->assertUnprocessable()
        ->assertJsonFragment(['reason' => 'insufficient_balance']);

    expect(TokenLedger::count())->toBe($ledgerBefore);
});

it('rejects a self tip through the web route', function () {
    $consumer = tipWebConsumer(100);
    // Give the consumer a performer profile so they can target themselves.
    $profile = $consumer->performerProfile()->create([
        'stage_name' => 'Self',
        'slug' => 'self-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'split_pct' => 65,
    ]);

    $this->actingAs($consumer)->postJson(route('tips.send'), [
        'performer_slug' => $profile->slug,
        'amount' => 10,
        'idempotency_key' => (string) Str::uuid(),
    ])
        ->assertUnprocessable()
        ->assertJsonFragment(['reason' => 'self_tip']);

    expect(Tip::count())->toBe(0);
});

it('is idempotent on the web route with the same key', function () {
    $consumer = tipWebConsumer(200);
    $profile = tipWebPerformer(65);
    $key = (string) Str::uuid();

    $payload = [
        'performer_slug' => $profile->slug,
        'amount' => 50,
        'idempotency_key' => $key,
    ];

    $this->actingAs($consumer)->postJson(route('tips.send'), $payload)->assertCreated();
    $this->actingAs($consumer)->postJson(route('tips.send'), $payload)->assertCreated();

    expect(Tip::count())->toBe(1);
    expect(TokenWallet::where('user_id', $consumer->id)->value('balance'))->toBe(150);
});

it('forbids a performer from using the consumer tip route', function () {
    $performer = tipWebPerformer(65)->user;
    $target = tipWebPerformer(65);

    $this->actingAs($performer)->postJson(route('tips.send'), [
        'performer_slug' => $target->slug,
        'amount' => 10,
        'idempotency_key' => (string) Str::uuid(),
    ])->assertForbidden();
});

it('rejects invalid input without creating a tip', function () {
    // Nota: por convenção do projeto (bootstrap/app.php: shouldRenderJsonWhen só
    // em api/*), falhas de validação em rota web redirecionam (302) em vez de 422
    // JSON. O front-end nunca chega aqui — o modal valida valor (1–1000) e gera o
    // idempotency_key. O contrato garantido aqui: entrada inválida não vira gorjeta.
    $consumer = tipWebConsumer(100);
    $profile = tipWebPerformer(65);

    $this->actingAs($consumer)->postJson(route('tips.send'), [
        'performer_slug' => $profile->slug,
        'amount' => 50,
        // idempotency_key ausente → ValidationException
    ])->assertRedirect();

    expect(Tip::count())->toBe(0);
});
