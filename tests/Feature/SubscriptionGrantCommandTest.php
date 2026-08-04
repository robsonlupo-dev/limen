<?php

use App\Events\SubscriptionGrantPended;
use App\Models\Circle;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\SubscriptionService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Franquia mensal com fila de pendência — reconciliação (PR #133 do Sprint 14,
 * M.13.4/M.13.8).
 *
 * O caminho PRIMÁRIO da concessão é o webhook de cobrança (coberto em
 * SubscriptionTest); este command é a rede de segurança. Os dois compartilham
 * last_grant_period_start para NUNCA conceder o mesmo ciclo duas vezes. Cada
 * teste trava uma invariante; os números vêm de config/monetization.php.
 * Revisão de segurança do plano e do código rodada (regra do CLAUDE.md).
 *
 * Helpers com prefixo `sgc` — funções do Pest são globais.
 */
function sgcMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function sgcBalance(User $user): int
{
    return app(TokenService::class)->balance($user);
}

function sgcPending(User $user): int
{
    return (int) TokenWallet::where('user_id', $user->id)->value('pending_grant_tokens');
}

/** Semeia saldo por um tipo que NÃO respeita teto (setup só de teste). */
function sgcSeed(User $user, int $amount): void
{
    if ($amount > 0) {
        app(TokenService::class)->credit($user, $amount, 'adjustment', description: 'seed');
    }
}

/**
 * Assinatura ATIVA com um ciclo pendente de concessão: a marca está velha (NÃO
 * nula, != current_period_start) — é o estado "ciclo rodou sem o webhook
 * conceder" que o command reconcilia. Semeia o saldo inicial.
 */
function sgcDueSubscription(User $user, string $slug, int $balance = 0): Subscription
{
    $sub = Subscription::factory()->for($user)->circle($slug)->create();
    // Marca fora do $fillable: atribuição direta (o factory a descartaria).
    $sub->last_grant_period_start = now()->subMonthNoOverflow();
    $sub->save();
    $user->refresh();

    sgcSeed($user, $balance);

    return $sub;
}

function sgcGrant(): array
{
    return app(SubscriptionService::class)->grantDueFranchises();
}

function sgcCard(): array
{
    return [
        'holderName' => 'Fulano de Tal', 'number' => '5162306219378829',
        'expiryMonth' => '12', 'expiryYear' => '2030', 'ccv' => '123',
        'holder' => [
            'name' => 'Fulano de Tal', 'email' => 'f@t.com', 'cpfCnpj' => '24971563792',
            'postalCode' => '01310000', 'addressNumber' => '100', 'phone' => '11999999999',
        ],
    ];
}

// ─── Concessão dentro do teto ────────────────────────────────────────────────

