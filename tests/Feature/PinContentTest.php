<?php

use App\Exceptions\ContentException;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ContentVisibilityService;
use App\Services\PerformerContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Fixar conteúdo na vitrine — roadmap social, Onda 3 (§ 3.1). A performer prende
 * peças no TOPO; a ordem é ÚNICA (vitrine pública e painel). Teto em config; só a
 * dona fixa; só peça pronta; fixado que o espectador não alcança fica no topo
 * BLOQUEADO (decisão do PO). Prefixo `pin` para não colidir com outros arquivos.
 */
function pinPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function pinMember(?string $tier = null): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($tier !== null) {
        Subscription::factory()->for($user)->circle($tier)->create();
        $user->refresh();
    }

    return $user;
}

/** Peça direta no banco (sem o pipeline de publish) — ordenação e pin não precisam de bytes. */
function pinPiece(PerformerProfile $profile, string $level = 'open', string $status = 'ready'): PerformerContent
{
    return PerformerContent::forceCreate([
        'performer_profile_id' => $profile->id,
        'kind' => PerformerContent::KIND_PHOTO,
        'status' => $status,
        'access_level' => $level,
        'price_tokens' => 20,
        'path' => $profile->id.'/'.Str::random(16).'.jpg',
        'content_hash' => str_repeat('a', 64),
    ]);
}

function pinSvc(): PerformerContentService
{
    return app(PerformerContentService::class);
}

// ─── Toggle + autoridade ─────────────────────────────────────────────────────

it('a dona fixa e desafixa a própria peça', function () {
    $performer = pinPerformer();
    $piece = pinPiece($performer);

    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $piece->id), ['on' => true])
        ->assertOk()->assertJsonPath('pinned', true);
    expect($piece->fresh()->isPinned())->toBeTrue();

    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $piece->id), ['on' => false])
        ->assertOk()->assertJsonPath('pinned', false);
    expect($piece->fresh()->isPinned())->toBeFalse();
});

it('não-dona não fixa a peça de outra (404, máscara offline)', function () {
    $owner = pinPerformer();
    $piece = pinPiece($owner);
    $outra = pinPerformer();

    $this->actingAs($outra->user)
        ->postJson(route('performer.content.pin', $piece->id), ['on' => true])
        ->assertNotFound();

    expect($piece->fresh()->isPinned())->toBeFalse();
});

// ─── Teto ────────────────────────────────────────────────────────────────────

it('respeita o teto de destaques (o excedente é 422 pin_cap)', function () {
    config(['content.max_pinned' => 3]);
    $performer = pinPerformer();
    $pieces = collect(range(1, 4))->map(fn () => pinPiece($performer));

    // Fixa 3 — ok.
    foreach ($pieces->take(3) as $p) {
        pinSvc()->setPinned($performer, $p, true);
    }

    // O 4º estoura o teto.
    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $pieces[3]->id), ['on' => true])
        ->assertStatus(422)->assertJsonPath('reason', ContentException::PIN_CAP);

    expect($pieces[3]->fresh()->isPinned())->toBeFalse();
    expect(PerformerContent::whereNotNull('pinned_at')->count())->toBe(3);
});

it('refixar uma já fixada não reconta no teto (idempotente)', function () {
    config(['content.max_pinned' => 1]);
    $performer = pinPerformer();
    $piece = pinPiece($performer);

    pinSvc()->setPinned($performer, $piece, true);
    // De novo: no-op, não estoura o teto de 1.
    pinSvc()->setPinned($performer->fresh(), $piece->fresh(), true);

    expect(PerformerContent::whereNotNull('pinned_at')->count())->toBe(1);
});

// ─── Só peça pronta ──────────────────────────────────────────────────────────

it('não fixa peça que não está pronta (vídeo em processamento) — 422', function () {
    $performer = pinPerformer();
    $processing = pinPiece($performer, 'open', PerformerContent::STATUS_PROCESSING);

    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $processing->id), ['on' => true])
        ->assertStatus(422)->assertJsonPath('reason', ContentException::PIN_NOT_READY);

    expect($processing->fresh()->isPinned())->toBeFalse();
});

