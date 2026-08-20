<?php

use App\Exceptions\GiftException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Gift;
use App\Models\GiftSend;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\GiftService;
use App\Services\PayoutService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use App\Support\FanAlias;
use Database\Seeders\GiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Presentes virtuais (PR #137, M.13.6). Catálogo fixo da Limen, split 75/25,
 * idempotente. Helpers locais (g*) para o arquivo rodar isolado. Revisão de
 * segurança do plano e do código rodada.
 */
function gPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Bia '.Str::random(6),
        'slug' => 'bia-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function gMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function gFund(User $user, int $tokens): void
{
    app(TokenService::class)->credit($user, $tokens, 'purchase');
}

function gBalance(User $user): int
{
    return app(TokenService::class)->balance($user);
}

function gGift(int $price = 40, string $slug = 'champagne'): Gift
{
    return Gift::create(['name' => ucfirst($slug), 'slug' => $slug, 'price_tokens' => $price, 'active' => true]);
}

function gSend(User $member, PerformerProfile $performer, Gift $gift, ?string $key = null): GiftSend
{
    return app(GiftService::class)->send($member, $performer, $gift, $key ?? (string) Str::uuid());
}

// ─── Split 75/25 e débito/crédito ────────────────────────────────────────────

it('debita o membro e credita a performer com split 75/25', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    $gift = gGift(40); // 75% = 30, 25% = 10
    $send = gSend($member, $performer, $gift);

    expect($send->tokens)->toBe(40)
        ->and($send->performer_amount)->toBe(30)
        ->and($send->platform_amount)->toBe(10)
        ->and($send->performer_amount + $send->platform_amount)->toBe($send->tokens)
        ->and(gBalance($member))->toBe(60)
        ->and(gBalance($performer->user))->toBe(30);
});

it('congela applied_rate=75 na linha do gift_send E do ledger', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    $send = gSend($member, $performer, gGift(40));

    expect($send->applied_rate)->toBe(75);

    $creditLine = TokenLedger::find($send->performer_ledger_id);
    expect($creditLine->entry_type)->toBe('gift_credit')
        ->and((int) $creditLine->applied_rate)->toBe(75)
        ->and((int) $creditLine->amount)->toBe(30);

    $spendLine = TokenLedger::find($send->sender_ledger_id);
    expect($spendLine->entry_type)->toBe('spend_gift')
        ->and((int) $spendLine->amount)->toBe(-40);
});

it('os 6 presentes do catálogo dividem exatamente (múltiplos de 4)', function () {
    $this->seed(GiftSeeder::class);
    $policy = app(TokenCreditPolicy::class);

    Gift::active()->get()->each(function (Gift $gift) use ($policy) {
        $split = $policy->applyRate($gift->price_tokens, 'gift');
        // Split DECIMAL EXATO: credited + retained == preço SEMPRE. Múltiplo de 4 →
        // 75% fecha em inteiro (credited termina em .0000). Soma por TokenMath, nunca `+`.
        expect(\App\Support\TokenMath::add($split['credited'], $split['retained']))->toBe(\App\Support\TokenMath::of($gift->price_tokens))
            ->and($split['credited'])->toBe(\App\Support\TokenMath::of(intdiv($gift->price_tokens * 3, 4)))
            ->and($gift->price_tokens % 4)->toBe(0);
    });

    expect(Gift::active()->count())->toBe(6);
});

// ─── Validação de preço ──────────────────────────────────────────────────────

it('rejeita presente com preço fora do múltiplo de 4', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    $bad = gGift(5); // não é múltiplo de 4

    expect(fn () => gSend($member, $performer, $bad))
        ->toThrow(GiftException::class);

    // Defesa em profundidade: nada gravado.
    expect(GiftSend::count())->toBe(0)
        ->and(gBalance($member))->toBe(100);
});

// ─── Saldo insuficiente ──────────────────────────────────────────────────────

it('saldo insuficiente falha sem gravar nenhuma linha no ledger', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 10);

    expect(fn () => gSend($member, $performer, gGift(40)))
        ->toThrow(InsufficientBalanceException::class);

    expect(GiftSend::count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'spend_gift')->count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'gift_credit')->count())->toBe(0)
        ->and(gBalance($member))->toBe(10)
        ->and(gBalance($performer->user))->toBe(0);
});

// ─── Idempotência ────────────────────────────────────────────────────────────

it('envio duplicado com a mesma chave não debita duas vezes', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);
    $gift = gGift(40);

    $key = (string) Str::uuid();
    $first = gSend($member, $performer, $gift, $key);
    $second = gSend($member, $performer, $gift, $key);

    expect($second->id)->toBe($first->id)
        ->and(GiftSend::count())->toBe(1)
        ->and(gBalance($member))->toBe(60)
        ->and(gBalance($performer->user))->toBe(30)
        ->and(TokenLedger::where('entry_type', 'spend_gift')->count())->toBe(1);
});

it('a mesma chave de outro remetente não devolve o envio alheio', function () {
    $performer = gPerformer();
    $memberA = gMember();
    $memberB = gMember();
    gFund($memberA, 100);
    gFund($memberB, 100);
    $gift = gGift(40);

    $key = (string) Str::uuid();
    $sendA = gSend($memberA, $performer, $gift, $key);
    // memberB reusa a MESMA chave — deve gerar um envio SEPARADO (escopo por sender).
    $sendB = gSend($memberB, $performer, $gift, $key);

    expect($sendB->id)->not->toBe($sendA->id)
        ->and($sendA->sender_id)->toBe($memberA->id)
        ->and($sendB->sender_id)->toBe($memberB->id)
        ->and(GiftSend::count())->toBe(2);
});

