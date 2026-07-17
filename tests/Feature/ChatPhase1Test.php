<?php

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\Message;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\ChatService;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Chat pós-desbloqueio de Interesse (Fase 1 — backend). Modelo interest-gated:
 * a conversa nasce no unlock; a performer manda a 1ª mensagem grátis; o membro
 * paga por mensagem salvo se tiver Círculo ativo. Ver docs/COMMUNICATION_ECONOMY.md
 * §2, docs/INTEREST_SYSTEM_SPEC.md §4-5 e docs/INTEREST_ANONYMITY_FLOOR.md.
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

// --- O canal nasce no desbloqueio, não por endpoint do membro -----------------

it('opens a conversation when the member unlocks an interest', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);

    expect($conversation)->not->toBeNull()
        ->and($conversation->member_id)->toBe($member->id)
        ->and($conversation->performer_profile_id)->toBe($performer->id)
        ->and($conversation->status)->toBe('active');
});

it('does not expose a member-initiated conversation route', function () {
    // O modelo interest-gated proíbe o membro abrir conversa. A rota da spec
    // literal (POST /chat/conversations) não deve existir.
    $names = collect(app('router')->getRoutes())->map->getName()->filter()->all();

    expect($names)->not->toContain('chat.conversations.store');
    expect(collect(app('router')->getRoutes())->contains(
        fn ($r) => $r->uri() === 'chat/conversations' && in_array('POST', $r->methods(), true)
    ))->toBeFalse();
});

it('reuses the same conversation when the pair unlocks twice', function () {
    $performer = chatPerformer();
    [$member] = chatUnlockedPair($performer);

    // Segundo interesse do mesmo par, fora do cooldown, desbloqueado de novo.
    $second = PerformerInterest::create([
        'performer_profile_id' => $performer->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    app(InterestService::class)->unlock($member, $second);

    expect(Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)->count())->toBe(1);
});

// --- Performer manda a 1ª mensagem (grátis) -----------------------------------

it('lets the performer send the first message for free', function () {
    Event::fake([MessageSent::class]);
    $performer = chatPerformer();
    [$member, , $interest] = chatUnlockedPair($performer);

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interest->id), ['body' => 'Oi :)'])
        ->assertStatus(202)
        ->assertExactJson(['status' => 'sent']);

    $message = Message::sole();
    expect($message->sender_id)->toBe($performer->user_id)
        ->and($message->body)->toBe('Oi :)')
        ->and($message->spend_ledger_id)->toBeNull()
        ->and($message->credit_ledger_id)->toBeNull();

    Event::assertDispatched(MessageSent::class, fn ($e) => $e->message->id === $message->id);
});

// --- Máscara de opt-out: parece sucesso, não entrega nada ---------------------

it('masks the opt-out: sending to a suppressed interest looks successful but persists nothing', function () {
    Event::fake([MessageSent::class]);
    $performer = chatPerformer();

    // Par com desbloqueio ANTERIOR (canal já existe), depois o membro opta por
    // sair; um novo interesse nasce 'suppressed' mas a aba da performer o mostra
    // como 'unlocked'. Enviar por essa linha não pode entregar nada.
    [$member] = chatUnlockedPair($performer);
    app(InterestService::class)->setOptOut($member, true);
    // Além do cooldown de 30 dias do interesse anterior, para o novo envio valer.
    $this->travel(31)->days();
    $suppressed = app(InterestService::class)->send($performer, $member);
    expect($suppressed->status)->toBe('suppressed');

    $messagesBefore = Message::count();

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $suppressed->id), ['body' => 'Oi, sumida'])
        ->assertStatus(202)
        ->assertExactJson(['status' => 'sent']); // idêntico ao sucesso real

    expect(Message::count())->toBe($messagesBefore); // nada persistido
    Event::assertNotDispatched(MessageSent::class);  // nada transmitido
});

