<?php

use App\Exceptions\BoostException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\BoostService;
use App\Services\PerformerCatalogService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Boost pago (Sprint 11) — a performer gasta tokens para destacar o perfil no
 * topo do catálogo. Eixos: o ledger é append-only (linha `spend_boost`, nunca
 * UPDATE de saldo), o carimbo `boosted_until` nunca vaza (só o booleano
 * is_boosted), e boostados vêm primeiro.
 */
function boostPerformer(int $followers = 0, bool $verified = true, string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $verified,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    // followers_count fica FORA do $fillable (é contador denormalizado, escrito
    // só pelo FollowService) — forceFill para o teste poder controlar a ordem
    // base do catálogo, que sem um valor distinto seria um empate de ordenação
    // não-determinística.
    return $profile->forceFill(['followers_count' => $followers])->save() ? $profile->fresh() : $profile;
}

function boostGiveTokens(User $user, int $amount): void
{
    app(TokenService::class)->credit($user, $amount, 'purchase');
}

it('ativa o boost e o perfil passa a aparecer primeiro no catálogo', function () {
    $a = boostPerformer(followers: 30);
    $b = boostPerformer(followers: 20);
    $c = boostPerformer(followers: 10);

    // Sem boost, a ordem é por seguidores: A, B, C.
    $before = app(PerformerCatalogService::class)->publicSearch()->items();
    expect(collect($before)->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);

    // C boosta — mesmo tendo menos seguidores, vai para o topo.
    boostGiveTokens($c->user, 50);
    app(BoostService::class)->boost($c, $c->user);

    $after = app(PerformerCatalogService::class)->publicSearch()->items();
    expect(collect($after)->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('o boost expira depois da duração e o perfil volta à ordem normal', function () {
    $a = boostPerformer(followers: 30);
    $c = boostPerformer(followers: 10);

    boostGiveTokens($c->user, 50);
    app(BoostService::class)->boost($c, $c->user);

    expect($c->fresh()->isBoosted())->toBeTrue();

    // Passa a duração (6h) + folga.
    $this->travel(config('boost.duration_hours') + 1)->hours();

    expect($c->fresh()->isBoosted())->toBeFalse();

    $order = app(PerformerCatalogService::class)->publicSearch()->items();
    expect(collect($order)->pluck('id')->all())->toBe([$a->id, $c->id]);

    $this->travelBack();
});

it('rejeita boost sem tokens suficientes, sem carimbar nada', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 10); // custo é 50

    expect(fn () => app(BoostService::class)->boost($profile, $profile->user))
        ->toThrow(InsufficientBalanceException::class);

    expect($profile->fresh()->boosted_until)->toBeNull()
        ->and(TokenLedger::where('entry_type', 'spend_boost')->count())->toBe(0);
});

it('rejeita boost quando já está boostada (não empilha)', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 200);

    app(BoostService::class)->boost($profile, $profile->user);
    $firstUntil = $profile->fresh()->boosted_until;

    expect(fn () => app(BoostService::class)->boost($profile->fresh(), $profile->user))
        ->toThrow(BoostException::class);

    // O carimbo não mudou e só um débito aconteceu.
    expect($profile->fresh()->boosted_until->equalTo($firstUntil))->toBeTrue()
        ->and(TokenLedger::where('entry_type', 'spend_boost')->count())->toBe(1)
        ->and(app(TokenService::class)->balance($profile->user))->toBe(150);
});

it('rejeita boost quando as vagas estão cheias', function () {
    config()->set('boost.max_active', 2);

    // Ocupa as 2 vagas.
    foreach (range(1, 2) as $i) {
        $p = boostPerformer();
        boostGiveTokens($p->user, 50);
        app(BoostService::class)->boost($p, $p->user);
    }

    expect(app(BoostService::class)->activeBoostedCount())->toBe(2)
        ->and(app(BoostService::class)->availableSlots())->toBe(0);

    $third = boostPerformer();
    boostGiveTokens($third->user, 50);

    expect(fn () => app(BoostService::class)->boost($third, $third->user))
        ->toThrow(BoostException::class);

    // Não debitou o terceiro.
    expect($third->fresh()->boosted_until)->toBeNull()
        ->and(app(TokenService::class)->balance($third->user))->toBe(50);
});

