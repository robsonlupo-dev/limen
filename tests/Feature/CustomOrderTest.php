<?php

use App\Exceptions\CustomOrderException;
use App\Models\ContentUnlock;
use App\Models\CustomOrder;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ContentVisibilityService;
use App\Services\CustomOrderService;
use App\Services\PerformerEarningsService;
use App\Services\TokenService;
use App\Support\CustomOrderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Encomenda de conteúdo sob medida com ESCROW — roadmap social, Onda 4 (§4.3). O membro
 * pede+oferece; a performer aceita (DEBITA o membro = escrow), entrega (peça do cofre,
 * moderada), e o membro aprova (libera 80/20) ou contesta (segura p/ a moderação). Cron
 * expira/estorna/libera por tempo. Eixos: (1) dinheiro exato e idempotente por
 * escrow_settled; (2) autorização de dono no service (404); (3) anonimato (FanAlias); (4)
 * escopo da peça (nunca vaza na vitrine, revogada no estorno); (5) máquina de estado.
 * Prefixo `co`.
 */
function coService(): CustomOrderService
{
    return app(CustomOrderService::class);
}

/** Par membro+performer, com o membro já com saldo. */
function coPair(int $balance = 0): array
{
    $performer = chatPerformer();
    $member = chatMember($balance);

    return [$performer, $member];
}

/** Pedido REQUESTED pronto para a performer aceitar. */
function coRequest(PerformerProfile $performer, User $member, int $price = 100): CustomOrder
{
    return coService()->request($member, $performer, 'Um vídeo dançando', $price);
}

/** Foto entregue (pronta na hora) para uma encomenda aceita. */
function coDeliverPhoto(PerformerProfile $performer, CustomOrder $order): CustomOrder
{
    return coService()->deliver(
        $performer->user,
        $order,
        UploadedFile::fake()->image('co.jpg', 640, 480),
    );
}

// ─── Dinheiro: aceite debita o escrow; aprovação libera 80/20 ──────────────────────

it('aceite debita o membro (escrow) e a aprovação credita 80% à performer', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);

    // Aceite: debita 100 do membro (escrow), sem creditar ninguém ainda.
    coService()->accept($performer->user, $order);
    expect($tokens->balance($member))->toBe(200)
        ->and($tokens->balance($performer->user))->toBe(0);

    $order->refresh();
    expect($order->status)->toBe(CustomOrder::STATUS_ACCEPTED)
        ->and($order->spend_ledger_id)->not->toBeNull()
        ->and($order->escrow_settled)->toBeFalse();

    // Entrega + aprovação: libera 80/20.
    $order = coDeliverPhoto($performer, $order);
    coService()->approve($member, $order);

    expect($tokens->balance($performer->user))->toBe(80)
        ->and($tokens->balance($member))->toBe(200); // membro não recebe de volta

    $order->refresh();
    expect($order->status)->toBe(CustomOrder::STATUS_RELEASED)
        ->and($order->escrow_settled)->toBeTrue()
        ->and($order->settle_ledger_id)->not->toBeNull();
});

it('aceite sem saldo suficiente falha e não move nada', function () {
    [$performer, $member] = coPair(50); // pediu 100, só tem 50
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);

    expect(fn () => coService()->accept($performer->user, $order))
        ->toThrow(CustomOrderException::class);

    expect($tokens->balance($member))->toBe(50);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_REQUESTED);
});

it('aprovar é idempotente: a segunda aprovação não credita de novo', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    coService()->approve($member, $order);
    expect($tokens->balance($performer->user))->toBe(80);

    // Segunda chamada: já released → 410, sem novo crédito.
    expect(fn () => coService()->approve($member, $order))
        ->toThrow(CustomOrderException::class);
    expect($tokens->balance($performer->user))->toBe(80);
});

