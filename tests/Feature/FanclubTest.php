<?php

use App\Exceptions\FanclubException;
use App\Models\FanclubMembership;
use App\Models\FanclubSettings;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\ContentVisibilityService;
use App\Services\FanclubService;
use App\Services\PerformerContentService;
use App\Services\TokenService;
use App\Support\LedgerEntryLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Fã-Clube da performer — Onda 4, fork da assinatura (docs/FORK_ASSINATURA.md). Assinatura
 * recorrente por-performer, em token, 80/20 (rate 'content'). Eixos: (1) dinheiro exato e
 * idempotente; (2) ciclo recorrente (renova/carência/pausa/cancela); (3) serving por
 * assinatura ATIVA e set privado (não vaza na vitrine, não é unlock por peça); (4)
 * anonimato (FanAlias); (5) payout (fatia sacável) e rótulos. Prefixo `fcl`.
 */
function fclService(): FanclubService
{
    return app(FanclubService::class);
}

function fclVis(): ContentVisibilityService
{
    return app(ContentVisibilityService::class);
}

/** Abre o fã-clube da performer com um preço público (e, opcional, VIP). */
function fclOpen(PerformerProfile $profile, int $public = 50, bool $vip = false, ?int $vipPrice = null): FanclubSettings
{
    return fclService()->saveSettings($profile, true, $public, $vip, $vipPrice);
}

/** Uma peça marcada como de fã-clube (pronta por padrão). */
function fclPiece(PerformerProfile $profile, bool $ready = true): PerformerContent
{
    return PerformerContent::forceCreate([
        'performer_profile_id' => $profile->id,
        'kind' => PerformerContent::KIND_PHOTO,
        'status' => $ready ? 'ready' : 'processing',
        'access_level' => PerformerContent::LEVEL_EXCLUSIVE,
        'price_tokens' => 0,
        'path' => $profile->id.'/'.Str::random(16).'.jpg',
        'content_hash' => str_repeat('a', 64),
        'fanclub' => true,
    ]);
}

/** Força a assinatura a estar vencida (período no passado) para o cron pegar. */
function fclExpire(FanclubMembership $m): void
{
    $m->forceFill(['current_period_start' => now()->subDays(31), 'current_period_end' => now()->subMinute()])->save();
}

// ─── Dinheiro: assinar debita o membro e credita 80% à performer ───────────────────

it('assinar debita o membro e credita 80% à performer e ativa a assinatura', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    $tokens = app(TokenService::class);
    fclOpen($performer, 50);

    $m = fclService()->subscribe($member, $performer);

    expect($tokens->balance($member))->toBe(250)
        ->and($tokens->balance($performer->user))->toBe(40); // 80% de 50 (inteiro → int, contrato TokenMath::readable)

    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE)
        ->and($m->price_tokens)->toBe(50)
        ->and($m->price_tier)->toBe('public')
        ->and($m->current_period_end->isFuture())->toBeTrue();
});

it('assinar de novo com assinatura ativa é idempotente — não recobra', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    $tokens = app(TokenService::class);
    fclOpen($performer, 50);

    fclService()->subscribe($member, $performer);
    fclService()->subscribe($member, $performer); // 2ª vez: não cobra

    expect($tokens->balance($member))->toBe(250) // só um débito de 50
        ->and(FanclubMembership::where('member_id', $member->id)->count())->toBe(1);
});

it('assinar sem saldo falha e não move nada', function () {
    $performer = chatPerformer();
    $member = chatMember(30); // preço 50
    $tokens = app(TokenService::class);
    fclOpen($performer, 50);

    expect(fn () => fclService()->subscribe($member, $performer))
        ->toThrow(FanclubException::class);

    expect($tokens->balance($member))->toBe(30)
        ->and($tokens->balance($performer->user))->toBe(0)
        ->and(FanclubMembership::count())->toBe(0);
});

it('a performer não pode assinar o próprio fã-clube', function () {
    $performer = chatPerformer();
    fclOpen($performer, 50);

    expect(fn () => fclService()->subscribe($performer->user, $performer))
        ->toThrow(FanclubException::class);
});