it('concede a franquia inteira quando ha espaco', function () {
    $user = sgcMember();
    sgcDueSubscription($user, 'prestige'); // franquia 490, saldo 0

    $result = sgcGrant();

    expect($result)->toBe(['granted' => 1, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(490)
        ->and(sgcPending($user))->toBe(0);

    $entry = TokenLedger::where('entry_type', 'subscription_grant')->latest('id')->first();
    expect($entry->amount)->toBe(490)
        ->and($entry->reference_type)->toBe('subscription');
});

it('marca o ciclo concedido para nao repetir', function () {
    $user = sgcMember();
    $sub = sgcDueSubscription($user, 'prestige');

    sgcGrant();

    $sub->refresh();
    expect($sub->last_grant_period_start->equalTo($sub->current_period_start))->toBeTrue();
});

// ─── Concessão parcial + fila de pendência (M.13.8) ──────────────────────────

it('concede o que cabe e pendura o excedente (Black no teto)', function () {
    $user = sgcMember();
    // Black: franquia 1000, teto 5000. Saldo 4700 → cabe 300, pendura 700.
    sgcDueSubscription($user, 'black', 4700);

    $result = sgcGrant();

    expect($result)->toBe(['granted' => 1, 'pended' => 1, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(5000)
        ->and(sgcPending($user))->toBe(700);
});

it('concede parcial no FC com teto de 8000', function () {
    $user = sgcMember();
    // FC: franquia 2100, teto 8000. Saldo 7500 → cabe 500, pendura 1600.
    sgcDueSubscription($user, 'founders_circle', 7500);

    $result = sgcGrant();

    expect($result['granted'])->toBe(1)
        ->and(sgcBalance($user))->toBe(8000)
        ->and(sgcPending($user))->toBe(1600);
});

it('pendura a franquia inteira quando ja esta no teto', function () {
    $user = sgcMember();
    sgcDueSubscription($user, 'black', 5000); // no teto

    $result = sgcGrant();

    // Nada creditado, franquia inteira pendurada — mas o ciclo conta como concedido.
    expect($result)->toBe(['granted' => 1, 'pended' => 1, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(5000)
        ->and(sgcPending($user))->toBe(1000);
});

// ─── Idempotência (command × command) ────────────────────────────────────────

it('nao concede duas vezes no mesmo ciclo', function () {
    $user = sgcMember();
    sgcDueSubscription($user, 'prestige');

    $first = sgcGrant();
    $second = sgcGrant();

    expect($first['granted'])->toBe(1)
        ->and($second)->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(490)
        ->and(TokenLedger::where('entry_type', 'subscription_grant')->count())->toBe(1);
});

it('nao toca assinatura cuja marca ja casa com o ciclo (webhook ja concedeu)', function () {
    $user = sgcMember();
    $sub = Subscription::factory()->for($user)->circle('prestige')->create();
    // Marca == current_period_start: o webhook já concedeu este ciclo.
    $sub->last_grant_period_start = $sub->current_period_start;
    $sub->save();

    expect(sgcGrant())->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(0);
});

it('nao concede o primeiro ciclo (marca NULL — grant antecipado do webhook)', function () {
    $user = sgcMember();
    // Factory não seta a marca → NULL. É o estado "grant do primeiro mês ainda
    // vai chegar pelo webhook"; conceder aqui duplicaria (B2 da revisão).
    Subscription::factory()->for($user)->circle('prestige')->create();

    expect(sgcGrant())->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(0);
});

// ─── Pendência substitui, não empilha (M.13.8) ───────────────────────────────

it('substitui a pendencia a cada ciclo, nunca empilha', function () {
    $user = sgcMember();
    $sub = sgcDueSubscription($user, 'black', 5000); // no teto

    sgcGrant(); // ciclo 1: pendura 1000
    expect(sgcPending($user))->toBe(1000);

    // Novo ciclo: avança o período; a marca do ciclo 1 fica velha → due de novo.
    $sub->update([
        'current_period_start' => now()->addMonthNoOverflow(),
        'current_period_end' => now()->addMonthsNoOverflow(2),
    ]);

    sgcGrant(); // ciclo 2, ainda no teto: pendura 1000 (substitui, não 2000)

    expect(sgcPending($user))->toBe(1000)
        ->and(sgcBalance($user))->toBe(5000);
});

// ─── Liberação da pendência no gasto (M.13.8 — hook do TokenService::debit) ───

it('libera a pendencia no gasto, valor a valor', function () {
    $user = sgcMember();
    // Black, saldo 4900 → concede 100 (teto), pendura 900.
    sgcDueSubscription($user, 'black', 4900);
    sgcGrant();
    expect(sgcBalance($user))->toBe(5000)->and(sgcPending($user))->toBe(900);

    // Gasta 100 → abre 100 de espaço → libera 100 da pendência (volta ao teto).
    app(TokenService::class)->debit($user, 100, 'spend_tip', 'test', null, 'gasto');

    expect(sgcBalance($user))->toBe(5000)
        ->and(sgcPending($user))->toBe(800);

    $release = TokenLedger::where('reference_type', 'pending_release')->latest('id')->first();
    expect($release->entry_type)->toBe('subscription_grant')
        ->and($release->amount)->toBe(100);
});

it('nao libera a mesma pendencia duas vezes em gastos sequenciais', function () {
    $user = sgcMember();
    sgcDueSubscription($user, 'black', 5000); // no teto
    sgcGrant(); // pendura 1000

    app(TokenService::class)->debit($user, 200, 'spend_tip', 'test', null, 'g1'); // libera 200
    app(TokenService::class)->debit($user, 300, 'spend_tip', 'test', null, 'g2'); // libera 300

    // Total liberado 500 ≤ pendência 1000; nunca negativa, nunca dobrada.
    expect(sgcPending($user))->toBe(500)
        ->and(sgcBalance($user))->toBe(5000)
        ->and((int) TokenLedger::where('reference_type', 'pending_release')->sum('amount'))->toBe(500);
});

// ─── Fora do alvo: sem assinatura, expirada ──────────────────────────────────

it('nao concede a quem nao assina', function () {
    $user = sgcMember();

    expect(sgcGrant())->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(0);
});

it('nao concede a assinatura expirada', function () {
    $user = sgcMember();
    $sub = Subscription::factory()->for($user)->circle('prestige')->create([
        'current_period_end' => now()->subDay(), // período pago acabou
    ]);
    $sub->last_grant_period_start = now()->subMonthNoOverflow();
    $sub->save();

    expect(sgcGrant())->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(0);
});

// ─── Evento de aproximação do teto (M.13.8) ──────────────────────────────────

it('dispara o evento SO quando o grant gera pendencia', function () {
    Event::fake([SubscriptionGrantPended::class]);

    $comFolga = sgcMember();
    sgcDueSubscription($comFolga, 'prestige'); // saldo 0 → sem pendência

    $noTeto = sgcMember();
    sgcDueSubscription($noTeto, 'black', 5000); // no teto → pendura 1000

    sgcGrant();

    Event::assertDispatchedTimes(SubscriptionGrantPended::class, 1);
    Event::assertDispatched(
        SubscriptionGrantPended::class,
        fn (SubscriptionGrantPended $e) => $e->userId === $noTeto->id
            && $e->tier === 'black'
            && $e->pendedTokens === 1000
            && $e->cap === 5000,
    );
});

it('dispara o evento tambem no caminho primario do webhook (S1)', function () {
    // O caso mais comum de pendência é o assinante recorrente com saldo alto: a
    // franquia da renovação bate no teto. O aviso tem que sair aqui, não só na
    // reconciliação.
    $user = User::factory()->create();
    $black = Circle::where('slug', 'black')->firstOrFail(); // franquia 1000, teto 5000
    $sub = app(SubscriptionService::class)->subscribe($user, $black, sgcCard());
    sgcSeed($user, 4000); // 1000 (1º mês) + 4000 == teto 5000

    Event::fake([SubscriptionGrantPended::class]);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    app(SubscriptionService::class)->handleWebhook(
        $fake->simulateSubscriptionCharged($sub->asaas_subscription_id),
    );

    // Renovação no teto: nada creditado, 1000 pendurado → evento disparado.
    expect(sgcBalance($user))->toBe(5000)
        ->and(sgcPending($user))->toBe(1000);
    Event::assertDispatched(
        SubscriptionGrantPended::class,
        fn (SubscriptionGrantPended $e) => $e->userId === $user->id
            && $e->tier === 'black'
            && $e->pendedTokens === 1000
            && $e->cap === 5000,
    );
});

// ─── Wiring do command + regressão do B2 (webhook marca o ciclo) ─────────────

it('roda pelo artisan e reporta a contagem', function () {
    $user = sgcMember();
    sgcDueSubscription($user, 'insider'); // franquia 230

    $this->artisan('subscriptions:grant-monthly')
        ->expectsOutputToContain('granted=1')
        ->assertExitCode(0);

    expect(sgcBalance($user))->toBe(230);
});

it('o webhook marca o ciclo, entao o command nao redobra (regressao B2)', function () {
    // Caminho de produção: subscribe concede o 1º mês e marca o ciclo.
    $user = User::factory()->create();
    $prestige = Circle::where('slug', 'prestige')->firstOrFail();
    $sub = app(SubscriptionService::class)->subscribe($user, $prestige, sgcCard());

    expect(sgcBalance($user))->toBe(490);
    $sub->refresh();
    expect($sub->last_grant_period_start)->not->toBeNull()
        ->and($sub->last_grant_period_start->equalTo($sub->current_period_start))->toBeTrue();

    // O command não acha nada a reconciliar — o webhook já marcou o ciclo.
    expect(sgcGrant())->toBe(['granted' => 0, 'pended' => 0, 'failed' => 0])
        ->and(sgcBalance($user))->toBe(490)
        ->and(TokenLedger::where('entry_type', 'subscription_grant')->count())->toBe(1);
});

// ─── Sync DB × config: os dois caminhos concedem o MESMO valor (B1) ──────────

it('mantem circle.monthly_tokens igual a franquia da config em todo tier', function () {
    foreach (config('monetization.franchises_by_tier') as $slug => $franchise) {
        $circle = Circle::where('slug', $slug)->first();
        expect($circle)->not->toBeNull("circle {$slug} ausente")
            ->and($circle->monthly_tokens)->toBe(
                $franchise,
                "monthly_tokens de {$slug} divergiu da franquia da config",
            );
    }
});
