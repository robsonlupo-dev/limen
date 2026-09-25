<?php

use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Services\TokenService;

/**
 * "Responder ao story → chat" (feat/story-reply-to-chat). Estilo Insta: a resposta
 * do membro ao story vira a 1ª mensagem do chat e ABRE/paga a janela (mesma
 * economia do chat.start), carimbada com o story respondido.
 *
 * O eixo dos testes:
 *
 *  1. **Visibilidade é a porta**: quem não pode VER o story não pode respondê-lo —
 *     404 (não 403), indistinguível de story inexistente, e SEM cobrar. O feed já
 *     filtra, mas o endpoint rechega (a URL pode ser forjada).
 *  2. **A economia é a mesma do chat.start**: cobra uma vez no envio, atômico com
 *     a criação da conversa; saldo insuficiente não deixa mensagem-fantasma.
 *  3. **A miniatura só vai para a DONA**: a bolha da performer aponta a rota dela
 *     (sem poluir views); o membro vê só o rótulo "Respondeu ao story".
 */

// ─── Fixtures ──────────────────────────────────────────────────────────────────

/** Story vivo da performer, criado direto (sem passar pelo upload). */
function srStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return PerformerStory::forceCreate([
        'performer_profile_id' => $profile->id,
        'visibility_level' => $visibility,
        'media_path' => 'stories/'.$profile->id.'/sr-'.uniqid().'.jpg',
        'content_hash' => str_repeat('a', 64),
        'expires_at' => now()->addHours(24),
    ]);
}

function srFollow(int $memberId, int $profileId): void
{
    Follow::create(['user_id' => $memberId, 'performer_profile_id' => $profileId]);
}

// ─── 1. Caminho feliz: seguidor com saldo responde, abre e paga a janela ────────

it('lets a following member reply to a story, opening and charging the chat window', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5); // não-assinante → custo 2
    srFollow($member->id, $performer->id);
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'adorei esse story!'])
        ->assertStatus(201)
        ->assertJsonPath('conversation_id', fn ($id) => is_int($id) && $id > 0);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    $message = Message::where('conversation_id', $conversation->id)->sole();

    // A mensagem É a resposta: corpo do membro + ponteiro do story respondido.
    expect($message->sender_id)->toBe($member->id)
        ->and($message->body)->toBe('adorei esse story!')
        ->and($message->reply_to_story_id)->toBe($story->id);

    // Cobrou o tier UMA vez e abriu a janela — igual ao chat.start.
    expect(ChatAccess::sole()->hasFullAccess())->toBeTrue()
        ->and(app(TokenService::class)->balance($member))->toBe(3); // 5 - 2
});

// ─── 2. Não-seguidor: 404, sem conversa, sem cobrança ───────────────────────────

it('returns 404 and does not charge when a non-follower replies to a public story', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5); // não segue, não é Black → não vê story público
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'oi'])
        ->assertNotFound();

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(ChatAccess::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(5); // saldo intacto
});

// ─── 3. Story vencido: 404 mesmo para quem segue ────────────────────────────────

it('returns 404 when the story has expired', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);
    srFollow($member->id, $performer->id);
    $story = srStory($performer);
    $story->forceFill(['expires_at' => now()->subHour()])->save();

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'oi'])
        ->assertNotFound();

    expect(Conversation::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(5);
});

// ─── 4. Saldo insuficiente: 422, sem mensagem-fantasma ──────────────────────────

it('refuses the reply with insufficient balance and leaves no ghost conversation', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 1); // custo do tier é 2
    srFollow($member->id, $performer->id);
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'oi'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(1); // sem débito, sem negativo
});

// ─── 5. Corpo obrigatório ───────────────────────────────────────────────────────

it('validates the reply body as JSON', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);
    srFollow($member->id, $performer->id);
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');

    expect(Conversation::count())->toBe(0);
});

// ─── 6. A miniatura do story só vai para a DONA (sem poluir views) ───────────────

it('serves the story thumbnail only to the performer, never to the member', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);
    srFollow($member->id, $performer->id);
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'que story lindo'])
        ->assertStatus(201);

    $conversation = Conversation::sole();

    // Performer (dona): vê o rótulo E a miniatura (rota dela).
    $this->actingAs($performer->user)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('messages.data.0.reply_to_story.thumb_url', fn ($url) => is_string($url) && $url !== ''));

    // Membro (remetente): vê o rótulo, mas NUNCA a miniatura da dona.
    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('messages.data.0.reply_to_story.thumb_url', null));
});

// ─── 7. Nível do story: seguidor SEM assinatura não responde story de assinantes ─

it('returns 404 when a follower without a subscription replies to a subscribers-only story', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5); // segue, mas não tem Círculo ativo
    srFollow($member->id, $performer->id);
    $story = srStory($performer, 'subscribers'); // exige CAP_CIRCLE, não follow

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'oi'])
        ->assertNotFound();

    expect(Conversation::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(5); // sem cobrança
});

// ─── 8. O filtro de conteúdo vale nesta via: bloqueio audita, 422, sem cobrar ────

it('runs the content filter on the story reply and does not charge a blocked message', function () {
    $performer = chatPerformer();
    $member = chatMember(balance: 5);
    srFollow($member->id, $performer->id);
    $story = srStory($performer);

    $this->actingAs($member)
        ->postJson(route('stories.reply', $story->id), ['body' => 'faço programa completo'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'content_blocked');

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(ChatAccess::count())->toBe(0)
        ->and(app(TokenService::class)->balance($member))->toBe(5); // saldo intacto
});
