<?php

use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Services\ChatService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * feat/chat-economy-v2 — o MEMBRO inicia a conversa (fim do interest-gate
 * membro→performer) e a cobrança do acesso passou do desbloqueio prévio para o
 * ATO DO 1º ENVIO. A economia decimal do #197 (split 80/20 exato, R1–R4) fica
 * intacta; a retenção (30d + 15d de carência + purge) também.
 *
 * Reusa os helpers globais (tests/Pest.php): chatPerformer, chatMember,
 * chatUnlockedPair, grantChatAccess.
 */

// --- 1. Membro INICIA uma conversa nova, pagando no envio ----------------------

it('lets a member start a fresh conversation, charging the tier cost once on send', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5); // não-assinante → custo 2

    // Não existe conversa ainda: o membro inicia pela performer (slug).
    $this->actingAs($member)
        ->postJson(route('chat.start', $performer->slug), ['body' => 'oi, tudo bem?'])
        ->assertStatus(201)
        ->assertJsonPath('conversation_id', fn ($id) => is_int($id) && $id > 0);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(1);

    // Cobrou o custo do tier UMA vez e creditou a performer 80% exato.
    $access = ChatAccess::sole();
    expect($access->hasFullAccess())->toBeTrue();
    $spend = TokenLedger::find($access->spend_ledger_id);
    $credit = TokenLedger::find($access->credit_ledger_id);
    expect($spend->entry_type)->toBe('spend_chat_access')
        ->and($spend->amount)->toBe(-2)
        ->and($credit->entry_type)->toBe('chat_access_credit')
        ->and($credit->amount)->toBe('1.6000')            // 80% de 2 (decimal exato)
        ->and($credit->applied_rate)->toBe(80)
        ->and(app(TokenService::class)->balance($member))->toBe(3); // 5 - 2

    // Segunda mensagem DENTRO da janela não debita de novo.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'de novo'])
        ->assertStatus(201);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(1)
        ->and(app(TokenService::class)->balance($member))->toBe(3); // inalterado
});

// --- 2. Membro Black inicia: custo 1, performer credita 0,8000 -----------------

it('charges Black/FC only 1 token on the first send and credits the performer 0.8000', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 3);
    Subscription::factory()->circle('black')->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->postJson(route('chat.start', $performer->slug), ['body' => 'oi'])
        ->assertStatus(201);

    $access = ChatAccess::sole();
    expect(TokenLedger::find($access->spend_ledger_id)->amount)->toBe(-1)      // Black paga 1
        ->and(TokenLedger::find($access->credit_ledger_id)->amount)->toBe('0.8000') // 80% de 1
        ->and(app(TokenService::class)->balance($member))->toBe(2);
});

// --- 3. Inicia, janela expira, re-engaja: cobra de novo ------------------------

it('charges again when the member re-engages after the window has fully expired', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 10);

    // 1º envio abre e cobra a janela (custo 2).
    $this->actingAs($member)
        ->postJson(route('chat.start', $performer->slug), ['body' => 'oi'])
        ->assertStatus(201);
    $conversation = Conversation::sole();
    expect(app(TokenService::class)->balance($member))->toBe(8);

    // Passa vencimento (30d) + carência (15d) → purge soft-deleta as mensagens.
    $this->travel(46)->days();
    app(App\Services\ChatAccessService::class)->purgeExpired();

    // "Comportamento idêntico ao existente daí em diante": re-engajar é pela
    // renovação EXPLÍCITA (pagar para ler), que cobra de novo.
    $this->actingAs($member)
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(201);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(2)
        ->and(app(TokenService::class)->balance($member))->toBe(6); // 8 - 2
});

// --- 4. Performer INICIA: nada cobrado no envio; cobra na LEITURA --------------

it('charges nothing when the performer sends first, and debits+credits only when the member pays to read', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // A performer manda de graça (consome só a franquia do catálogo, se for o caso).
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'ei, vamos conversar?');

    // Nenhum movimento de acesso ao chat até aqui.
    expect(ChatAccess::count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'chat_access_credit')->count())->toBe(0);

    // O membro PAGA para ler (abre a janela) → aí sim debita e credita 80/20.
    $this->actingAs($member)
        ->postJson(route('chat.access.open', $conversation->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(201);

    $access = ChatAccess::sole();
    expect(TokenLedger::find($access->spend_ledger_id)->amount)->toBe(-2)
        ->and(TokenLedger::find($access->credit_ledger_id)->amount)->toBe('1.6000')
        ->and(app(TokenService::class)->balance($member))->toBe(3);
});