// ─── Self-gift ───────────────────────────────────────────────────────────────

it('a performer não pode presentear a si mesma', function () {
    $performer = gPerformer();
    gFund($performer->user, 100);

    expect(fn () => gSend($performer->user, $performer, gGift(40)))
        ->toThrow(GiftException::class);

    expect(GiftSend::count())->toBe(0)
        ->and(gBalance($performer->user))->toBe(100);
});

// ─── Presente inativo ────────────────────────────────────────────────────────

it('presente inativo não pode ser enviado', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    $gift = Gift::create(['name' => 'Velho', 'slug' => 'velho', 'price_tokens' => 40, 'active' => false]);

    expect(fn () => gSend($member, $performer, $gift))->toThrow(GiftException::class);
    expect(GiftSend::count())->toBe(0);
});

// ─── Payout: gift_credit é ganho sacável e não respeita teto ─────────────────

it('gift_credit entra no allowlist de ganho do payout', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    gSend($member, $performer, gGift(40)); // performer ganha 30

    expect(app(PayoutService::class)->earningsOwed($performer->user))->toBe(30);
});

it('gift_credit NÃO respeita o teto (é *_credit)', function () {
    $policy = app(TokenCreditPolicy::class);

    expect($policy->respectsCap('gift_credit'))->toBeFalse()
        ->and($policy->respectsCap('spend_gift'))->toBeFalse();
});

// ─── FanAlias: a performer nunca vê member_id ────────────────────────────────

it('a descrição do crédito da performer usa FanAlias, nunca o member_id', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);

    $send = gSend($member, $performer, gGift(40));

    $creditLine = TokenLedger::find($send->performer_ledger_id);
    $label = FanAlias::label($performer->id, $member->id);

    expect($creditLine->description)->toContain($label)
        ->and($creditLine->description)->not->toContain((string) $member->id);

    // sender_id é $hidden no JSON do GiftSend (M.13.10).
    expect($send->toArray())->not->toHaveKey('sender_id');
});

// ─── Catálogo ────────────────────────────────────────────────────────────────

it('o catálogo GET retorna só presentes ativos', function () {
    Gift::create(['name' => 'Ativo', 'slug' => 'ativo', 'price_tokens' => 4, 'active' => true]);
    Gift::create(['name' => 'Inativo', 'slug' => 'inativo', 'price_tokens' => 8, 'active' => false]);

    $response = $this->getJson('/api/v1/gifts');

    $response->assertOk();
    $slugs = collect($response->json('gifts'))->pluck('slug');

    expect($slugs)->toContain('ativo')
        ->and($slugs)->not->toContain('inativo');
});

it('o seeder é idempotente e desativa slugs fora do catálogo', function () {
    Gift::create(['name' => 'Legado', 'slug' => 'legado', 'price_tokens' => 8, 'active' => true]);

    $this->seed(GiftSeeder::class);
    $this->seed(GiftSeeder::class); // idempotente

    expect(Gift::where('slug', 'rosa')->count())->toBe(1)
        ->and(Gift::where('slug', 'legado')->first()->active)->toBeFalse()
        ->and(Gift::active()->count())->toBe(6);
});

// ─── Rota web do membro ──────────────────────────────────────────────────────

it('envia um presente pela rota web com sessão', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 100);
    $gift = gGift(40);

    $response = $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->slug,
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertCreated()->assertJsonFragment([
        'tokens' => 40,
        'new_balance' => 60,
    ]);

    expect(GiftSend::count())->toBe(1)
        ->and(gBalance($performer->user))->toBe(30);
});

it('saldo insuficiente devolve 422 com reason para a UI', function () {
    $performer = gPerformer();
    $member = gMember();
    gFund($member, 10);
    $gift = gGift(40);

    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->slug,
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
    ])
        ->assertUnprocessable()
        ->assertJsonFragment(['reason' => 'insufficient_balance']);

    expect(GiftSend::count())->toBe(0);
});

it('performer não-verificada dá 404 uniforme (não vira oráculo de estado)', function () {
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $unverified = $user->performerProfile()->create([
        'stage_name' => 'Nao Verif',
        'slug' => 'nao-verif-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    $member = gMember();
    gFund($member, 100);
    $gift = gGift(40);

    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $unverified->slug,
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();

    // Mesmo 404 para slug inexistente — indistinguível.
    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => 'nao-existe-'.Str::random(6),
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
});

it('o catálogo web também lista só presentes ativos', function () {
    Gift::create(['name' => 'Ativo', 'slug' => 'ativo', 'price_tokens' => 4, 'active' => true]);
    Gift::create(['name' => 'Inativo', 'slug' => 'inativo', 'price_tokens' => 8, 'active' => false]);

    $response = $this->getJson(route('gifts.catalog'));

    $response->assertOk();
    $slugs = collect($response->json('gifts'))->pluck('slug');

    expect($slugs)->toContain('ativo')->and($slugs)->not->toContain('inativo');
});