// ─── Ordenação: fixado primeiro (vitrine pública e painel) ───────────────────

it('põe as fixadas no topo da vitrine pública, o resto por mais nova', function () {
    $performer = pinPerformer();
    $a = pinPiece($performer); // mais antiga
    $b = pinPiece($performer);
    $c = pinPiece($performer); // mais nova

    // Sem fixar: mais nova primeiro (c, b, a).
    $order = collect(app(ContentVisibilityService::class)->galleryFor(null, $performer))->pluck('id');
    expect($order->all())->toBe([$c->id, $b->id, $a->id]);

    // Fixa a mais antiga → sobe ao topo; resto segue por id desc.
    pinSvc()->setPinned($performer, $a, true);
    $order = collect(app(ContentVisibilityService::class)->galleryFor(null, $performer))->pluck('id');
    expect($order->all())->toBe([$a->id, $c->id, $b->id]);

    // A última fixada fica acima da anterior (pinned_at desc).
    $this->travel(1)->minute();
    pinSvc()->setPinned($performer->fresh(), $b->fresh(), true);
    $order = collect(app(ContentVisibilityService::class)->galleryFor(null, $performer))->pluck('id');
    expect($order->all())->toBe([$b->id, $a->id, $c->id]);
});

it('usa a MESMA ordem no painel da performer e expõe o flag pinned', function () {
    $performer = pinPerformer();
    $a = pinPiece($performer);
    $b = pinPiece($performer);
    pinSvc()->setPinned($performer, $a, true);

    $rows = pinSvc()->forOwner($performer->fresh());
    expect($rows->pluck('id')->all())->toBe([$a->id, $b->id]);
    expect($rows->firstWhere('id', $a->id)['pinned'])->toBeTrue();
    expect($rows->firstWhere('id', $b->id)['pinned'])->toBeFalse();
});

// ─── Denúncia aberta congela o "fixar" (moderação primeiro) ───────────────────

it('não fixa peça sob denúncia aberta (409), mas deixa desafixar', function () {
    $performer = pinPerformer();
    $piece = pinPiece($performer);
    $member = pinMember();
    Report::open($member, $piece, 'coercion', 'em análise');

    // Fixar é congelado sob denúncia aberta.
    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $piece->id), ['on' => true])
        ->assertStatus(409)->assertJsonPath('reason', ContentException::UNDER_REVIEW);
    expect($piece->fresh()->isPinned())->toBeFalse();

    // Já fixada antes da denúncia: desafixar continua permitido (reduzir prominência).
    $piece->forceFill(['pinned_at' => now()])->save();
    $this->actingAs($performer->user)
        ->postJson(route('performer.content.pin', $piece->id), ['on' => false])
        ->assertOk()->assertJsonPath('pinned', false);
    expect($piece->fresh()->isPinned())->toBeFalse();
});

// ─── Fixado bloqueado fica no topo, com cadeado (decisão do PO) ───────────────

it('mantém no topo o fixado que o espectador não alcança, porém bloqueado', function () {
    $performer = pinPerformer();
    $aberto = pinPiece($performer, 'open');       // qualquer um vê
    $exclusivo = pinPiece($performer, 'exclusive'); // exige Black+

    // Fixa o exclusivo.
    pinSvc()->setPinned($performer, $exclusivo, true);

    // Espectador sem assinatura: o exclusivo fica no TOPO, mas locked. (O 'aberto'
    // também é locked para não-assinante — nível open é grátis só para assinante,
    // não-assinante paga; o ponto aqui é a ORDEM, não o cadeado do aberto.)
    $anon = pinMember();
    $gallery = collect(app(ContentVisibilityService::class)->galleryFor($anon, $performer));

    // Fixado bloqueado permanece no topo; o não-fixado vem depois.
    expect($gallery->pluck('id')->all())->toBe([$exclusivo->id, $aberto->id]);
    expect($gallery->firstWhere('id', $exclusivo->id)['locked'])->toBeTrue();
    expect($gallery->firstWhere('id', $exclusivo->id)['pinned'])->toBeTrue();
    expect($gallery->firstWhere('id', $aberto->id)['pinned'])->toBeFalse();
});
