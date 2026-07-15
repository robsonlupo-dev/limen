<?php

use App\Models\AuditLog;
use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasHttpClient;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\TokenService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function fundPerformerWallet(User $performer, int $tokens): void
{
    app(TokenService::class)->credit($performer, $tokens, 'purchase');
}

function payoutPayload(array $overrides = []): array
{
    return array_merge([
        'tokens' => 1000,
        'pix_key_type' => 'email',
        'pix_key' => 'performer@example.com',
    ], $overrides);
}

// ─── Acesso ─────────────────────────────────────────────────────────────────

it('performer ativo acessa payouts', function () {
    [$performer] = makeWebPerformer();

    $this->actingAs($performer)
        ->get('/performer/payouts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Payouts/Index')
            ->where('kycOk', true)
        );
});

it('performer sem kyc ve aviso e nao pode solicitar saque', function () {
    [$performer] = makeWebPerformer([], ['is_verified' => false]);
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)
        ->get('/performer/payouts')
        ->assertInertia(fn (Assert $page) => $page->where('kycOk', false));

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload())
        ->assertSessionHasErrors('kyc');

    expect(Payout::count())->toBe(0);
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(2000);
});

it('consumer nao acessa rota de payout', function () {
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/performer/payouts')
        ->assertForbidden();
});

it('performer pending nao acessa rota de payout', function () {
    [$performer] = makeWebPerformer(['status' => 'pending']);

    $this->actingAs($performer)
        ->get('/performer/payouts')
        ->assertForbidden();
});

it('visitante e redirecionado para login', function () {
    $this->get('/performer/payouts')
        ->assertRedirect(route('login'));
});

// ─── Validação ──────────────────────────────────────────────────────────────

it('saque abaixo de 500 tokens e rejeitado', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 100]))
        ->assertSessionHasErrors('tokens');

    expect(Payout::count())->toBe(0);
});

it('saque acima de 50000 tokens e rejeitado', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 100000);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 50001]))
        ->assertSessionHasErrors('tokens');

    expect(Payout::count())->toBe(0);
});

it('saldo insuficiente e rejeitado', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 500);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionHasErrors('tokens');

    expect(Payout::count())->toBe(0);
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(500);
});

// ─── Cálculo ────────────────────────────────────────────────────────────────

it('valor brl e calculado pelo servidor nao pelo request', function () {
    [$performer, $profile] = makeWebPerformer([], ['split_pct' => 65]);
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 1000, 'amount_brl' => 999999.99]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    $expected = round((1000 * 99 * 65) / 1000 / 100, 2);

    expect((float) $payout->amount_brl)->toBe($expected);
});

it('split_pct e lido do performer_profile nao do request', function () {
    [$performer, $profile] = makeWebPerformer([], ['split_pct' => 70]);
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 1000, 'split_pct' => 10]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    $expectedAt70 = round((1000 * 99 * 70) / 1000 / 100, 2);
    $expectedAt10 = round((1000 * 99 * 10) / 1000 / 100, 2);

    expect((float) $payout->amount_brl)->toBe($expectedAt70);
    expect((float) $payout->amount_brl)->not->toBe($expectedAt10);
});

// ─── Ledger ─────────────────────────────────────────────────────────────────

it('tokens sao debitados do ledger ao solicitar saque', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)
        ->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000);

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    $ledger = TokenLedger::where('reference_type', 'payout')
        ->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reserve')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount)->toBe(-1000);
});

it('saldo nao fica negativo em solicitacoes sequenciais acima do saldo', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 800]))
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 800]))
        ->assertSessionHasErrors('tokens');

    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBeGreaterThanOrEqual(0);
    expect(Payout::where('performer_id', $performer->id)->count())->toBe(1);
});

// ─── Webhook ────────────────────────────────────────────────────────────────

it('transfer_paid marca payout como paid', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    expect($payout->status)->toBe('processing');

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_transfer_paid_1',
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => $payout->asaas_transfer_id],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->status)->toBe('paid');
    expect($payout->processed_at)->not->toBeNull();
});