it('a encomenda liberada aparece no extrato de ganhos da performer', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);
    coService()->approve($member, $order);

    // Regressão: o crédito custom_order_credit não pode ser filtrado pela allowlist do
    // extrato (PerformerEarningsService::TYPE_MAP) — antes ele sumia da tela mesmo com o
    // saldo correto.
    $page = app(PerformerEarningsService::class)->paginate($performer->user, []);
    $rows = collect($page->items());
    $co = $rows->firstWhere('type_key', 'custom');

    expect($co)->not->toBeNull()
        ->and($co['type_label'])->toBe('Encomenda')
        ->and($co['gross'])->toBe(100)
        ->and($co['net'])->toBe('80.0000')
        ->and($co['member_alias'])->toStartWith('Fã');
});

// ─── Estornos ──────────────────────────────────────────────────────────────────────

it('a performer recusa: o membro nunca é debitado', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);

    coService()->decline($performer->user, $order);

    expect($tokens->balance($member))->toBe(300);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DECLINED);
});

it('disputa resolvida a favor do membro estorna 100% e revoga o acesso à peça', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);
    $pieceId = $order->fresh()->performer_content_id;

    // Membro tem acesso à peça enquanto decide.
    expect(ContentUnlock::where('performer_content_id', $pieceId)->where('user_id', $member->id)->exists())->toBeTrue();

    coService()->dispute($member, $order, 'Não foi o que combinamos na descrição.');
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DISPUTED)
        ->and($order->fresh()->dispute_reason)->toBe('Não foi o que combinamos na descrição.');

    coService()->resolveDispute($order->fresh(), 'refund');

    expect($tokens->balance($member))->toBe(300) // estorno integral
        ->and($tokens->balance($performer->user))->toBe(0);
    $order->refresh();
    expect($order->status)->toBe(CustomOrder::STATUS_REFUNDED)
        ->and($order->escrow_settled)->toBeTrue();
    // Acesso revogado — o membro foi reembolsado, não fica com o conteúdo.
    expect(ContentUnlock::where('performer_content_id', $pieceId)->where('user_id', $member->id)->exists())->toBeFalse();
});

it('disputa resolvida a favor da performer libera 80/20', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);
    coService()->dispute($member, $order, 'A imagem veio cortada e sem o combinado.');

    coService()->resolveDispute($order->fresh(), 'release');

    expect($tokens->balance($performer->user))->toBe(80);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_RELEASED);
});

// ─── Recado da entrega + motivo da contestação (Parte 2) ───────────────────────────

it('a entrega guarda o recado da performer e o expõe aos dois lados', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);

    $order = coService()->deliver(
        $performer->user,
        $order,
        UploadedFile::fake()->image('co.jpg', 640, 480),
        '  Espero que goste!  ',
    );

    // Gravado e com trim.
    expect($order->fresh()->delivery_message)->toBe('Espero que goste!');
    // Visível na projeção do membro e da performer, e para a moderação.
    expect(CustomOrderPresenter::forMember($order->fresh())['delivery_message'])->toBe('Espero que goste!')
        ->and(CustomOrderPresenter::forPerformer($order->fresh())['delivery_message'])->toBe('Espero que goste!');
});

it('recado vazio na entrega vira null (não grava recado em branco)', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);

    $order = coService()->deliver(
        $performer->user,
        $order,
        UploadedFile::fake()->image('co.jpg', 640, 480),
        '   ',
    );

    expect($order->fresh()->delivery_message)->toBeNull();
});

