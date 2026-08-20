<?php

use App\Events\NewMessage;
use App\Models\ChatAccess;
use App\Services\ChatService;
use App\Support\MessageTeaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Teaser da mensagem bloqueada (feat/message-preview-teaser): o membro que ainda
 * não pagou vê as primeiras palavras da mensagem em claro + convite ao desbloqueio,
 * mas NUNCA o corpo completo. O corte é SERVER-SIDE — a regra crítica é que o corpo
 * inteiro não trafega para quem não pagou (borrar via CSS vazaria no DevTools).
 * A economia do chat (M.13.1) não muda; só o preview.
 */

// ─── App\Support\MessageTeaser (corte puro) ──────────────────────────────────

it('mostra as primeiras N palavras + reticências numa mensagem longa', function () {
    $body = 'Oi, adorei seu perfil, você é de São Paulo?';

    // Default = 3 palavras.
    expect(MessageTeaser::for($body))->toBe('Oi, adorei seu perfil,…');
});

it('respeita o teto de palavras do config', function () {
    config(['message_teaser.words' => 2]);
    expect(MessageTeaser::for('uma duas três quatro cinco seis'))->toBe('uma duas…');

    config(['message_teaser.words' => 4]);
    expect(MessageTeaser::for('uma duas três quatro cinco seis sete oito'))->toBe('uma duas três quatro…');
});

it('nunca revela a mensagem inteira — no máximo metade das palavras numa curta', function () {
    // 3 palavras → metade = 1.
    expect(MessageTeaser::for('cadê você agora'))->toBe('cadê…');
    // 2 palavras → metade = 1.
    expect(MessageTeaser::for('oi linda'))->toBe('oi…');
    // 4 palavras, default 3 → metade = 2 (corta antes do teto).
    expect(MessageTeaser::for('vamos sair hoje juntos'))->toBe('vamos sair…');
});

it('mensagem de uma palavra mostra só um pedaço dela, nunca a palavra inteira', function () {
    $teaser = MessageTeaser::for('segredo'); // 7 chars → metade = 3
    expect($teaser)->toBe('seg…')
        ->and($teaser)->not->toContain('segredo');
});

it('corpo vazio, só espaços ou null → sem teaser (null)', function () {
    expect(MessageTeaser::for(null))->toBeNull();
    expect(MessageTeaser::for(''))->toBeNull();
    expect(MessageTeaser::for('    '))->toBeNull();
});

it('o teaser nunca contém as palavras finais da mensagem', function () {
    $teaser = MessageTeaser::for('Oi, adorei seu perfil, você é de São Paulo?');
    // Corte novo (~40 chars / 8 palavras, piso de metade): revela "Oi, adorei seu
    // perfil,…" e SEGURA a cauda. As palavras finais nunca aparecem.
    expect($teaser)->not->toContain('você')
        ->and($teaser)->not->toContain('São Paulo')
        ->and($teaser)->not->toContain('de São');
});

// ─── fix/chat-ux-mobile: o corte aumentou (era 2 chars — "O..."/"co...") ──────

it('revela bem mais que antes numa mensagem longa (até 8 palavras), sem o corpo inteiro', function () {
    // 15 palavras: piso metade = 7, teto de palavras = 8 → mostra 7 palavras
    // (bem mais que as 3 de antes), sempre com reticências e segurando a cauda.
    $body = 'Oi tudo bem eu queria muito te conhecer melhor você parece incrível me conta mais';
    $teaser = MessageTeaser::for($body);

    expect($teaser)->toBe('Oi tudo bem eu queria muito te…')
        ->and($teaser)->toEndWith('…')
        ->and($teaser)->not->toContain('conhecer')
        ->and($teaser)->not->toContain('conta mais');
});

it('o teto de caracteres vem primeiro quando as palavras enchem os ~40 chars', function () {
    // 20 palavras de 5 letras: piso metade = 10, teto de palavras = 8, mas o teto de
    // 40 caracteres corta ANTES (em 6 palavras ~35 chars). "o que vier primeiro".
    $body = 'aaaaa bbbbb ccccc ddddd eeeee fffff ggggg hhhhh iiiii jjjjj kkkkk lllll mmmmm nnnnn ooooo ppppp qqqqq rrrrr sssss ttttt';
    $teaser = MessageTeaser::for($body);

    // Sem as reticências, o trecho cabe em 40 chars.
    expect(mb_strlen(rtrim($teaser, '…')))->toBeLessThanOrEqual(40)
        ->and($teaser)->toBe('aaaaa bbbbb ccccc ddddd eeeee fffff…');
});