it('webhook TRANSFER_DONE (evento real do Asaas) marca o payout como paid', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();
    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    expect($payout->status)->toBe('processing');

    config(['asaas.webhook_token' => 'valid-token']);

    // Payload real do Asaas: event=TRANSFER_DONE, status=DONE (não "TRANSFER_PAID").
    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_transfer_done_real',
        'event' => 'TRANSFER_DONE',
        'transfer' => [
            'id' => $payout->asaas_transfer_id,
            'externalReference' => "payout_{$payout->id}",
            'status' => 'DONE',
        ],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->status)->toBe('paid');
    expect($payout->processed_at)->not->toBeNull();
});

it('transfer_paid marca como paid mesmo se o payout ainda estiver pending (webhook corre à frente)', function () {
    [$performer] = makeWebPerformer();

    // Payout gravado mas ainda 'pending' — o update para 'processing' não aconteceu
    // (webhook chegou antes, ou o processo morreu logo após criar a transferência).
    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'pending',
        'asaas_transfer_id' => 'transfer_race_1',
        'requested_at' => now(),
    ]);

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_transfer_paid_race',
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => 'transfer_race_1'],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->status)->toBe('paid');
    expect($payout->processed_at)->not->toBeNull();
});

it('transfer_failed marca payout como failed e estorna tokens', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000);

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_transfer_failed_1',
        'event' => 'TRANSFER_FAILED',
        'transfer' => ['id' => $payout->asaas_transfer_id, 'failReason' => 'Chave PIX inválida'],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->status)->toBe('failed');
    expect($payout->failure_reason)->toContain('Chave PIX inválida');
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(2000);
});

it('estorno cria linha no ledger tipo payout_reversal', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_transfer_failed_2',
        'event' => 'TRANSFER_FAILED',
        'transfer' => ['id' => $payout->asaas_transfer_id],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $reversal = TokenLedger::where('reference_type', 'payout')
        ->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')
        ->first();

    expect($reversal)->not->toBeNull();
    expect($reversal->amount)->toBe(1000);
});

it('webhook e idempotente por event_id', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();

    config(['asaas.webhook_token' => 'valid-token']);
    $headers = ['asaas-access-token' => 'valid-token'];
    $payload = [
        'id' => 'evt_idem_transfer_1',
        'event' => 'TRANSFER_FAILED',
        'transfer' => ['id' => $payout->asaas_transfer_id],
    ];

    $this->postJson('/api/webhooks/asaas/transfer', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/asaas/transfer', $payload, $headers)->assertOk();

    expect(PaymentEvent::where('provider_event_id', 'evt_idem_transfer_1')->count())->toBe(1);
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(2000);

    $reversalCount = TokenLedger::where('reference_type', 'payout')
        ->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')
        ->count();
    expect($reversalCount)->toBe(1);
});

it('webhook rejeita token invalido', function () {
    config(['asaas.webhook_token' => 'correct-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => 'transfer_x'],
    ], ['asaas-access-token' => 'wrong-token'])->assertStatus(401);
});

it('falha sincrona no asaas marca payout como failed e estorna imediatamente', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextTransferFailure();

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();
    expect($payout->status)->toBe('failed');
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(2000);
});

it('falha ambigua (timeout) NAO estorna e deixa o payout em processing para reconciliar', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextTransferUnavailable();

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();

    // Ambíguo: não estorna (senão pagaria em dobro se o Asaas mandou o PIX).
    expect($payout->status)->toBe('processing');
    expect($payout->asaas_transfer_id)->toBeNull();
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000);
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
});

/**
 * O furo achado na revisão do merge do hardening: o AsaasHttpClient devolvia um
 * 429 como AsaasRequestException ("definitivo") e o PayoutService estornava a
 * reserva — se o Asaas já tivesse aceitado a transferência, pagamento em dobro.
 *
 * Este teste atravessa o client HTTP REAL de propósito. O bug morava na
 * fronteira entre classificar o erro e decidir estornar, e os testes que usam o
 * FakeAsaasClient nunca a cruzavam: cada lado estava certo isoladamente.
 */
