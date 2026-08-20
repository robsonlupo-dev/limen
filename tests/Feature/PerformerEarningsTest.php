<?php

use App\Services\TipService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Extrato de GANHOS da performer (fix/chat-ux-mobile) — SOMENTE LEITURA. A
 * performer confere que o líquido é a taxa congelada sobre o bruto pago pelo membro
 * (1,6000 = 80% de 2), e o membro aparece SEMPRE por FanAlias, nunca por dado real.
 *
 * Reusa os helpers globais (tests/Pest.php): chatPerformer, chatUnlockedPair,
 * grantChatAccess.
 */

// Gera uma gorjeta (tip_credit) + uma abertura de chat (chat_access_credit) na
// carteira da performer e devolve [performer, member].
function earningsFixture(): array
{
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 60);

    // Dado real do membro, para provar que NÃO vaza no extrato.
    $member->forceFill([
        'name' => 'Nome Real Do Membro',
        'email' => 'membro-real-sentinela@example.test',
    ])->save();

    // Gorjeta 50 → performer credita 80% = 40,0000 (bruto 50).
    app(TipService::class)->send($member, $performer, 50, (string) Str::uuid());
    // Abre chat (custo 2) → chat_access_credit 1,6000 (bruto 2). credit_ledger_id
    // liga a linha ao crédito, e é por ali que o membro do chat é resolvido.
    grantChatAccess($member, $conversation);

    return [$performer, $member->fresh()];
}

it('lista os créditos com bruto, percentual e líquido, o membro por FanAlias', function () {
    [$performer, $member] = earningsFixture();
    $alias = FanAlias::label($performer->id, $member->id);

    $response = $this->actingAs($performer->user)->get(route('performer.earnings.index'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Performer/Earnings/Index')
        ->has('entries.data', 2)
        // id desc: a abertura de chat foi a última.
        ->where('entries.data.0.type_label', 'Mensagem')
        ->where('entries.data.0.gross', 2)
        ->where('entries.data.0.applied_rate', 80)
        ->where('entries.data.0.net', '1.6000')       // 80% de 2, exato, 4 casas
        ->where('entries.data.0.member_alias', $alias)
        ->where('entries.data.1.type_label', 'Gorjeta')
        ->where('entries.data.1.gross', 50)
        ->where('entries.data.1.applied_rate', 80)
        ->where('entries.data.1.net', '40.0000')      // 80% de 50
        ->where('entries.data.1.member_alias', $alias)
        // Saldo no topo COM casas decimais (contrato do extrato).
        ->where('summary.balance', fn ($b) => str_contains((string) $b, '.'))
    );

    expect($alias)->toStartWith('Fã #');
});

it('NUNCA expõe dado real do membro no extrato (só FanAlias)', function () {
    [$performer, $member] = earningsFixture();

    $response = $this->actingAs($performer->user)->get(route('performer.earnings.index'));

    $response->assertOk();
    $response->assertDontSee($member->email, false);
    $response->assertDontSee('Nome Real Do Membro', false);
});

it('resolve o membro do chat pelo elo reverso (chat não grava o alias na description)', function () {
    [$performer, $member] = earningsFixture();
    $alias = FanAlias::label($performer->id, $member->id);

    // O crédito de chat tem description "Acesso ao chat recebido" (sem alias); o
    // membro tem que vir do chat_access.credit_ledger_id, não de "Membro".
    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index', ['type' => 'chat']))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.type_label', 'Mensagem')
            ->where('entries.data.0.member_alias', $alias));

    expect($alias)->not->toBe('Membro');
});

it('filtra por tipo de ganho', function () {
    [$performer] = earningsFixture();

    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index', ['type' => 'tip']))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.type_label', 'Gorjeta'));

    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index', ['type' => 'chat']))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.type_label', 'Mensagem'));
});

it('filtra por período (limites de data)', function () {
    [$performer] = earningsFixture();

    // Os dois créditos são de HOJE. Um teto ONTEM exclui os dois; um piso AMANHÃ
    // também. Sem filtro, os dois aparecem.
    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index', ['to' => now()->subDay()->toDateString()]))
        ->assertInertia(fn ($page) => $page->has('entries.data', 0));

    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index', ['from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn ($page) => $page->has('entries.data', 0));

    $this->actingAs($performer->user)
        ->get(route('performer.earnings.index'))
        ->assertInertia(fn ($page) => $page->has('entries.data', 2));
});

it('só a dona vê o próprio extrato — outra performer não vê estes créditos', function () {
    [$performer] = earningsFixture();
    $other = chatPerformer();

    // A outra performer, sem ganhos, vê o extrato VAZIO (o wallet é por usuário).
    $this->actingAs($other->user)
        ->get(route('performer.earnings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 0));
});
