<?php

use App\Models\User;
use App\Services\BroadcastService;
use Inertia\Testing\AssertableInertia as Assert;

// Abas do Painel da performer (reorg do Painel, UAT Fase 21): Stories · Canal ·
// Ajustes, e Pessoas › Solicitações. Cada uma é página Inertia própria (como
// Resumo e Insights já eram). Os contratos de prop das seções que SAÍRAM do
// Resumo continuam cobertos pelos testes que apontam para `performer.dashboard`
// — o controller ainda os devolve na transição.

function ptPendingPerformer(): User
{
    $profile = chatPerformer();
    $profile->user->forceFill(['status' => 'pending'])->save();

    return $profile->user->fresh();
}

it('Stories rende a página com os stories, os níveis e os destaques', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->get(route('performer.panel.stories'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Panel/Stories')
            ->has('stories')
            ->has('storyVisibilityLevels')
            ->has('storySubscriberMinTiers')
            ->has('storyInviteLimit')
            ->has('highlights')
            ->has('highlightStories'));
});

it('Canal rende o compositor e o histórico do que ELA enviou — só corpo e hora', function () {
    $performer = chatPerformer();
    app(BroadcastService::class)->send($performer, 'Hoje à noite tem story novo.');

    $this->actingAs($performer->user)
        ->get(route('performer.panel.channel'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Panel/Channel')
            ->has('broadcastMaxLength')
            ->where('broadcasts.0.body', 'Hoje à noite tem story novo.')
            ->has('broadcasts.0.created_at')
            // Quem leu NÃO existe por desenho: a entrega é derivada de follows.
            ->missing('broadcasts.0.reads')
            ->missing('broadcasts.0.read_count'));
});

it('Ajustes rende saldo + destaque (com elegibilidade), e aceita a performer pendente', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->get(route('performer.panel.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Panel/Settings')
            ->has('wallet')
            ->has('boost.eligible')
            ->has('boost.cost')
            // O carimbo `boosted_until` nunca trafega — só o booleano + faixa.
            ->missing('boost.boosted_until'));

    // Pendente (KYC em curso) configura sons como qualquer uma — mesmo gate do Resumo.
    $this->actingAs(ptPendingPerformer())
        ->get(route('performer.panel.settings'))
        ->assertOk();
});

it('Solicitações rende pedidos de acesso, fotos recebidas e visitantes — sem id de membro', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->get(route('performer.people.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/People/Requests')
            ->has('photoAccessRequests')
            ->has('receivedPhotos')
            ->has('visitors')
            ->has('visitorsVisible')
            ->has('anonymityFloor')
            ->has('canSendVisitorInterest')
            ->has('visitorInterestRemaining'));

    // Varredura de substring, como o StoryEndpointsTest faz: nenhum id cru.
    $content = $this->actingAs($performer->user->fresh())
        ->get(route('performer.people.requests'))
        ->getContent();
    expect($content)->not->toContain('"user_id"')
        ->and($content)->not->toContain('"member_id"');
});

it('as abas de uso diário exigem performer ATIVA; o membro não alcança nenhuma', function () {
    $pending = ptPendingPerformer();

    // Stories, Canal e Solicitações acionam rotas performer-active — a aba
    // nem abre para quem ainda está em KYC (oferecer seria oferecer 403).
    $this->actingAs($pending)->get(route('performer.panel.stories'))->assertForbidden();
    $this->actingAs($pending)->get(route('performer.panel.channel'))->assertForbidden();
    $this->actingAs($pending)->get(route('performer.people.requests'))->assertForbidden();

    $consumer = chatMember();
    foreach (['performer.panel.stories', 'performer.panel.channel', 'performer.panel.settings', 'performer.people.requests'] as $name) {
        $this->actingAs($consumer)->get(route($name))->assertForbidden();
    }
});