it('429 no createTransfer NAO estorna — atravessa o client HTTP real', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    config([
        'asaas.base_url' => 'https://sandbox.asaas.com/api/v3',
        'asaas.api_key' => 'sandbox-key',
    ]);
    app()->bind(AsaasClientInterface::class, fn () => new AsaasHttpClient());

    Http::fake([
        'sandbox.asaas.com/api/v3/transfers' => Http::response(['errors' => [['description' => 'rate limited']]], 429),
    ]);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();

    // Ambíguo: fica em processing e o reconcile resolve. Nada de estorno.
    expect($payout->status)->toBe('processing');
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000);
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'payout.unconfirmed']);
});

it('reconcile confirma como paid um payout ambiguo cuja transferencia existe e foi concluida', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextTransferUnavailable(); // grava a transfer no fake mas lança (resposta "perdida")

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();
    $payout = Payout::where('performer_id', $performer->id)->firstOrFail();

    // O Asaas conclui a transferência; resolvemos pelo externalReference.
    $transferId = $fake->findTransfersByExternalReference("payout_{$payout->id}")['data'][0]['id'];
    $fake->simulateTransferPaid($transferId);

    $payout->update(['requested_at' => now()->subMinutes(20)]); // passa da janela de reconcile

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('paid');
    expect($payout->asaas_transfer_id)->toBe($transferId);
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000); // sem estorno
});

it('reconcile NAO estorna automaticamente um payout ambiguo sem transferencia — sinaliza revisao manual', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    // Payout 'processing' sem transfer id e reserva debitada — como se o createTransfer
    // tivesse dado timeout. NÃO sabemos se a transferência saiu, então estornar seria
    // arriscar pagamento em dobro. Deve ficar para revisão manual, sem estorno.
    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'requested_at' => now()->subMinutes(20),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('processing'); // não estornado
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(0); // tokens NÃO voltaram
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'payout.reconcile_unresolved']);
});

it('reconcile NAO estorna quando o lookup do transfer falha (ex.: 429) — apenas adia', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'asaas_transfer_id' => 'transfer_known_1', // temos o id: a transferência FOI criada
        'requested_at' => now()->subMinutes(20),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextGetTransferFailure(); // getTransfer estoura (429), como num batch

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('processing'); // adiado, não estornado
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(0);
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
});

/**
 * O irmão do teste acima, pelo ramo DEFINITIVO: um 404 no lookup também não pode
 * estornar. Ter o asaas_transfer_id gravado prova que a transferência foi criada,
 * então "não encontrada agora" é problema de leitura, não licença para devolver
 * tokens de um PIX que pode ter saído.
 *
 * Este ramo ficou sem cobertura quando o FakeAsaasClient passou a classificar o
 * 429 como ambíguo (espelhando o client real): o teste acima migrou de catch e
 * deixou o `\Throwable` órfão.
 */
it('reconcile NAO estorna quando o lookup do transfer falha de forma definitiva (404)', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        // Id gravado, mas ausente no provedor → getTransfer lança AsaasRequestException.
        'asaas_transfer_id' => 'transfer_que_o_asaas_nao_acha',
        'requested_at' => now()->subMinutes(20),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('processing');
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(0);
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
});

/**
 * A porta de saída do furo 1: o teste acima (20min) continua tentando, mas depois de
 * RECONCILE_REVIEW_AFTER_HOURS não é mais atraso de indexação. Sem isto o payout era
 * re-consultado a cada 10min para sempre e os tokens sumiam da carteira por tempo
 * indeterminado, com um log como único sinal.
 */
it('payout irresolvivel ha mais de 2h vai para needs_review — sem estorno', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'requested_at' => now()->subHours(3),
        'unresolved_since' => now()->subHours(3), // 3h de buscas vazias, não só 3h de idade
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('needs_review');
    // Continua sem saber se o PIX saiu: parar de tentar NÃO é licença para estornar.
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(0);
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'payout.needs_review']);
});

