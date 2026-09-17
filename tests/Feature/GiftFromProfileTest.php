<?php

use App\Models\Conversation;
use App\Models\Gift;
use App\Models\GiftSend;
use App\Models\Message;
use App\Models\TokenLedger;
use App\Services\GiftService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Presente pelo PERFIL, fora da live (feat/gift-from-profile). Reusa a MESMA
 * economia do presente da live (GiftService, split 80/20, entry_types) — o que
 * muda é a ENTREGA: o presente vira uma mensagem no chat 1:1 do par, sem cobrar
 * acesso de chat. Usa os helpers globais chatPerformer()/chatMember() do Pest.php.
 */
function gpfGift(int $price = 4, string $slug = 'rosa'): Gift
{
    return Gift::create(['name' => ucfirst($slug), 'slug' => $slug, 'price_tokens' => $price, 'active' => true]);
}

function gpfBalance($user): int
{
    return app(TokenService::class)->balance($user);
}

// ─── Entrega no chat ─────────────────────────────────────────────────────────

it('presente pelo perfil credita 80% e cria a mensagem de presente no chat', function () {
    $performer = chatPerformer();
    $member = chatMember(50);

    $gift = gpfGift(4); // 80% de 4 = 3,2000

    app(GiftService::class)->send($member, $performer, $gift, (string) Str::uuid(), deliverToChat: true);

    // Economia: split 80/20 e applied_rate congelado (idêntico ao presente da live).
    $send = GiftSend::sole();
    expect($send->applied_rate)->toBe(80)
        ->and((string) $send->performer_amount)->toBe('3.2000')
        ->and(gpfBalance($member))->toBe(46);   // 50 - 4 (só o presente; sem cobrança de chat)

    // Entrega: uma conversa do par e UMA mensagem de presente, do membro, com gift_id.
    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();
    $message = Message::where('conversation_id', $conversation->id)->sole();
    expect($message->sender_id)->toBe($member->id)
        ->and($message->gift_id)->toBe($gift->id);

    // Não cobrou acesso de chat: nenhum spend_chat_access no ledger.
    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(0);
});

it('a performer sempre vê o presente recebido no chat (ela lê de graça)', function () {
    $performer = chatPerformer();
    $member = chatMember(50);
    $gift = gpfGift(4);

    app(GiftService::class)->send($member, $performer, $gift, (string) Str::uuid(), deliverToChat: true);
    $conversation = Conversation::where('member_id', $member->id)->sole();

    // A performer lê sempre — vê o presente com o slug do ícone e o nome do item.
    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))
        ->assertInertia(fn ($page) => $page
            ->where('messages.data.0.gift_slug', 'rosa')
            ->where('messages.data.0.gift_name', 'Rosa')
            ->where('messages.data.0.sender_id', $member->id)
        );
});

it('o membro vê o próprio presente no chat quando tem acesso de leitura', function () {
    // Sem acesso, o chat inteiro fica atrás do paywall (comportamento existente):
    // o membro recebe o toast de confirmação, mas a conversa só abre com acesso
    // pago. Com acesso, o presente que ele enviou aparece com o ícone.
    $performer = chatPerformer();
    $member = chatMember(50);
    $gift = gpfGift(4);

    app(GiftService::class)->send($member, $performer, $gift, (string) Str::uuid(), deliverToChat: true);
    $conversation = Conversation::where('member_id', $member->id)->sole();

    grantChatAccess($member, $conversation);

    $this->actingAs($member)->get(route('chat.show', $conversation->id))
        ->assertInertia(fn ($page) => $page
            ->where('messages.data.0.gift_slug', 'rosa')
            ->where('messages.data.0.sender_id', $member->id)
        );
});

// ─── Reuso do endpoint web + flag ────────────────────────────────────────────

it('a rota web com deliver_to_chat entrega no chat e devolve a conversa', function () {
    $performer = chatPerformer();
    $member = chatMember(50);
    $gift = gpfGift(4);

    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->slug,
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
        'deliver_to_chat' => true,
    ])->assertCreated()->assertJsonFragment(['tokens' => 4]);

    $conversation = Conversation::where('member_id', $member->id)->sole();
    expect(Message::where('conversation_id', $conversation->id)->whereNotNull('gift_id')->count())->toBe(1);
});

it('presente da live (sem deliver_to_chat) NÃO cria mensagem de chat', function () {
    $performer = chatPerformer();
    $member = chatMember(50);
    $gift = gpfGift(4);

    // Sem a flag: comportamento do presente da live — só overlay, nada no chat.
    app(GiftService::class)->send($member, $performer, $gift, (string) Str::uuid());

    expect(GiftSend::count())->toBe(1)
        ->and(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

// ─── Saldo insuficiente e idempotência ───────────────────────────────────────

it('saldo insuficiente devolve 422 sem cobrar nem criar mensagem', function () {
    $performer = chatPerformer();
    $member = chatMember(2); // não cobre a Rosa de 4
    $gift = gpfGift(4);

    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->slug,
        'gift_slug' => $gift->slug,
        'idempotency_key' => (string) Str::uuid(),
        'deliver_to_chat' => true,
    ])->assertUnprocessable()->assertJsonFragment(['reason' => 'insufficient_balance']);

    expect(GiftSend::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(gpfBalance($member))->toBe(2);
});

it('reenvio idempotente não duplica a mensagem de presente no chat', function () {
    $performer = chatPerformer();
    $member = chatMember(50);
    $gift = gpfGift(4);
    $key = (string) Str::uuid();

    app(GiftService::class)->send($member, $performer, $gift, $key, deliverToChat: true);
    app(GiftService::class)->send($member, $performer, $gift, $key, deliverToChat: true);

    expect(GiftSend::count())->toBe(1)
        ->and(Message::whereNotNull('gift_id')->count())->toBe(1)
        ->and(gpfBalance($member))->toBe(46); // debitado uma vez só
});