it('a contestação exige um motivo e o guarda na linha', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    // Sem motivo → 422 (não contesta).
    $this->actingAs($member)
        ->postJson(route('custom-orders.dispute', $order->id), [])
        ->assertStatus(422);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DELIVERED);

    // Motivo curto demais → 422.
    $this->actingAs($member)
        ->postJson(route('custom-orders.dispute', $order->id), ['motivo' => 'ruim'])
        ->assertStatus(422);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DELIVERED);

    // Motivo curto PADDED com espaços (burlaria o piso se medido sem trim) → 422.
    $this->actingAs($member)
        ->postJson(route('custom-orders.dispute', $order->id), ['motivo' => 'ruim      '])
        ->assertStatus(422);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DELIVERED);

    // Motivo válido → contesta e grava.
    $this->actingAs($member)
        ->postJson(route('custom-orders.dispute', $order->id), ['motivo' => 'Não foi o que combinamos.'])
        ->assertStatus(200);
    $order->refresh();
    expect($order->status)->toBe(CustomOrder::STATUS_DISPUTED)
        ->and($order->dispute_reason)->toBe('Não foi o que combinamos.');

    // E chega à moderação.
    expect(CustomOrderPresenter::forModeration($order->fresh())['dispute_reason'])->toBe('Não foi o que combinamos.');
});

it('o motivo da contestação barra troca de contato (SafeProfileText)', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    $this->actingAs($member)
        ->postJson(route('custom-orders.dispute', $order->id), ['motivo' => 'me chama no zap 11 99999-8888 por favor'])
        ->assertStatus(422);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_DELIVERED);
});

// ─── Prova da disputa visível para a moderação (PR B) ──────────────────────────────

it('o moderador vê a mídia da peça em disputa; fora da disputa é 404', function () {
    $moderator = User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    // Entregue, ainda NÃO contestado → a prova não é servível (404 uniforme).
    $this->actingAs($moderator)
        ->get(route('moderacao.custom-orders.media', $order->id))
        ->assertStatus(404);

    // Contestado → o moderador vê a foto entregue.
    coService()->dispute($member, $order, 'Não foi o combinado, quero revisar.');
    $this->actingAs($moderator)
        ->get(route('moderacao.custom-orders.media', $order->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    // A projeção de moderação expõe a URL da prova.
    expect(CustomOrderPresenter::forModeration($order->fresh())['delivered']['image_url'])->toContain('/midia');
});

it('a mídia da disputa é negada a quem não é moderador', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);
    coService()->dispute($member, $order, 'Problema com a entrega, por favor revisar.');

    // O próprio membro (consumer) não alcança a porta de moderação.
    $this->actingAs($member)
        ->get(route('moderacao.custom-orders.media', $order->id))
        ->assertForbidden();
});

// ─── Cron por tempo ──────────────────────────────────────────────────────────────

it('expira pedidos não aceitos além da janela sem mover token', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);

    // Empurra a criação para trás da janela de aceite.
    $order->forceFill(['created_at' => now()->subHours((int) config('custom_order.accept_window_hours') + 1)])->save();

    $n = coService()->expireStaleRequests();
    expect($n)->toBe(1);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_EXPIRED);
    expect($tokens->balance($member))->toBe(300);
});

it('estorna aceitos não entregues além da janela de entrega', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    expect($tokens->balance($member))->toBe(200);

    $order->forceFill(['accepted_at' => now()->subHours((int) config('custom_order.deliver_window_hours') + 1)])->save();

    $n = coService()->refundUndelivered();
    expect($n)->toBe(1);
    expect($tokens->balance($member))->toBe(300); // 100% de volta
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_REFUNDED);
});

it('libera automaticamente entregas prontas passada a janela de contestação', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    $order->forceFill(['dispute_deadline_at' => now()->subMinute()])->save();

    $n = coService()->autoReleaseDelivered();
    expect($n)->toBe(1);
    expect($tokens->balance($performer->user))->toBe(80);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_RELEASED);
});

it('auto-release estorna quando a mídia não ficou pronta', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    // Simula uma entrega que não pôde ser vista (vídeo que falhou/travou).
    $order->deliveredContent->forceFill(['status' => PerformerContent::STATUS_FAILED])->save();
    $order->forceFill(['dispute_deadline_at' => now()->subMinute()])->save();

    coService()->autoReleaseDelivered();

    expect($tokens->balance($member))->toBe(300) // estorno integral, não libera
        ->and($tokens->balance($performer->user))->toBe(0);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_REFUNDED);
});