it('config.chars é um teto (afrouxar palavras não fura os caracteres)', function () {
    config(['message_teaser.words' => 8, 'message_teaser.chars' => 12]);
    // Piso metade permite 4 palavras, mas 12 chars cortam em ~2.
    expect(MessageTeaser::for('alpha bravo charlie delta echo foxtrot golf hotel'))
        ->toBe('alpha bravo…');
});

// ─── Lista de mensagens (ChatController::index) ──────────────────────────────

it('a lista entrega SÓ o teaser (não o corpo) ao membro sem acesso', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'Oi, adorei seu perfil, você é de São Paulo?');

    $response = $this->actingAs($member)->get(route('chat.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('conversations.data.0.last_message_preview', 'Oi, adorei seu perfil,…')
        ->where('conversations.data.0.locked', true)
        ->where('conversations.data.0.unread_count', 0));

    // O corpo completo NUNCA trafega no payload — nem escapado no HTML da página.
    // Fragmentos EXCLUSIVOS do corpo (além do teaser "Oi, adorei seu perfil,…"), que não
    // colidem com rotas/Ziggy: se o corpo vazasse, apareceriam aqui.
    $response->assertDontSee('é de São Paulo', false);
    $response->assertDontSee('você é de São Paulo', false);
});

it('depois de pagar, a lista mostra o corpo completo', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'Oi, adorei seu perfil, você é de São Paulo?');

    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->where('conversations.data.0.last_message_preview', 'Oi, adorei seu perfil, você é de São Paulo?')
            ->where('conversations.data.0.locked', false));
});

// ─── Conversa aberta (ChatController::show) ──────────────────────────────────

it('a tela da conversa mostra o teaser no paywall e NÃO o corpo, sem acesso', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'Oi, adorei seu perfil, você é de São Paulo?');

    $response = $this->actingAs($member)->get(route('chat.show', $conversation->id));

    $response->assertInertia(fn ($page) => $page
        ->component('Chat/Show')
        ->where('teaser', 'Oi, adorei seu perfil,…')
        // Sem acesso: paginador vazio de fato (nem a CONTAGEM atrás do paywall).
        ->where('messages.total', 0)
        ->where('access.can_read', false));

    $response->assertDontSee('é de São Paulo', false);
    $response->assertDontSee('você é de São Paulo', false);
});

it('depois de pagar, a conversa entrega o corpo e o teaser some', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'Oi, adorei seu perfil, você é de São Paulo?');

    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertInertia(fn ($page) => $page
            ->where('teaser', null)
            ->where('access.can_read', true)
            ->where('messages.data.0.body', 'Oi, adorei seu perfil, você é de São Paulo?'));
});

// ─── Broadcast (NewMessage → lista em tempo real + toast) ────────────────────

it('na CARÊNCIA (grace) o broadcast manda só o teaser, não o preview completo', function () {
    // Grace: can_read=true mas locked=true e o corpo é retido em todo lugar. O
    // broadcast tem que alinhar ao index/show (teaser + locked), não vazar os 60
    // chars do preview. Regressão do achado da revisão de segurança.
    Event::fake([NewMessage::class]);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 0);

    // Acesso EXPIRADO mas ainda na janela de carência.
    ChatAccess::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->id,
        'unlocked_at' => now()->subDays(40),
        'expires_at' => now()->subDay(),
        'grace_ends_at' => now()->addDays(10),
        'status' => 'active',
    ]);

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'Oi, adorei seu perfil, você é de São Paulo?');

    Event::assertDispatched(NewMessage::class, fn ($e) => $e->recipientUserId === $member->id
        && $e->preview === 'Oi, adorei seu perfil,…'
        && $e->locked === true
        && ! str_contains((string) $e->preview, 'São Paulo'));
});