/**
 * O prazo conta das buscas vazias, NÃO da idade do payout. Um outage do Asaas adia
 * sem gastar lookup nenhum: se o prazo contasse de requested_at, a janela inteira
 * queimaria durante o outage e o payout estacionaria na primeira busca limpa — com
 * orçamento zero de tentativa, estacionando um lote inteiro de uma vez.
 */
it('payout velho mas ainda sem streak de buscas vazias ganha a janela inteira', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    // Nasceu há 3h (outage longo), mas nenhuma busca chegou a rodar: unresolved_since null.
    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'requested_at' => now()->subHours(3),
        'unresolved_since' => null,
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('processing'); // primeira busca vazia: tenta de novo, não estaciona
    expect($payout->unresolved_since)->not->toBeNull(); // streak começa agora
});

it('streak zera quando o lookup volta a resolver', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $transfer = $fake->createTransfer(['value' => 64.35, 'external_reference' => 'payout_streak']);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'asaas_transfer_id' => $transfer['id'], // agora encontrável, status PENDING
        'requested_at' => now()->subHours(3),
        'unresolved_since' => now()->subHours(1), // streak anterior, do tempo em que sumia
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    $payout->refresh();
    expect($payout->status)->toBe('processing');
    expect($payout->unresolved_since)->toBeNull();
});

/**
 * Corrida estreita: um webhook NÃO-terminal (TRANSFER_CREATED) resolve pelo
 * external_reference e grava o asaas_transfer_id sem mexer no status. Se isso cai
 * entre a busca e o parque, estacionar tiraria do lote um payout que o getTransfer
 * resolveria no run seguinte — e, se o DONE se perdesse depois, congelaria.
 *
 * O client aqui injeta o webhook DENTRO da busca, que é a única forma de fechar a
 * janela de forma determinística.
 */
it('nao estaciona payout que ganhou asaas_transfer_id durante a corrida', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'requested_at' => now()->subHours(3),
        'unresolved_since' => now()->subHours(3), // prazo esgotado: sem a guarda, estacionaria
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app()->instance(AsaasClientInterface::class, new class extends FakeAsaasClient
    {
        public function findTransfersByExternalReference(string $externalReference): array
        {
            // O webhook TRANSFER_CREATED chega exatamente agora: grava o id, não mexe
            // no status. A busca em si continua vazia (índice ainda frio).
            Payout::where('id', (int) substr($externalReference, strlen('payout_')))
                ->update(['asaas_transfer_id' => 'transfer_do_webhook']);

            return ['data' => []];
        }
    });

    app(\App\Services\PayoutService::class)->reconcile();

    expect($payout->refresh()->status)->toBe('processing'); // volta pro lote, não estaciona
});

it('payout em needs_review sai do lote do reconcile e nao e mais consultado', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    // A transferência existe e está DONE no provedor: se o reconcile ainda pegasse
    // este payout, ele viraria 'paid'. Continuar em needs_review é a prova de que o
    // lote não cresce mais com ele.
    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $transfer = $fake->createTransfer([
        'value' => 64.35,
        'external_reference' => 'payout_999',
    ]);
    $fake->simulateTransferPaid($transfer['id']);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'needs_review',
        'asaas_transfer_id' => $transfer['id'],
        'requested_at' => now()->subHours(3),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->reconcile();

    expect($payout->refresh()->status)->toBe('needs_review');
});

/**
 * needs_review encerra as tentativas do reconcile, mas não pode congelar o payout:
 * se o webhook chegar (mesmo dias depois), ele ainda manda.
 */
it('webhook TRANSFER_DONE ainda liquida um payout parado em needs_review', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'needs_review',
        'asaas_transfer_id' => 'transfer_review_done',
        'requested_at' => now()->subHours(3),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->handleWebhook([
        'id' => 'evt_review_done',
        'event' => 'TRANSFER_DONE',
        'transfer' => ['id' => 'transfer_review_done', 'status' => 'DONE'],
    ]);

    expect($payout->refresh()->status)->toBe('paid');
});