// --- 5. Filtro de conteúdo: sem token, sem conversa, texto preservado ----------

it('blocks a filtered first message without creating a conversation or charging a token', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);

    $this->actingAs($member)
        ->postJson(route('chat.start', $performer->slug), ['body' => 'faço programa completo'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'content_blocked');

    // Nada persistido, nada cobrado — o filtro roda ANTES de criar/transacionar.
    expect(Conversation::count())->toBe(0)
        ->and(ChatAccess::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(5); // saldo intacto
});

// --- 6. Bloqueio (conversa arquivada) impede envio mesmo pago, sem estorno -----

it('refuses to send into an archived conversation even with an active paid window, without refunding', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // Janela paga vigente (cobra 2 → saldo 3).
    grantChatAccess($member, $conversation);
    expect(app(TokenService::class)->balance($member))->toBe(3);

    // Conversa arquivada (bloqueio).
    $conversation->update(['status' => 'archived']);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'ainda dá?'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'conversation_archived');

    // Sem mensagem nova; token já cobrado NÃO volta (nenhum estorno).
    expect(Message::where('sender_id', $member->id)->count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(3); // inalterado
});

// --- 7. Saldo insuficiente ao iniciar: recusa, sem negativo, sem conversa ------

it('refuses to start when the member cannot afford the tier cost, without going negative', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 1); // custo do tier é 2

    $this->actingAs($member)
        ->postJson(route('chat.start', $performer->slug), ['body' => 'oi'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(ChatAccess::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(1); // sem débito, sem negativo
});

// --- 8. Concorrência (duplo-submit): duas mensagens cobram UMA vez -------------

it('charges once when two sends land back to back on a fresh conversation', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // Dois envios em sequência (duplo-submit): o 1º abre a janela e cobra; o 2º
    // vê a janela ativa e NÃO cobra. Charge-once por construção (lock da conversa
    // + hasFullAccess). Cada envio persiste uma mensagem.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'um'])
        ->assertStatus(201);
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'dois'])
        ->assertStatus(201);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(1)
        ->and(ChatAccess::count())->toBe(1)
        ->and(Message::where('sender_id', $member->id)->count())->toBe(2)
        ->and(app(TokenService::class)->balance($member))->toBe(3); // cobrado uma vez
});

// --- 9. chat.with redireciona para a conversa quando ela já existe -------------

it('redirects chat.with to the existing conversation, and renders compose mode when there is none', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);

    // Sem conversa → modo compor (renderiza Chat/Show com conversation.id null).
    $this->actingAs($member)
        ->get(route('chat.with', $performer->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat/Show')
            ->where('conversation.id', null)
            ->where('conversation.performer.slug', $performer->slug)
            ->where('access.state', 'none'));

    // Com conversa → redireciona para chat.show.
    $conversation = Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->id,
        'status' => 'active',
    ]);

    $this->actingAs($member)
        ->get(route('chat.with', $performer->slug))
        ->assertRedirect(route('chat.show', $conversation->id));
});

// --- 10. Franquia diária da performer (mensagem do catálogo) preservada --------

it('keeps enforcing the performer daily free-message franchise', function () {
    // Preserva o mecanismo do #173 (a cobrança nova do membro não o afeta). O
    // número vem da config (MEMBER_FREE_MESSAGES_PER_DAY); aqui fixamos 2 para o
    // teste ser determinístico independente do default.
    config()->set('member_engagement.free_messages_per_day', 2);

    $performer = chatPerformer();
    $member = chatMember();

    $service = app(ChatService::class);
    $service->sendCatalogMessage($performer, $member, 'primeira');
    $service->sendCatalogMessage($performer, $member, 'segunda');

    expect(fn () => $service->sendCatalogMessage($performer, $member, 'terceira'))
        ->toThrow(App\Exceptions\ChatException::class);

    expect($service->remainingDailyMessages($performer))->toBe(0);
});
