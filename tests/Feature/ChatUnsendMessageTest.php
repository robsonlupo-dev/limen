<?php

use App\Events\MessageRedacted;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * "Desfazer envio" no chat (feat/chat-unsend-message). O eixo: (1) o remetente
 * redige a PRÓPRIA mensagem numa janela curta; (2) redigir é REDAÇÃO de exibição,
 * NÃO delete — o corpo/áudio original FICA no banco e a moderação segue lendo a
 * prova; (3) não é mensagem de outro, nem fora do prazo, nem de outra conversa;
 * (4) o chat esconde o conteúdo das duas pontas. Reusa os helpers globais de chat.
 */

function unsendReadyAudio(Conversation $conversation, int $senderId): Message
{
    return Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $senderId,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_READY,
        'audio_path' => $conversation->id.'/x.mp3',
        'audio_duration_seconds' => 5,
        'audio_content_hash' => str_repeat('a', 64),
    ]);
}

// ─── 1. O remetente apaga a própria mensagem dentro da janela ──────────────────

it('lets the sender redact their own text message within the window, keeping the body in the db', function () {
    Event::fake([MessageRedacted::class]);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $message = app(ChatService::class)->sendMessage($conversation, $member, 'texto original');
    expect($message->isRedacted())->toBeFalse();

    $this->actingAs($member)
        ->deleteJson(route('chat.messages.destroy', [$conversation->id, $message->id]))
        ->assertOk()
        ->assertJsonPath('redacted', true);

    $message->refresh();
    expect($message->isRedacted())->toBeTrue()
        // O CONTEÚDO permanece no banco — só a exibição é redigida.
        ->and($message->body)->toBe('texto original');

    Event::assertDispatched(MessageRedacted::class, fn ($e) => $e->message->id === $message->id);
});

// ─── 2. Não dá pra apagar a mensagem de OUTRO ──────────────────────────────────

it('refuses to redact a message the requester did not send', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    // Mensagem da PERFORMER; o membro tenta apagar.
    $message = app(ChatService::class)->sendMessage($conversation, $performer->user, 'da performer');

    $this->actingAs($member)
        ->deleteJson(route('chat.messages.destroy', [$conversation->id, $message->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_your_message');

    expect($message->refresh()->isRedacted())->toBeFalse();
});

// ─── 3. Fora da janela → recusa ────────────────────────────────────────────────

it('refuses to redact after the window has closed', function () {
    config(['chat.redact_window_minutes' => 5]);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $message = app(ChatService::class)->sendMessage($conversation, $member, 'tarde demais');
    // Envelhece a mensagem além da janela.
    $message->forceFill(['created_at' => now()->subMinutes(6)])->save();

    $this->actingAs($member)
        ->deleteJson(route('chat.messages.destroy', [$conversation->id, $message->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'redact_window_closed');

    expect($message->refresh()->isRedacted())->toBeFalse();
});

// ─── 4. Id de outra conversa não vira janela pra apagar ────────────────────────

it('404s when the conversation is not the requester’s, and refuses cross-conversation ids', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $mine = app(ChatService::class)->sendMessage($conversation, $member, 'minha');

    // Outro par, mensagem alheia.
    $otherPerformer = chatPerformer();
    [$other, $otherConversation] = chatUnlockedPair($otherPerformer, balance: 5);
    grantChatAccess($other, $otherConversation);
    $foreign = app(ChatService::class)->sendMessage($otherConversation, $other, 'alheia');

    // Não-participante da conversa → 404 (policy view).
    $this->actingAs($other)
        ->deleteJson(route('chat.messages.destroy', [$conversation->id, $mine->id]))
        ->assertNotFound();

    // Minha conversa, mas id de mensagem de OUTRA conversa → 422 (não é sua).
    $this->actingAs($member)
        ->deleteJson(route('chat.messages.destroy', [$conversation->id, $foreign->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_your_message');

    expect($foreign->refresh()->isRedacted())->toBeFalse();
});

// ─── 5. Redigir de novo é no-op idempotente ────────────────────────────────────

it('is an idempotent no-op when the message is already redacted', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = app(ChatService::class)->sendMessage($conversation, $member, 'oi');

    $this->actingAs($member)->deleteJson(route('chat.messages.destroy', [$conversation->id, $message->id]))->assertOk();
    $firstAt = $message->refresh()->redacted_at;

    $this->travel(1)->minutes();
    $this->actingAs($member)->deleteJson(route('chat.messages.destroy', [$conversation->id, $message->id]))->assertOk();

    // Não recarimba (mantém o instante original) e segue redigida.
    expect($message->refresh()->redacted_at->equalTo($firstAt))->toBeTrue();
});

// ─── 6. O chat esconde o conteúdo redigido das duas pontas ─────────────────────

it('hides the redacted content from both sides in the chat screen', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = app(ChatService::class)->sendMessage($conversation, $member, 'segredo');
    app(ChatService::class)->redactMessage($conversation, $member, $message);

    // Do lado do membro (remetente).
    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('messages.data.0.redacted', true)->where('messages.data.0.body', null));

    // Do lado da performer (destinatária) — também não vê o corpo.
    $this->actingAs($performer->user)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('messages.data.0.redacted', true)->where('messages.data.0.body', null));
});

// ─── 7. Áudio redigido: exibição escondida, mas a prova segue servida ──────────

it('hides a redacted voice message in the chat but still serves the audio evidence to moderation', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = unsendReadyAudio($conversation, $member->id);

    app(ChatService::class)->redactMessage($conversation, $member, $message);

    // No chat: sem status/URL de áudio (vira "apagada").
    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('messages.data.0.redacted', true)
            ->where('messages.data.0.audio_status', null)
            ->where('messages.data.0.audio_url', null));

    // A denúncia aponta a mensagem; a prova de áudio segue disponível ao moderador.
    Report::open($performer->user, $message, 'coercion', 'denúncia');

    $moderator = App\Models\User::factory()->create(['role' => 'moderator', 'status' => 'active']);
    $this->actingAs($moderator)
        ->get(route('moderacao.reports.show', Report::sole()))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('evidence.kind', 'audio')
            ->where('evidence.available', true)               // redigir NÃO tira a prova
            ->whereNot('evidence.redacted_at', null));
});

// ─── 8. A moderação lê o corpo ORIGINAL de uma mensagem de texto redigida ──────

it('still serves the original text body to moderation after redaction', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = app(ChatService::class)->sendMessage($conversation, $member, 'prova retida');
    app(ChatService::class)->redactMessage($conversation, $member, $message);

    Report::open($performer->user, $message, 'coercion', 'denúncia');
    $moderator = App\Models\User::factory()->create(['role' => 'moderator', 'status' => 'active']);

    $this->actingAs($moderator)
        ->getJson(route('moderacao.evidence.message', $message->id))
        ->assertOk()
        ->assertJson(['body' => 'prova retida']); // conteúdo original preservado
});
