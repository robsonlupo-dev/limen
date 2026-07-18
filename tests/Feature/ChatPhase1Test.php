<?php

use App\Events\MessageSent;
use App\Events\NewMessage;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\ChatService;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Chat pós-desbloqueio de Interesse (backend). Modelo interest-gated: a conversa
 * nasce no unlock; a performer manda a 1ª mensagem grátis. Para conversar, o
 * membro precisa de Círculo ativo (chat livre) OU de um ACESSO pago por
 * performer (50 tokens / janela). Ver docs/COMMUNICATION_ECONOMY.md §2,
 * docs/INTEREST_SYSTEM_SPEC.md §4-5 e docs/INTEREST_ANONYMITY_FLOOR.md.
 */
function chatPerformer(int $splitPct = 65): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => $splitPct,
    ]);
}

function chatMember(int $balance = 0): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($balance > 0) {
        app(TokenService::class)->credit($member, $balance, 'purchase');
    }

    return $member;
}

/** Membro que seguiu, recebeu interesse e desbloqueou — canal aberto de verdade. */
function chatUnlockedPair(PerformerProfile $performer, int $balance = 0): array
{
    $member = chatMember($balance + 15);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);

    $interest = app(InterestService::class)->send($performer, $member);
    app(InterestService::class)->unlock($member, $interest);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    return [$member, $conversation, $interest->fresh()];
}

function grantChatAccess(User $member, Conversation $conversation): ChatAccess
{
    return app(ChatAccessService::class)->openOrRenew($conversation, $member, (string) Str::uuid());
}

// --- O canal nasce no desbloqueio, não por endpoint do membro -----------------

it('opens a conversation when the member unlocks an interest', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);

    expect($conversation->member_id)->toBe($member->id)
        ->and($conversation->performer_profile_id)->toBe($performer->id)
        ->and($conversation->status)->toBe('active');
});

it('does not expose a member-initiated conversation route', function () {
    $names = collect(app('router')->getRoutes())->map->getName()->filter()->all();
    expect($names)->not->toContain('chat.conversations.store');
    expect(collect(app('router')->getRoutes())->contains(
        fn ($r) => $r->uri() === 'chat/conversations' && in_array('POST', $r->methods(), true)
    ))->toBeFalse();
});

// --- Performer manda a 1ª mensagem (grátis, sem depender do acesso do membro) --

it('lets the performer send the first message for free', function () {
    Event::fake([MessageSent::class]);
    $performer = chatPerformer();
    [, , $interest] = chatUnlockedPair($performer);

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interest->id), ['body' => 'Oi :)'])
        ->assertStatus(202)
        ->assertExactJson(['status' => 'sent']);

    $message = Message::sole();
    expect($message->sender_id)->toBe($performer->user_id)
        ->and($message->body)->toBe('Oi :)');
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->message->id === $message->id);
});

// --- Atualização da LISTA em tempo real (canal user.{id}, paywall no preview) --

it('broadcasts NewMessage to both participants with a paywalled preview', function () {
    Event::fake([MessageSent::class, NewMessage::class]);
    $performer = chatPerformer();
    [$member, $conversation, $interest] = chatUnlockedPair($performer);

    // A performer manda a 1ª mensagem. O membro (sem Círculo nem janela paga)
    // NÃO pode ler → preview null (cadeado). A performer lê sempre → preview.
    app(ChatService::class)->performerMessageFromInterest($performer, $interest, 'Olá, tudo bem?');

    // Destinatário performer: preview com corpo; não incrementa não-lidas (é ela quem manda).
    Event::assertDispatched(NewMessage::class, fn ($e) => $e->recipientUserId === $performer->user_id
        && $e->conversationId === $conversation->id
        && $e->preview === 'Olá, tudo bem?'
        && $e->incrementsUnread === false);

    // Destinatário membro: SEM corpo (paywall) e incrementa não-lidas.
    Event::assertDispatched(NewMessage::class, fn ($e) => $e->recipientUserId === $member->id
        && $e->conversationId === $conversation->id
        && $e->preview === null
        && $e->incrementsUnread === true);
});

it('includes the body preview for a member with paid access', function () {
    Event::fake([MessageSent::class, NewMessage::class]);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation); // janela paga vigente → leitura plena

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'mensagem visível');

    Event::assertDispatched(NewMessage::class, fn ($e) => $e->recipientUserId === $member->id
        && $e->preview === 'mensagem visível'
        && $e->incrementsUnread === true);
});

// --- Máscara de opt-out (indistinguível do status exibido) --------------------

