<?php

use App\Services\ChatService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * fix/chat-ux-mobile: a lista de conversas (Mensagens) mostra o OUTRO participante
 * em cada linha — a performer via o PRÓPRIO nome em todas (o payload só trazia o
 * dela) e não distinguia uma conversa da outra. Do lado da performer, o membro vem
 * por FanAlias (nunca dado real, M.13.10); do lado do membro, a performer.
 */

it('mostra o MEMBRO por FanAlias em cada linha da lista da performer', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // Dado real do membro, para provar que NÃO vaza na lista da performer.
    $member->forceFill([
        'name' => 'Nome Real Do Membro',
        'email' => 'membro-sentinela@example.test',
    ])->save();

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi!');

    $alias = FanAlias::label($performer->id, $member->id);

    $response = $this->actingAs($performer->user)->get(route('chat.index'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('viewerIsPerformer', true)
        ->where('conversations.data.0.title', $alias)
        // NUNCA o próprio nome da performer, nem "Membro" genérico.
        ->where('conversations.data.0.title', fn ($t) => $t !== $performer->stage_name && $t !== 'Membro'));

    // Nem o nome/e-mail reais do membro trafegam.
    $response->assertDontSee('Nome Real Do Membro', false);
    $response->assertDontSee($member->email, false);
    expect($alias)->toStartWith('Fã #');
});

it('mostra a PERFORMER (nome público) em cada linha da lista do membro', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi!');

    $this->actingAs($member)->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('viewerIsPerformer', false)
            ->where('conversations.data.0.title', $performer->stage_name));
});

it('cada linha da performer traz o alias do SEU membro (não o mesmo em todas)', function () {
    $performer = chatPerformer();
    [$m1, $c1] = chatUnlockedPair($performer, balance: 5);
    [$m2, $c2] = chatUnlockedPair($performer, balance: 5);

    app(ChatService::class)->sendMessage($c1, $performer->user, 'oi um');
    app(ChatService::class)->sendMessage($c2, $performer->user, 'oi dois'); // mais recente

    // Ordem por last_message_at desc: c2 (m2) primeiro, c1 (m1) depois. Cada linha
    // casa com o alias do SEU membro — a prova de que não é o mesmo nome em todas.
    $this->actingAs($performer->user)->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 2)
            ->where('conversations.data.0.title', FanAlias::label($performer->id, $m2->id))
            ->where('conversations.data.1.title', FanAlias::label($performer->id, $m1->id)));
});
