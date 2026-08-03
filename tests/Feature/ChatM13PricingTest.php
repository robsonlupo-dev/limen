<?php

use App\Models\Subscription;
use App\Models\TokenWallet;
use App\Services\ChatService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;

/**
 * M.13.1 (PR #132) — fim do chat grátis de assinante. Guardas específicas do
 * rewiring que a revisão de segurança exigiu, além dos testes de preço/crédito
 * em ChatPhase1Test. `chatPerformer`/`chatUnlockedPair`/`grantChatAccess` são
 * helpers globais do Pest.php.
 */

it('does not leak the last-message preview to a subscriber without a paid chat line', function () {
    // Regressão do ALTO 1 da revisão: o index() tinha um 2º paywall por
    // activeSubscription. Sem atalho, o assinante SEM janela paga vê cadeado —
    // nada de preview nem contagem de não-lidas.
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    Subscription::factory()->create(['user_id' => $member->id]); // assinante, sem linha

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'segredo');

    $this->actingAs($member->fresh())
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->where('conversations.data.0.locked', true)
            ->where('conversations.data.0.last_message_preview', null)
            ->where('conversations.data.0.unread_count', 0));
});

it('releases a pending subscription grant when the subscriber opens chat, once', function () {
    // M.13.8 × M.13.1: assinante passou a GASTAR no chat, então o gasto pode
    // destravar a franquia pendente — no mesmo caminho do débito, sob o lock.
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    Subscription::factory()->circle('black')->create(['user_id' => $member->id]); // teto 5000, custo chat 1

    // Saldo no teto + pendência cheia (grant acima do teto pendura o excedente).
    app(TokenService::class)->credit($member, 5000, 'adjustment'); // never-caps: semeia 5000
    app(TokenCreditPolicy::class)->credit($member, 1000, 'subscription_grant'); // room 0 → pendura 1000
    expect((int) TokenWallet::where('user_id', $member->id)->value('pending_grant_tokens'))->toBe(1000);

    // Abrir chat debita 1 (Black) → libera exatamente 1 da pendência (o espaço aberto).
    grantChatAccess($member->fresh(), $conversation);

    expect(app(TokenService::class)->balance($member))->toBe(5000)           // −1 gasto, +1 liberado
        ->and((int) TokenWallet::where('user_id', $member->id)->value('pending_grant_tokens'))->toBe(999);
});