it('masks the opt-out as unlocked (202) when a prior unlock exists', function () {
    Event::fake([MessageSent::class]);
    $performer = chatPerformer();
    [$member] = chatUnlockedPair($performer);
    app(InterestService::class)->setOptOut($member, true);
    $this->travel(31)->days();
    $suppressed = app(InterestService::class)->send($performer, $member);
    expect($suppressed->status)->toBe('suppressed');

    $before = Message::count();
    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $suppressed->id), ['body' => 'oi'])
        ->assertStatus(202)
        ->assertExactJson(['status' => 'sent']);

    expect(Message::count())->toBe($before);
    Event::assertNotDispatched(MessageSent::class);
});

it('masks the opt-out as a genuine sent (422) when there is no prior unlock', function () {
    $performer = chatPerformer();
    $member = chatMember();
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);
    app(InterestService::class)->setOptOut($member, true);
    $suppressed = app(InterestService::class)->send($performer, $member);

    expect($suppressed->status)->toBe('suppressed')
        ->and($suppressed->isDisplayedAsUnlocked())->toBeFalse();

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $suppressed->id), ['body' => 'oi'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'channel_not_open');
    expect(Message::count())->toBe(0);
});

// --- Acesso pago: membro sem assinatura ---------------------------------------

it('blocks a non-subscriber without access from sending', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'access_required');

    expect(Message::where('sender_id', $member->id)->count())->toBe(0);
});

it('charges 50 tokens for chat access and credits the performer split, unlocking send', function () {
    $performer = chatPerformer(splitPct: 60);
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);

    $this->actingAs($member)
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(201)
        ->assertJsonPath('access.state', 'active')
        ->assertJsonPath('access.can_send', true)
        ->assertJsonPath('new_balance', 0);

    $access = ChatAccess::sole();
    $spend = TokenLedger::find($access->spend_ledger_id);
    $credit = TokenLedger::find($access->credit_ledger_id);
    expect($spend->entry_type)->toBe('spend_chat_access')
        ->and($spend->amount)->toBe(-50)
        ->and($credit->entry_type)->toBe('chat_access_credit')
        ->and($credit->amount)->toBe(30); // floor(50 * 60/100)

    // Agora consegue enviar.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi!'])
        ->assertStatus(201);
    expect(Message::where('sender_id', $member->id)->count())->toBe(1);
});

it('does not double-charge on a replayed idempotency key', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    $key = (string) Str::uuid();

    $this->actingAs($member)->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => $key])->assertStatus(201);
    $this->actingAs($member)->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => $key])->assertStatus(201);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(1);
    expect(app(TokenService::class)->balance($member))->toBe(0);
});

it('renews access with a new key, charging again and extending the window', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 100);

    $first = grantChatAccess($member, $conversation);
    $firstExpiry = $first->expires_at->copy();

    $second = grantChatAccess($member, $conversation);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(2)
        ->and($second->expires_at->greaterThan($firstExpiry))->toBeTrue()
        ->and($second->renewed_at)->not->toBeNull()
        ->and(ChatAccess::count())->toBe(1); // uma linha por par
});

it('rejects opening access with insufficient balance without persisting it', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 10); // < 50

    $this->actingAs($member)
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(ChatAccess::count())->toBe(0);
    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(0);
});

// --- Assinante: chat livre, sem linha de acesso, sem débito -------------------

it('lets a member with an active Circle send for free without any access row', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    Subscription::factory()->create(['user_id' => $member->id]);

    $ledgerBefore = TokenLedger::count();

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'livre'])
        ->assertStatus(201);

    expect(ChatAccess::count())->toBe(0)
        ->and(TokenLedger::count())->toBe($ledgerBefore)
        ->and(Message::where('sender_id', $member->id)->count())->toBe(1);
});

it('refuses to sell access to an active subscriber', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    Subscription::factory()->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_applicable');
    expect(ChatAccess::count())->toBe(0);
});

// --- Grace period: leitura bloqueada, corpo retido, sem envio ------------------

it('enters grace after expiry: history visible but bodies withheld and sending blocked', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    // Performer manda algo legível durante o acesso.
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'segredo');

    // Passa o vencimento (30d), ainda dentro da carência (45d).
    $this->travel(31)->days();

    // Não consegue mais enviar.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'tarde'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'access_required');

    // Lê a tela: mensagens marcadas locked e SEM corpo (o gate é server-side).
    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('access.state', 'grace')
            ->where('access.locked', true)
            ->where('messages.data.0.locked', true)
            ->where('messages.data.0.body', null));
});

it('withholds messages entirely from a member who never bought access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'oi da performer');

    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('access.state', 'none')
            ->where('access.can_read', false)
            ->where('messages.data', [])
            // Sem leitura não vaza nem a CONTAGEM: total tem de ser 0, não o real.
            ->where('messages.total', 0));
});

// --- Job de expiração/retenção (soft-delete) ----------------------------------