it('grava uma linha spend_boost no ledger com o saldo correto', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 80);

    app(BoostService::class)->boost($profile, $profile->user);

    $entry = TokenLedger::where('entry_type', 'spend_boost')->sole();

    expect($entry->amount)->toBe(-50)      // débito é negativo
        ->and($entry->balance_after)->toBe(30) // 80 - 50
        ->and($entry->reference_type)->toBe(PerformerProfile::class)
        ->and($entry->reference_id)->toBe($profile->id)
        ->and(app(TokenService::class)->balance($profile->user))->toBe(30);
});

it('rejeita boost de performer não verificada, antes de debitar', function () {
    $profile = boostPerformer(verified: false);
    boostGiveTokens($profile->user, 50);

    expect(fn () => app(BoostService::class)->boost($profile, $profile->user))
        ->toThrow(BoostException::class);

    expect($profile->fresh()->boosted_until)->toBeNull()
        ->and(app(TokenService::class)->balance($profile->user))->toBe(50);
});

it('expõe is_boosted como booleano no resource, sem o carimbo', function () {
    $boosted = boostPerformer(followers: 5);
    $plain = boostPerformer(followers: 5);

    boostGiveTokens($boosted->user, 50);
    app(BoostService::class)->boost($boosted, $boosted->user);

    $response = $this->get(route('performers.public'));
    $response->assertOk();

    $items = collect($response->viewData('page')['props']['performers']['data']);
    $boostedItem = $items->firstWhere('slug', $boosted->slug);
    $plainItem = $items->firstWhere('slug', $plain->slug);

    expect($boostedItem['is_boosted'])->toBeTrue()
        ->and($plainItem['is_boosted'])->toBeFalse()
        // O carimbo nunca aparece nos props.
        ->and($boostedItem)->not->toHaveKey('boosted_until');

    // E nem no payload cru.
    $payload = $response->getContent();
    expect($payload)->not->toContain('boosted_until')
        ->and($payload)->not->toContain($boosted->boosted_until->toIso8601String());
});

it('o Hard Delete limpa o carimbo de boost', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 50);
    app(BoostService::class)->boost($profile, $profile->user);

    expect($profile->fresh()->boosted_until)->not->toBeNull();

    app(\App\Services\DeletionService::class)->executeDeletion($profile->user->fresh());

    // Perfil soft-deletado e com o carimbo zerado.
    $row = DB::table('performer_profiles')->where('id', $profile->id)->first();
    expect($row->boosted_until)->toBeNull();
});

it('o endpoint de boost debita e devolve o estado novo', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 80);

    $this->actingAs($profile->user)
        ->postJson(route('performer.boost'))
        ->assertOk()
        ->assertJson([
            'boosted' => true,
            'is_boosted' => true,
            'wallet' => 30,
        ]);

    expect($profile->fresh()->isBoosted())->toBeTrue()
        ->and(TokenLedger::where('entry_type', 'spend_boost')->count())->toBe(1);
});

it('o endpoint responde 422 com reason quando falta token', function () {
    $profile = boostPerformer();
    boostGiveTokens($profile->user, 10);

    $this->actingAs($profile->user)
        ->postJson(route('performer.boost'))
        ->assertStatus(422)
        ->assertJson(['reason' => 'insufficient_balance']);

    expect($profile->fresh()->boosted_until)->toBeNull();
});

it('bloqueia quem não é performer', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)
        ->postJson(route('performer.boost'))
        ->assertForbidden();
});

it('exige autenticação', function () {
    // Rota WEB: guest é redirecionado ao login (sessão), não 401.
    $this->post(route('performer.boost'))->assertRedirect(route('login'));
});