it('não deixa o membro aprovar entrega cuja mídia ainda não está pronta', function () {
    [$performer, $member] = coPair(300);
    $tokens = app(TokenService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);

    // Mídia em processamento → aprovar deve ser recusado (o escrow não se libera).
    $order->deliveredContent->forceFill(['status' => PerformerContent::STATUS_PROCESSING])->save();

    expect(fn () => coService()->approve($member, $order->fresh()))
        ->toThrow(CustomOrderException::class);
    expect($tokens->balance($performer->user))->toBe(0);
    expect(CustomOrderPresenter::forMember($order->fresh())['can_approve'])->toBeFalse();
});

// ─── Autorização (dono no service, 404 uniforme) ─────────────────────────────────

it('outro membro não cancela/aprova a encomenda alheia', function () {
    [$performer, $member] = coPair(300);
    $intruder = chatMember(300);
    $order = coRequest($performer, $member, 100);

    expect(fn () => coService()->cancel($intruder, $order))
        ->toThrow(CustomOrderException::class);

    coService()->accept($performer->user, $order);
    coDeliverPhoto($performer, $order);
    expect(fn () => coService()->approve($intruder, $order))
        ->toThrow(CustomOrderException::class);
});

it('outra performer não aceita/entrega a encomenda alheia', function () {
    [$performer, $member] = coPair(300);
    $other = chatPerformer();
    $order = coRequest($performer, $member, 100);

    expect(fn () => coService()->accept($other->user, $order))
        ->toThrow(CustomOrderException::class);
    expect($order->fresh()->status)->toBe(CustomOrder::STATUS_REQUESTED);
});

// ─── Validação e anti-flood ──────────────────────────────────────────────────────

it('recusa preço fora do piso/passo/teto', function () {
    [$performer, $member] = coPair(300);

    expect(fn () => coService()->request($member, $performer, 'x', 3)) // abaixo do piso
        ->toThrow(CustomOrderException::class);
    expect(fn () => coService()->request($member, $performer, 'x', 22)) // fora do passo
        ->toThrow(CustomOrderException::class);
});

it('respeita o teto de encomendas ativas por par', function () {
    [$performer, $member] = coPair(300);
    $max = (int) config('custom_order.max_active_per_pair');
    for ($i = 0; $i < $max; $i++) {
        coRequest($performer, $member, 100);
    }

    expect(fn () => coRequest($performer, $member, 100))
        ->toThrow(CustomOrderException::class);
});

// ─── Anonimato + escopo da peça ──────────────────────────────────────────────────

it('a projeção para a performer usa FanAlias e nunca o member_id', function () {
    [$performer, $member] = coPair(300);
    $order = coRequest($performer, $member, 100);

    $view = CustomOrderPresenter::forPerformer($order->fresh());
    expect($view)->not->toHaveKey('member_id')
        ->and($view['fan'])->toBeString()
        ->and($view['fan'])->not->toContain((string) $member->id);
});

it('a peça entregue não aparece na vitrine pública nem para outro membro', function () {
    [$performer, $member] = coPair(300);
    $vis = app(ContentVisibilityService::class);
    $order = coRequest($performer, $member, 100);
    coService()->accept($performer->user, $order);
    $order = coDeliverPhoto($performer, $order);
    $piece = $order->fresh()->deliveredContent;

    // Não entra na galeria de ninguém (nem visitante, nem outro membro, nem o comprador).
    $stranger = chatMember(0);
    foreach ([null, $stranger, $member] as $viewer) {
        $ids = collect($vis->galleryFor($viewer, $performer))->pluck('id');
        expect($ids)->not->toContain($piece->id);
    }

    // Só o membro dono do unlock enxerga a peça; um estranho não.
    expect($vis->canView($member, $piece))->toBeTrue()
        ->and($vis->canView($stranger, $piece))->toBeFalse();
});