it('soft-deletes messages after the grace period and marks the access deleted', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'a reter');
    expect(Message::count())->toBe(1);

    // Passa a carência inteira (45d+).
    $this->travel(46)->days();
    $this->artisan('chat:purge-expired-access')->assertSuccessful();

    // Mensagem soft-deletada: some das queries padrão, mas RETIDA no servidor.
    expect(Message::count())->toBe(0)
        ->and(Message::withTrashed()->count())->toBe(1)
        ->and(Message::withTrashed()->first()->trashed())->toBeTrue();
    expect(ChatAccess::sole()->status)->toBe('deleted');
});

// --- Autorização --------------------------------------------------------------

it('hides a conversation from a non-participant with 404, not 403', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);
    $this->actingAs(chatMember())
        ->get(route('chat.show', $conversation->id))
        ->assertNotFound();
});

it('rejects a non-participant sending into a conversation with 404', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);
    $this->actingAs(chatMember(100))
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'intruso'])
        ->assertNotFound();
    expect(Message::count())->toBe(0);
});

it('rejects a non-participant buying access with 404', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);
    $this->actingAs(chatMember(100))
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertNotFound();
    expect(ChatAccess::count())->toBe(0);
});

it('authorizes the private channel only for the two participants', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);
    $stranger = chatMember();

    $conversation->load('performerProfile');
    expect($conversation->hasParticipant($member))->toBeTrue()
        ->and($conversation->hasParticipant($performer->user))->toBeTrue()
        ->and($conversation->hasParticipant($stranger))->toBeFalse();
});

// --- Validação ----------------------------------------------------------------

it('validates the message body length and presence', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->post(route('chat.messages.store', $conversation->id), ['body' => ''])
        ->assertSessionHasErrors('body');

    $this->actingAs($member)
        ->post(route('chat.messages.store', $conversation->id), ['body' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('body');
});

it('requires a uuid idempotency key to open access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);

    $this->actingAs($member)
        ->post(route('chat.access.open', $conversation->id), ['idempotency_key' => 'not-a-uuid'])
        ->assertSessionHasErrors('idempotency_key');
    expect(ChatAccess::count())->toBe(0);
});

// --- Lista de conversas: preview gateado + badge de não lidas -----------------

it('shows unread count and a readable preview to a member with active access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'primeira');
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'ultima visivel');

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->where('conversations.data.0.unread_count', 2)
            ->where('conversations.data.0.last_message_preview', 'ultima visivel')
            ->where('conversations.data.0.locked', false));
});

it('withholds the list preview and unread count from a member without access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'segredo');

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->where('conversations.data.0.last_message_preview', null)
            ->where('conversations.data.0.locked', true)
            // Não vaza nem a contagem atrás do paywall.
            ->where('conversations.data.0.unread_count', 0));
});

it('always shows the preview to the performer regardless of member access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'oi membro');

    $this->actingAs($performer->user)
        ->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->where('conversations.data.0.last_message_preview', 'oi membro')
            ->where('conversations.data.0.locked', false));
});

// --- Read receipts (marca ao abrir com leitura plena) -------------------------

it('marks the counterpart messages as read when opened with full access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    expect(Message::whereNull('read_at')->count())->toBe(1);
    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();
    expect(Message::whereNull('read_at')->count())->toBe(0);
});

it('does not mark messages read while in grace (body withheld)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'oi');
    $this->travel(31)->days(); // grace: leitura bloqueada, corpo retido

    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();
    expect(Message::whereNull('read_at')->count())->toBe(1);
});

it('marks only the counterpart messages, never the reader own', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);
    app(App\Services\ChatService::class)->sendMessage($conversation, $performer->user, 'da performer');
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'do membro'])
        ->assertStatus(201);

    // Performer abre: marca a do membro; a própria (ainda não vista pelo membro) fica.
    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))->assertOk();

    expect(Message::where('sender_id', $member->id)->sole()->read_at)->not->toBeNull()
        ->and(Message::where('sender_id', $performer->user_id)->sole()->read_at)->toBeNull();
});

// --- Botão de chat no perfil público da performer (interest-gated) ------------

it('exposes chat state to a member who already has a conversation and access', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->get(route('performers.public.show', $performer->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Performers/Show')
            ->where('chat.conversation_id', $conversation->id)
            ->where('chat.can_access', true)
            ->where('chat.cost', 50));
});

it('flags chat as needing access when a conversation exists without an active window', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);

    $this->actingAs($member)
        ->get(route('performers.public.show', $performer->slug))
        ->assertInertia(fn ($page) => $page
            ->where('chat.conversation_id', $conversation->id)
            ->where('chat.can_access', false));
});

it('does not expose chat state without a conversation (no cold-start)', function () {
    $performer = chatPerformer();

    // Membro sem conversa: nada de botão (não dá para iniciar chat a frio).
    $this->actingAs(chatMember())
        ->get(route('performers.public.show', $performer->slug))
        ->assertInertia(fn ($page) => $page->where('chat', null));

    // Visitante deslogado: idem.
    $this->get(route('performers.public.show', $performer->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('chat', null));
});
