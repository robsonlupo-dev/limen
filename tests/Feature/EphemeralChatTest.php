<?php

use App\Events\NewMessage;
use App\Exceptions\ChatException;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatAudioStore;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Modo efêmero (vanish) no chat — TIMER "tocar para ver" (roadmap social, Onda 3;
 * evolução do §2.2, agora que o servidor cresceu — CX33).
 *
 * Eixo: (1) qualquer um dos dois liga/desliga o modo; (2) a mensagem efêmera chega
 * SELADA para o destinatário (sem corpo até o toque); o remetente vê o próprio corpo
 * até ser consumido; (3) REVELAR = CONSUMIR: grava revealed_at, devolve o corpo uma
 * vez, e some das duas pontas em qualquer load seguinte; (4) o corpo/áudio ficam no
 * banco para a moderação; (5) normal não é afetada. Prefixo `ec`.
 */

/** As linhas de mensagem das props do Inertia na tela do chat. */
function ecMessages(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props']['messages']['data'];
}

function ecRow(TestResponse $response, int $id): ?array
{
    return collect(ecMessages($response))->firstWhere('id', $id);
}

// ─── Toggle ────────────────────────────────────────────────────────────────────

it('qualquer um dos dois liga e desliga o modo efêmero', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    $this->actingAs($member)
        ->postJson(route('chat.ephemeral.toggle', $conversation->id), ['on' => true])
        ->assertOk()->assertJsonPath('ephemeral', true);
    expect($conversation->fresh()->ephemeral)->toBeTrue();

    $this->actingAs($performer->user)
        ->postJson(route('chat.ephemeral.toggle', $conversation->id), ['on' => false])
        ->assertOk()->assertJsonPath('ephemeral', false);
    expect($conversation->fresh()->ephemeral)->toBeFalse();
});

it('não-participante não liga o modo efêmero (404)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $estranho = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($estranho)
        ->postJson(route('chat.ephemeral.toggle', $conversation->id), ['on' => true])
        ->assertNotFound();

    expect($conversation->fresh()->ephemeral)->toBeFalse();
});

// ─── Carimbo no envio ──────────────────────────────────────────────────────────

it('carimba ephemeral na mensagem conforme o modo no envio', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $normal = app(ChatService::class)->sendMessage($conversation, $member, 'normal');
    expect($normal->isEphemeral())->toBeFalse();

    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $efemera = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'efemera');
    expect($efemera->isEphemeral())->toBeTrue();

    app(ChatService::class)->setEphemeral($conversation, $member, false);
    expect($efemera->fresh()->isEphemeral())->toBeTrue();
});

// ─── Selada para o destinatário; corpo só via reveal ─────────────────────────────

it('chega SELADA para o destinatário (sem corpo) e ABERTA para o remetente', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');

    // Destinatário (performer): selada, sem corpo — só revela ao tocar.
    $forPerformer = ecRow($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)), $msg->id);
    expect($forPerformer['sealed'])->toBeTrue()
        ->and($forPerformer['vanished'])->toBeFalse()
        ->and($forPerformer['body'])->toBeNull();

    // Remetente (membro): vê o próprio corpo até ser consumido — não é selada p/ ele.
    $forMember = ecRow($this->actingAs($member)->get(route('chat.show', $conversation->id)), $msg->id);
    expect($forMember['sealed'])->toBeFalse()
        ->and($forMember['vanished'])->toBeFalse()
        ->and($forMember['body'])->toBe('segredo');
});

// ─── Revelar = consumir ──────────────────────────────────────────────────────────

it('revelar devolve o corpo, grava revealed_at e some nas duas pontas depois', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');

    // Destinatário toca para revelar: recebe o corpo UMA vez.
    $this->actingAs($performer->user)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $msg->id]))
        ->assertOk()->assertJsonPath('body', 'segredo');

    expect($msg->fresh()->isRevealed())->toBeTrue();

    // A partir daí some para os DOIS lados (revelar = consumir).
    $forPerformer = ecRow($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)), $msg->id);
    expect($forPerformer['vanished'])->toBeTrue()->and($forPerformer['body'])->toBeNull();

    $forMember = ecRow($this->actingAs($member)->get(route('chat.show', $conversation->id)), $msg->id);
    expect($forMember['vanished'])->toBeTrue()->and($forMember['body'])->toBeNull();

    // O corpo permanece no banco — só a exibição some (moderação lê a prova).
    expect($msg->fresh()->body)->toBe('segredo');
});