it('um não-consumidor (ex.: performer) não pode assinar — serving só atende consumer', function () {
    $alvo = chatPerformer();
    $outra = chatPerformer(); // outra performer tentando assinar o clube da primeira
    fclOpen($alvo, 50);

    expect(fn () => fclService()->subscribe($outra->user, $alvo))
        ->toThrow(FanclubException::class);
    expect(FanclubMembership::count())->toBe(0);
});

it('não dá pra assinar fã-clube fechado', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    // sem fclOpen → fechado

    expect(fn () => fclService()->subscribe($member, $performer))
        ->toThrow(FanclubException::class);
});

// ─── Preço VIP: Black/FC pagam o preço oculto; trava público ≥ VIP ─────────────────

it('preço VIP vale para Black/FC e o público para os demais', function () {
    $performer = chatPerformer();
    $tokens = app(TokenService::class);
    fclOpen($performer, 50, true, 30); // público 50, VIP 30

    $free = chatMember(300);
    $black = chatMember(300);
    Subscription::factory()->circle('black')->create([
        'user_id' => $black->id, 'status' => 'active', 'current_period_end' => now()->addMonth(),
    ]);

    $mFree = fclService()->subscribe($free, $performer);
    $mBlack = fclService()->subscribe($black, $performer);

    expect($mFree->price_tokens)->toBe(50)->and($mFree->price_tier)->toBe('public');
    expect($mBlack->price_tokens)->toBe(30)->and($mBlack->price_tier)->toBe('vip');
    expect($tokens->balance($black))->toBe(270); // pagou o VIP (30), não o público
});

it('o sistema trava preço VIP maior que o público', function () {
    $performer = chatPerformer();

    expect(fn () => fclService()->saveSettings($performer, true, 50, true, 80)) // VIP > público
        ->toThrow(FanclubException::class);
});

it('preço fora do piso/passo/teto é recusado', function () {
    $performer = chatPerformer();

    expect(fn () => fclService()->saveSettings($performer, true, 7))->toThrow(FanclubException::class);   // < piso 20
    expect(fn () => fclService()->saveSettings($performer, true, 22))->toThrow(FanclubException::class);  // não múltiplo de 5
    expect(fn () => fclService()->saveSettings($performer, true, 5000))->toThrow(FanclubException::class); // > teto 2000
});

// ─── Serving: só assinante ativo vê o set; set é privado ───────────────────────────

it('assinante ativo vê a peça de fã-clube; não-assinante não', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    $outro = chatMember(300);
    fclOpen($performer, 50);
    $piece = fclPiece($performer);

    expect(fclVis()->canView($member, $piece))->toBeFalse(); // ainda não assinou
    fclService()->subscribe($member, $performer);
    $piece->refresh();

    expect(fclVis()->canView($member, $piece))->toBeTrue()   // assinante vê
        ->and(fclVis()->canView($outro, $piece))->toBeFalse() // quem não assina, não
        ->and(fclVis()->canView(null, $piece))->toBeFalse();  // visitante, não
    // A dona sempre vê o próprio conteúdo.
    expect(fclVis()->canView($performer->user, $piece))->toBeTrue();
});

it('peça de fã-clube não aparece na vitrine nem no painel e não é desbloqueável', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fclOpen($performer, 50);
    $piece = fclPiece($performer);

    // Vitrine pública (galleryFor) e painel da performer (forOwner) não listam a peça.
    $gallery = fclVis()->galleryFor($member, $performer);
    expect(collect($gallery)->pluck('id'))->not->toContain($piece->id);

    $panel = app(PerformerContentService::class)->forOwner($performer);
    expect($panel->pluck('id'))->not->toContain($piece->id);

    // Não é desbloqueável por peça (acesso é só a assinatura).
    expect(fclVis()->canUnlock($member, $piece))->toBeFalse();
});

// ─── Ciclo: renovação, carência, pausa, cancelamento ───────────────────────────────