it('webhook TRANSFER_FAILED em needs_review estorna os tokens', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 1000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'needs_review',
        'asaas_transfer_id' => 'transfer_review_failed',
        'requested_at' => now()->subHours(3),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(\App\Services\PayoutService::class)->handleWebhook([
        'id' => 'evt_review_failed',
        'event' => 'TRANSFER_FAILED',
        'transfer' => ['id' => 'transfer_review_failed', 'failReason' => 'Chave PIX inválida'],
    ]);

    $payout->refresh();
    expect($payout->status)->toBe('failed');
    // Estado terminal EXPLÍCITO do Asaas: aqui estornar é o certo.
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(1000);
});

// ─── Segurança ──────────────────────────────────────────────────────────────

it('chave pix de outro performer nao e acessivel', function () {
    [$performerA] = makeWebPerformer();
    [$performerB] = makeWebPerformer();
    fundPerformerWallet($performerA, 2000);
    fundPerformerWallet($performerB, 2000);

    $this->actingAs($performerA)->post('/performer/payouts', payoutPayload(['tokens' => 1000, 'pix_key' => 'a@example.com']))
        ->assertSessionDoesntHaveErrors();
    $this->actingAs($performerB)->post('/performer/payouts', payoutPayload(['tokens' => 1000, 'pix_key' => 'b@example.com']))
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($performerB)
        ->get('/performer/payouts/history')
        ->assertInertia(fn (Assert $page) => $page
            ->has('payouts.data', 1)
            ->where('payouts.data.0.pix_key_masked', 'b***@example.com')
        );
});

// ─── Fallback por external_reference (corrida webhook × gravação do transfer_id) ──

it('webhook resolve payout pelo external_reference quando asaas_transfer_id ainda nao foi gravado (transfer_paid)', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    // Simulates the window between debiting tokens (creating the pending Payout) and our
    // own asaas_transfer_id update landing, in which the webhook can already arrive.
    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => 64.35,
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'asaas_transfer_id' => null,
        'requested_at' => now(),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id);

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_race_paid_1',
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => 'transfer_race_1', 'externalReference' => "payout_{$payout->id}"],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->asaas_transfer_id)->toBe('transfer_race_1');
    expect($payout->status)->toBe('paid');
    expect($payout->processed_at)->not->toBeNull();
});

it('webhook resolve payout pelo external_reference quando asaas_transfer_id ainda nao foi gravado (transfer_failed)', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => 64.35,
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'asaas_transfer_id' => null,
        'requested_at' => now(),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id);

    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_race_failed_1',
        'event' => 'TRANSFER_FAILED',
        'transfer' => ['id' => 'transfer_race_2', 'externalReference' => "payout_{$payout->id}", 'failReason' => 'Erro simulado'],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $payout->refresh();
    expect($payout->asaas_transfer_id)->toBe('transfer_race_2');
    expect($payout->status)->toBe('failed');
    expect(TokenWallet::where('user_id', $performer->id)->value('balance'))->toBe(2000);
});

it('webhook com external_reference invalido ou inexistente nao quebra e nao afeta outro payout', function () {
    config(['asaas.webhook_token' => 'valid-token']);

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_race_bad_1',
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => 'transfer_race_bad', 'externalReference' => 'payout_999999999'],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    $this->postJson('/api/webhooks/asaas/transfer', [
        'id' => 'evt_race_bad_2',
        'event' => 'TRANSFER_PAID',
        'transfer' => ['id' => 'transfer_race_bad_2', 'externalReference' => 'payout_'],
    ], ['asaas-access-token' => 'valid-token'])->assertOk();

    expect(PaymentEvent::whereIn('provider_event_id', ['evt_race_bad_1', 'evt_race_bad_2'])->count())->toBe(2);
    expect(PaymentEvent::where('provider_event_id', 'evt_race_bad_1')->value('payout_id'))->toBeNull();
});

it('registra audit log ao solicitar saque', function () {
    [$performer] = makeWebPerformer();
    fundPerformerWallet($performer, 2000);

    $this->actingAs($performer)->post('/performer/payouts', payoutPayload(['tokens' => 1000]))
        ->assertSessionDoesntHaveErrors();

    expect(AuditLog::where('action', 'payout.requested')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'payout.processing')->exists())->toBeTrue();
});