it('o REMETENTE não revela a própria mensagem (404 not_revealable)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');

    $this->actingAs($member)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $msg->id]))
        ->assertNotFound()->assertJsonPath('reason', ChatException::EPHEMERAL_NOT_REVEALABLE);

    expect($msg->fresh()->isRevealed())->toBeFalse();
});

it('revelar de novo uma já consumida devolve 410 (expirada)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');

    $this->actingAs($performer->user)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $msg->id]))->assertOk();

    $this->actingAs($performer->user)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $msg->id]))
        ->assertStatus(410)->assertJsonPath('reason', ChatException::EPHEMERAL_GONE);
});

it('não-participante não revela (404)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');
    $estranho = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($estranho)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $msg->id]))
        ->assertNotFound();

    expect($msg->fresh()->isRevealed())->toBeFalse();
});

it('mensagem normal (modo desligado) não é selada nem some', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $msg = app(ChatService::class)->sendMessage($conversation, $member, 'permanente');

    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))->assertOk();
    $row = ecRow($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)), $msg->id);

    expect($row['sealed'])->toBeFalse()
        ->and($row['vanished'])->toBeFalse()
        ->and($row['body'])->toBe('permanente');
});

// ─── Áudio efêmero: janela do reveal ─────────────────────────────────────────────

it('serve o áudio efêmero só na janela certa (selado não; revelado sim; consumido não)', function () {
    Storage::fake(ChatAudioStore::DISK);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);

    $path = $conversation->id.'/'.Str::random(20).'.mp3';
    Storage::disk(ChatAudioStore::DISK)->put($path, 'MP3-BYTES');
    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $member->id,
        'body' => 'voz secreta',
        'ephemeral' => true,
        'audio_status' => Message::AUDIO_READY,
        'audio_path' => $path,
        'audio_duration_seconds' => 5,
        'audio_content_hash' => str_repeat('a', 64),
    ]);

    // Destinatário (performer) ANTES de revelar: selado → 404 (byte não sai).
    $this->actingAs($performer->user)
        ->get(route('chat.audio', [$conversation->id, $message->id]))->assertNotFound();

    // Remetente (membro) ANTES do consumo: ouve o próprio áudio.
    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))->assertOk();

    // Destinatário REVELA → dentro da janela, serve.
    $this->actingAs($performer->user)
        ->postJson(route('chat.ephemeral.reveal', [$conversation->id, $message->id]))->assertOk();
    $this->actingAs($performer->user)
        ->get(route('chat.audio', [$conversation->id, $message->id]))->assertOk();

    // Consumida → o REMETENTE não rebusca mais os bytes (404).
    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))->assertNotFound();

    // O arquivo PERMANECE no disco (moderação ouve a prova).
    Storage::disk(ChatAudioStore::DISK)->assertExists($path);
});

// ─── Broadcast em tempo real não vaza efêmera (revisão de segurança, ALTO) ───────

it('não vaza o corpo da efêmera no broadcast de nova mensagem', function () {
    Event::fake([NewMessage::class]);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo broadcast');

    // Nenhum NewMessage (nem à performer, nem ao membro) carrega o corpo — senão o
    // destinatário leria no toast/lista em tempo real sem tocar para revelar.
    Event::assertDispatched(NewMessage::class);
    Event::assertNotDispatched(
        NewMessage::class,
        fn (NewMessage $e) => str_contains((string) $e->preview, 'segredo'),
    );
});

// ─── Preview da lista não vaza efêmera ───────────────────────────────────────────

it('esconde o corpo da efêmera no preview da lista de conversas', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo de lista');

    $list = $this->actingAs($performer->user)->get(route('chat.index'))
        ->assertOk()->viewData('page')['props']['conversations']['data'];
    $row = collect($list)->firstWhere('id', $conversation->id);

    expect($row['last_message_preview'])->toBeNull();
});
