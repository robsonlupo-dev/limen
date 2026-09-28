<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatAudioStore;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Modo efêmero (vanish) no chat — v1 "ver-uma-vez" (roadmap social, Onda 2).
 *
 * Eixo: (1) qualquer um dos dois liga/desliga o modo na conversa; (2) a mensagem
 * enviada com o modo ligado some da EXIBIÇÃO depois de vista (nas duas pontas),
 * mas o corpo FICA no banco para a moderação; (3) mensagem normal não é afetada.
 * Reusa os helpers globais de chat. Prefixo `ec` (ephemeral chat).
 *
 * ⚠️ v1 é "ver-uma-vez" por decisão de servidor; o PO QUER "timer após vista"
 * quando o servidor crescer (ver docs/ROADMAP_SOCIAL.md §2.2).
 */

/** As linhas de mensagem das props do Inertia na tela do chat. */
function ecMessages(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props']['messages']['data'];
}

// ─── Toggle ────────────────────────────────────────────────────────────────────

it('qualquer um dos dois liga e desliga o modo efêmero', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // Membro liga.
    $this->actingAs($member)
        ->postJson(route('chat.ephemeral.toggle', $conversation->id), ['on' => true])
        ->assertOk()->assertJsonPath('ephemeral', true);
    expect($conversation->fresh()->ephemeral)->toBeTrue();

    // Performer desliga.
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

    // Modo desligado: mensagem normal.
    $normal = app(ChatService::class)->sendMessage($conversation, $member, 'normal');
    expect($normal->isEphemeral())->toBeFalse();

    // Liga e manda: efêmera. Desligar depois não muda a que já foi.
    app(ChatService::class)->setEphemeral($conversation, $member, true);
    $efemera = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'efemera');
    expect($efemera->isEphemeral())->toBeTrue();

    app(ChatService::class)->setEphemeral($conversation, $member, false);
    expect($efemera->fresh()->isEphemeral())->toBeTrue();
});

// ─── Ver-uma-vez ───────────────────────────────────────────────────────────────

it('some depois de vista: mostra uma vez e some na reabertura, nas duas pontas', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);

    $msg = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'segredo');

    // Performer abre: vê o corpo UMA vez (acabou de ler agora).
    $first = ecMessages($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)));
    $row = collect($first)->firstWhere('id', $msg->id);
    expect($row['vanished'])->toBeFalse()->and($row['body'])->toBe('segredo');

    // Reabre: já vista → some, sem corpo.
    $second = ecMessages($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)));
    $row2 = collect($second)->firstWhere('id', $msg->id);
    expect($row2['vanished'])->toBeTrue()->and($row2['body'])->toBeNull();

    // O corpo permanece no banco — só a exibição some (moderação lê a prova).
    expect($msg->fresh()->body)->toBe('segredo');

    // Para o REMETENTE (membro), depois que a performer leu, também some.
    $mine = ecMessages($this->actingAs($member)->get(route('chat.show', $conversation->id)));
    $rowMine = collect($mine)->firstWhere('id', $msg->id);
    expect($rowMine['vanished'])->toBeTrue()->and($rowMine['body'])->toBeNull();
});

it('mensagem normal (modo desligado) não some depois de vista', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $msg = app(ChatService::class)->sendMessage($conversation, $member, 'permanente');

    // Performer abre duas vezes: continua visível.
    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))->assertOk();
    $again = ecMessages($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)));
    $row = collect($again)->firstWhere('id', $msg->id);

    expect($row['vanished'])->toBeFalse()->and($row['body'])->toBe('permanente');
});

// ─── Não vaza pela URL direta do áudio (revisão de segurança, CRÍTICO) ───────────

it('para de servir o áudio efêmero pela URL direta depois de visto', function () {
    Storage::fake(ChatAudioStore::DISK);
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);

    // Áudio efêmero pronto no disco fake, enviado pelo membro.
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

    // Antes de ver (read_at null): a performer (destinatária) ouve pela URL direta.
    $this->actingAs($performer->user)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertOk();

    // Ela abre o chat: vê a bolha UMA vez e a mensagem é marcada como lida.
    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))->assertOk();

    // Agora a efêmera já foi vista → a URL direta NÃO serve mais os bytes (senão o
    // destinatário rebuscaria o áudio depois dele sumir da tela). 404, como a redigida.
    $this->actingAs($performer->user)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertNotFound();

    // Para o REMETENTE (membro), depois que a performer leu, também 404.
    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertNotFound();

    // O arquivo PERMANECE no disco — só a exibição/serving some (moderação ouve a prova).
    Storage::disk(ChatAudioStore::DISK)->assertExists($path);
});

// ─── Só a página aberta some (revisão de segurança, MÉDIO) ───────────────────────

it('não marca como vista uma efêmera de página seguinte, só a página aberta', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    app(ChatService::class)->setEphemeral($conversation, $member, true);

    // 21 efêmeras do membro: a página 1 mostra as 20 mais novas; a mais antiga
    // (menor id) cai na página 2.
    $ids = [];
    foreach (range(1, 21) as $i) {
        $ids[] = Message::forceCreate([
            'conversation_id' => $conversation->id,
            'sender_id' => $member->id,
            'body' => "efemera-{$i}",
            'ephemeral' => true,
        ])->id;
    }
    $oldest = $ids[0];

    // Performer abre a página 1 (não renderiza a mais antiga).
    $page1 = ecMessages($this->actingAs($performer->user)->get(route('chat.show', $conversation->id)));
    expect(collect($page1)->firstWhere('id', $oldest))->toBeNull();

    // A mais antiga NÃO foi marcada como lida ao abrir a página 1 — senão sumiria
    // sem nunca ter sido vista.
    expect(Message::find($oldest)->read_at)->toBeNull();

    // Ao abrir a página 2, ela aparece UMA vez (primeira leitura), com corpo.
    $page2 = ecMessages(
        $this->actingAs($performer->user)->get(route('chat.show', $conversation->id).'?page=2')
    );
    $row = collect($page2)->firstWhere('id', $oldest);
    expect($row['vanished'])->toBeFalse()->and($row['body'])->toBe('efemera-1');
});