it('a renovação cobra de novo e empurra o período', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    $tokens = app(TokenService::class);
    fclOpen($performer, 50);

    $m = fclService()->subscribe($member, $performer); // -50 → 250
    $endBefore = $m->current_period_end->copy();
    fclExpire($m);

    $counts = fclService()->processRenewals();

    expect($counts['renewed'])->toBe(1)
        ->and($tokens->balance($member))->toBe(200)            // cobrou mais 50
        ->and($tokens->balance($performer->user))->toBe(80); // 40 + 40 (inteiro → int)

    $m->refresh();
    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE)
        ->and($m->current_period_end->isFuture())->toBeTrue();
});

it('sem saldo na renovação entra em carência (mantém acesso) e depois pausa (perde acesso)', function () {
    $performer = chatPerformer();
    $member = chatMember(50); // só dá para a 1ª cobrança
    fclOpen($performer, 50);
    $piece = fclPiece($performer);

    $m = fclService()->subscribe($member, $performer); // -50 → 0
    fclExpire($m);

    // 1ª renovação sem saldo → carência (acesso mantido).
    expect(fclService()->processRenewals()['graced'])->toBe(1);
    $m->refresh();
    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE)
        ->and($m->grace_until)->not->toBeNull();
    expect(fclVis()->canView($member, $piece))->toBeTrue(); // ainda vê na carência

    // Carência vencida e ainda sem saldo → pausa (perde acesso).
    $m->forceFill(['grace_until' => now()->subMinute()])->save();
    expect(fclService()->processRenewals()['paused'])->toBe(1);
    $m->refresh();
    expect($m->status)->toBe(FanclubMembership::STATUS_PAUSED);
    expect(fclVis()->canView($member, $piece))->toBeFalse(); // acesso cai
});

it('a carência é resolvida quando o membro recarrega antes de vencer', function () {
    $performer = chatPerformer();
    $member = chatMember(50);
    $tokens = app(TokenService::class);
    fclOpen($performer, 50);

    $m = fclService()->subscribe($member, $performer); // 0
    fclExpire($m);
    fclService()->processRenewals(); // graced
    $tokens->credit($member, 50, 'purchase'); // recarrega

    expect(fclService()->processRenewals()['renewed'])->toBe(1);
    $m->refresh();
    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE)
        ->and($m->grace_until)->toBeNull();
});

it('desvincular mantém acesso até o fim do ciclo e o cron encerra', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fclOpen($performer, 50);
    $piece = fclPiece($performer);

    $m = fclService()->subscribe($member, $performer);
    fclService()->cancel($member, $performer);
    $m->refresh();

    expect($m->cancel_requested)->toBeTrue()
        ->and($m->status)->toBe(FanclubMembership::STATUS_ACTIVE); // ainda ativo no ciclo pago
    expect(fclVis()->canView($member, $piece))->toBeTrue();        // acesso mantido

    fclExpire($m);
    expect(fclService()->processRenewals()['cancelled'])->toBe(1);
    $m->refresh();
    expect($m->status)->toBe(FanclubMembership::STATUS_CANCELLED);
    expect(fclVis()->canView($member, $piece))->toBeFalse();       // acesso encerra
});

// ─── Payout, anonimato e rótulos ───────────────────────────────────────────────────

it('fanclub_sub_credit é ganho sacável; spend_fanclub_sub não', function () {
    $earning = config('monetization.payout.earning_entry_types');

    expect($earning)->toContain('fanclub_sub_credit')
        ->and($earning)->not->toContain('spend_fanclub_sub');
});

it('o crédito da performer descreve o fã por FanAlias, nunca id/nome', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fclOpen($performer, 50);

    fclService()->subscribe($member, $performer);

    $alias = \App\Support\FanAlias::label($performer->id, $member->id);
    $credit = TokenLedger::where('entry_type', 'fanclub_sub_credit')->latest('id')->first();
    expect($credit)->not->toBeNull()
        ->and($credit->description)->toBe('Fã-clube assinado por '.$alias)
        ->and($credit->description)->toContain('Fã #')
        ->and($credit->description)->not->toContain($member->email);
});

it('os rótulos cobrem os tipos novos do fã-clube', function () {
    expect(LedgerEntryLabel::for('spend_fanclub_sub'))->not->toBe('Movimento')
        ->and(LedgerEntryLabel::for('fanclub_sub_credit'))->not->toBe('Movimento');
});
