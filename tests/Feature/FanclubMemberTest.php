<?php

use App\Models\FanclubMembership;
use App\Models\PerformerContent;
use App\Models\TokenLedger;
use App\Services\FanclubService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Fã-Clube — lado do MEMBRO (Onda 4, fork da assinatura). Assinar/desvincular pela porta
 * HTTP + a visão do perfil (memberView) e o acesso ao set. Eixos: (1) dinheiro 80/20 via
 * HTTP; (2) autorização (só consumer); (3) set só destrava p/ assinante; (4) card do perfil
 * correto. Prefixo `fcm`.
 */
function fcmOpenClub($performer, int $price = 50): void
{
    app(FanclubService::class)->saveSettings($performer, true, $price);
}

function fcmPiece($performer): PerformerContent
{
    return PerformerContent::forceCreate([
        'performer_profile_id' => $performer->id,
        'kind' => PerformerContent::KIND_PHOTO,
        'status' => 'ready',
        'access_level' => PerformerContent::LEVEL_EXCLUSIVE,
        'price_tokens' => 0,
        'path' => $performer->id.'/'.Str::random(16).'.jpg',
        'content_hash' => str_repeat('a', 64),
        'fanclub' => true,
    ]);
}

// ─── Assinar / desvincular via HTTP ─────────────────────────────────────────────

it('o membro assina pela rota, debita o saldo e credita 80% à performer', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fcmOpenClub($performer, 50);
    $tokens = app(TokenService::class);

    $this->actingAs($member)
        ->postJson(route('fanclub.subscribe', $performer->slug))
        ->assertStatus(201)
        ->assertJson(['status' => 'active']);

    expect($tokens->balance($member))->toBe(250)
        ->and($tokens->balance($performer->user))->toBe(40); // 80% de 50

    $m = FanclubMembership::where('member_id', $member->id)->where('performer_profile_id', $performer->id)->first();
    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE);

    $credit = TokenLedger::where('entry_type', 'fanclub_sub_credit')->latest('id')->first();
    expect((int) $credit->applied_rate)->toBe(80);
});

it('assinar sem saldo devolve 422 e não move nada', function () {
    $performer = chatPerformer();
    $member = chatMember(30); // preço 50
    fcmOpenClub($performer, 50);

    $this->actingAs($member)
        ->postJson(route('fanclub.subscribe', $performer->slug))
        ->assertStatus(422)
        ->assertJson(['reason' => 'insufficient_balance']);

    expect(FanclubMembership::count())->toBe(0);
});

it('assinar fã-clube fechado devolve 422', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    // sem abrir o clube

    $this->actingAs($member)
        ->postJson(route('fanclub.subscribe', $performer->slug))
        ->assertStatus(422)
        ->assertJson(['reason' => 'not_open']);
});

it('desvincular mantém o acesso até o fim do ciclo (cancel_requested)', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fcmOpenClub($performer, 50);
    app(FanclubService::class)->subscribe($member, $performer);

    $this->actingAs($member)
        ->postJson(route('fanclub.cancel', $performer->slug))
        ->assertStatus(200)
        ->assertJson(['status' => 'cancel_requested']);

    $m = FanclubMembership::where('member_id', $member->id)->first();
    expect($m->status)->toBe(FanclubMembership::STATUS_ACTIVE)
        ->and($m->cancel_requested)->toBeTrue();
});

it('uma performer não pode assinar (rota é só de consumer)', function () {
    $performer = chatPerformer();
    $outra = chatPerformer();
    fcmOpenClub($performer, 50);

    $this->actingAs($outra->user)
        ->postJson(route('fanclub.subscribe', $performer->slug))
        ->assertStatus(403);
});

// ─── memberView + acesso ao set ─────────────────────────────────────────────────

it('o card do perfil mostra preço e teaser para quem não assina; set destravado para assinante', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fcmOpenClub($performer, 50);
    fcmPiece($performer);
    $svc = app(FanclubService::class);

    // Não assina: aberto, preço, não-assinante, set em teaser (locked, sem URL).
    $view = $svc->memberView($member, $performer);
    expect($view['open'])->toBeTrue()
        ->and($view['price_tokens'])->toBe(50)
        ->and($view['is_subscribed'])->toBeFalse()
        ->and($view['set'])->toHaveCount(1)
        ->and($view['set'][0]['locked'])->toBeTrue()
        ->and($view['set'][0]['image_url'])->toBeNull();

    // Assina → set destravado (image_url presente).
    $svc->subscribe($member, $performer);
    $view2 = $svc->memberView($member, $performer);
    expect($view2['is_subscribed'])->toBeTrue()
        ->and($view2['set'][0]['locked'])->toBeFalse()
        ->and($view2['set'][0]['image_url'])->not->toBeNull();
});

it('visitante deslogado vê o card com teaser bloqueado, sem bytes', function () {
    $performer = chatPerformer();
    fcmOpenClub($performer, 50);
    fcmPiece($performer);

    // viewer null (guest no perfil público): aberto, preço público, set só teaser.
    $view = app(FanclubService::class)->memberView(null, $performer);
    expect($view['open'])->toBeTrue()
        ->and($view['is_subscribed'])->toBeFalse()
        ->and($view['set'])->toHaveCount(1)
        ->and($view['set'][0]['locked'])->toBeTrue()
        ->and($view['set'][0]['image_url'])->toBeNull()
        ->and($view['set'][0]['video_url'])->toBeNull();
});

it('preço VIP aparece no card para Black/FC', function () {
    $performer = chatPerformer();
    app(FanclubService::class)->saveSettings($performer, true, 50, true, 30);

    $black = chatMember(300);
    \App\Models\Subscription::factory()->circle('black')->create([
        'user_id' => $black->id, 'status' => 'active', 'current_period_end' => now()->addMonth(),
    ]);

    $view = app(FanclubService::class)->memberView($black, $performer);
    expect($view['price_tokens'])->toBe(30)
        ->and($view['price_tier'])->toBe('vip');
});

it('a página Minhas assinaturas lista a assinatura do membro', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fcmOpenClub($performer, 50);
    app(FanclubService::class)->subscribe($member, $performer);

    $this->actingAs($member)
        ->get(route('fanclub.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Consumer/Fanclub/Index')
            ->has('subscriptions', 1)
        );
});