it('masks the opt-out as a genuine sent when there is no prior unlock', function () {
    // Suprimido SEM desbloqueio prévio → a performer o vê como 'sent'. Enviar
    // por ele precisa dar o MESMO 422 de um 'sent' genuíno (teste abaixo), senão
    // 202-vs-422 vaza o opt-out. Este é o furo que a revisão de segurança pegou.
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

it('rejects a performer first message when the interest is not yet unlocked', function () {
    $performer = chatPerformer();
    $member = chatMember();
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);
    $interest = app(InterestService::class)->send($performer, $member); // status 'sent'

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interest->id), ['body' => 'oi'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'channel_not_open');

    expect(Message::count())->toBe(0);
});

// --- Cobrança por mensagem do membro ------------------------------------------

it('charges a non-subscriber member per message and credits the performer split', function () {
    $performer = chatPerformer(splitPct: 50);
    [$member, $conversation] = chatUnlockedPair($performer, balance: 10);

    $balanceBefore = app(TokenService::class)->balance($member);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi!'])
        ->assertStatus(201)
        ->assertJsonPath('new_balance', $balanceBefore - 2);

    $message = Message::where('sender_id', $member->id)->sole();
    expect($message->spend_ledger_id)->not->toBeNull()
        ->and($message->credit_ledger_id)->not->toBeNull();

    $spend = TokenLedger::find($message->spend_ledger_id);
    $credit = TokenLedger::find($message->credit_ledger_id);
    expect($spend->entry_type)->toBe('spend_message')
        ->and($spend->amount)->toBe(-2)
        ->and($credit->entry_type)->toBe('message_credit')
        ->and($credit->amount)->toBe(1); // floor(2 * 50/100)
});

it('lets a member with an active Circle chat for free', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    Subscription::factory()->create(['user_id' => $member->id]); // Círculo ativo

    $ledgerBefore = TokenLedger::count();

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi livre'])
        ->assertStatus(201);

    $message = Message::where('sender_id', $member->id)->sole();
    expect($message->spend_ledger_id)->toBeNull()
        ->and($message->credit_ledger_id)->toBeNull()
        ->and(TokenLedger::count())->toBe($ledgerBefore); // nenhum lançamento novo
});

it('rejects a member message on insufficient balance without persisting it', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0); // saldo 0 após unlock

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'sem saldo'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(Message::where('sender_id', $member->id)->count())->toBe(0);
});

// --- Autorização --------------------------------------------------------------

it('hides a conversation from a non-participant with 404, not 403', function () {
    // 404 (não 403) para não vazar a existência da conversa por enumeração de id.
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);
    $stranger = chatMember();

    $this->actingAs($stranger)
        ->get(route('chat.show', $conversation->id))
        ->assertNotFound();
});

it('rejects a non-participant sending into a conversation with 404', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);
    $stranger = chatMember(100);

    $this->actingAs($stranger)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'intruso'])
        ->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('authorizes the private channel only for the two participants', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);
    $stranger = chatMember();

    // hasParticipant é exatamente a lógica que routes/channels.php aplica no
    // canal privado conversation.{id}.
    expect($conversation->fresh()->load('performerProfile')->hasParticipant($member))->toBeTrue()
        ->and($conversation->hasParticipant($performer->user))->toBeTrue()
        ->and($conversation->hasParticipant($stranger))->toBeFalse();
});

// --- Validação ----------------------------------------------------------------

it('validates the message body length and presence', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 10);

    // Erro de FormRequest em rota web volta como redirect + erros de sessão (o
    // handler só renderiza JSON em api/*); o Inertia consome os erros de sessão.
    $this->actingAs($member)
        ->post(route('chat.messages.store', $conversation->id), ['body' => ''])
        ->assertSessionHasErrors('body');

    $this->actingAs($member)
        ->post(route('chat.messages.store', $conversation->id), ['body' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('body');

    expect(Message::where('sender_id', $member->id)->count())->toBe(0);
});

// --- Listagem -----------------------------------------------------------------

it('lists only the acting user conversations', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);

    // Outra conversa, de outro par, não deve aparecer para este membro.
    $otherPerformer = chatPerformer();
    chatUnlockedPair($otherPerformer);

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertOk();

    // O membro vê exatamente a sua conversa.
    expect(Conversation::where('member_id', $member->id)->count())->toBe(1);
});
