<?php

use App\Events\NewMessage;
use App\Services\ChatService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * fix/voice-access-and-chat-avatar: a foto do membro passa a acompanhar o FanAlias
 * no chat (lista, cabeçalho e broadcast), como já acontece no catálogo. O nome
 * exibido continua o FanAlias; a foto vem por avatar_token OPACO — nunca member_id,
 * nome ou e-mail. Reverte a decisão "membro sem avatar no chat" (era de quando o
 * membro era anônimo; hoje a foto dele já aparece no catálogo).
 */

/** Dá ao membro uma foto de perfil (sem tocar o disco — só as colunas que avatarUrl() lê). */
function giveMemberAvatar(App\Models\User $member): string
{
    $token = 'tok_'.Str::random(40);
    $member->forceFill([
        'avatar_path' => "member-media/{$member->id}/avatar.jpg",
        'avatar_token' => $token,
    ])->save();

    return $token;
}

it('mostra a FOTO do membro na lista da performer, servida por TOKEN (nunca id/PII)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    $token = giveMemberAvatar($member);
    $member->forceFill(['name' => 'Nome Real', 'email' => 'sentinela@example.test'])->save();

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi!');

    $response = $this->actingAs($performer->user)->get(route('chat.index'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('conversations.data.0.title', FanAlias::label($performer->id, $member->id))
        ->where('conversations.data.0.avatar_url', fn ($url) => is_string($url)
            && str_contains($url, '/membro/midia')
            && str_contains($url, $token)));

    // A URL de foto não reintroduz o que o FanAlias esconde.
    $response->assertDontSee('Nome Real', false);
    $response->assertDontSee('sentinela@example.test', false);
});

it('cai na silhueta (avatar_url null) quando o membro não tem foto', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi!');

    $this->actingAs($performer->user)->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('conversations.data.0.avatar_url', null));
});

it('o cabeçalho da conversa entrega o bloco member (alias + foto) só para a performer', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $token = giveMemberAvatar($member);

    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversation.viewer_is_performer', true)
            ->where('conversation.member.label', FanAlias::label($performer->id, $member->id))
            ->where('conversation.member.avatar_url', fn ($url) => is_string($url) && str_contains($url, $token)));
});

it('o membro NUNCA recebe o bloco member (é o dono do lado dele)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    giveMemberAvatar($member);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversation.viewer_is_performer', false)
            ->where('conversation.member', null));
});

it('o broadcast em tempo real leva a foto do membro à performer, sob FanAlias', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $token = giveMemberAvatar($member);
    $member->forceFill(['email' => 'sentinela2@example.test'])->save();

    Event::fake([NewMessage::class]);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'olá');

    Event::assertDispatched(NewMessage::class, function (NewMessage $e) use ($performer, $member, $token) {
        if ($e->recipientUserId !== $performer->user->id) {
            return false; // só o evento que atualiza a LISTA da performer
        }

        return str_starts_with($e->senderName, 'Fã #')
            && is_string($e->senderAvatarUrl)
            && str_contains($e->senderAvatarUrl, $token)
            && ! str_contains($e->senderAvatarUrl, 'sentinela2@example.test');
    });
});

it('o broadcast à performer manda avatar null quando o membro não tem foto', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    Event::fake([NewMessage::class]);
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'olá');

    Event::assertDispatched(NewMessage::class, fn (NewMessage $e) => $e->recipientUserId !== $performer->user->id
        || $e->senderAvatarUrl === null);
});

it('a tela de perfil da performer expõe o ESTADO da apresentação de voz', function () {
    $performer = chatPerformer();

    // Sem intro: prop null (a aba Fotos mostra "Sem apresentação").
    $this->actingAs($performer->user)->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('voiceIntro', null));

    // Com intro pendente: o status vem para a tela (sem bytes crus). $fillable é
    // vazio de propósito no model, então a linha nasce por forceFill.
    $intro = $performer->voiceIntro()->make();
    $intro->forceFill([
        'performer_profile_id' => $performer->id,
        'status' => App\Models\PerformerVoiceIntro::STATUS_PENDING,
        'path' => 'performer_voice_intros/x.mp3',
        'content_hash' => str_repeat('a', 64),
        'duration_seconds' => 12,
    ])->save();

    $this->actingAs($performer->user)->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('voiceIntro.status', 'pending'));
});
