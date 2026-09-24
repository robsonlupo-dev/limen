<?php

use App\Jobs\ProcessChatAudio;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\ChatAudioStore;
use App\Services\ChatService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Mensagem de voz no chat 1:1 (feat/chat-voice-message). Reusa os helpers globais
 * de chat (tests/Pest.php): chatPerformer, chatMember, chatUnlockedPair,
 * grantChatAccess. O eixo é: (1) o áudio segue o MESMO gate de acesso/cobrança do
 * texto — membro paga a janela no 1º envio, performer manda de graça; (2) nasce
 * `processing` e despacha o job de sanitização (o ffmpeg roda fora do request);
 * (3) o serving dos bytes é por participação + paywall + `ready`; (4) o áudio é
 * denunciável e o moderador OUVE a prova pelo endpoint dedicado.
 *
 * Prefixo `cvm` nos helpers locais — funções Pest são GLOBAIS; colidir derruba a
 * suíte inteira.
 */
beforeEach(function () {
    Storage::fake(ChatAudioStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function cvmUpload(): UploadedFile
{
    return new UploadedFile(base_path('tests/fixtures/sample-voice.mp3'), 'voz.mp3', 'audio/mpeg', null, true);
}

/** Uma mensagem de voz PRONTA no disco fake, do remetente pedido. */
function cvmReadyAudio(Conversation $conversation, User $sender): Message
{
    $path = $conversation->id.'/'.Str::random(20).'.mp3';
    Storage::disk(ChatAudioStore::DISK)->put($path, 'MP3-BYTES');

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_READY,
        'audio_path' => $path,
        'audio_duration_seconds' => 7,
        'audio_content_hash' => hash('sha256', 'MP3-BYTES'),
    ]);

    return $message;
}

function cvmModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

// ─── 1. Envio: membro paga a janela no 1º envio de voz ─────────────────────────

it('lets a member send a voice message, opening and charging the window on the first send', function () {
    Queue::fake();
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    $this->actingAs($member)
        ->post(route('chat.messages.audio', $conversation->id), ['audio' => cvmUpload()])
        ->assertStatus(201)
        ->assertJsonPath('message_id', fn ($id) => is_int($id) && $id > 0);

    $message = Message::sole();
    expect($message->audio_status)->toBe(Message::AUDIO_PROCESSING)
        ->and($message->isAudio())->toBeTrue()
        ->and($message->body)->toBe('Mensagem de voz')
        ->and($message->sender_id)->toBe($member->id);

    // Sanitização é despachada para fora do request.
    Queue::assertPushed(ProcessChatAudio::class, fn ($job) => $job->messageId === $message->id);

    // Cobrou a janela UMA vez (custo do tier = 2 para não-assinante) e creditou 80%.
    $access = ChatAccess::sole();
    expect($access->hasFullAccess())->toBeTrue()
        ->and(TokenLedger::find($access->spend_ledger_id)->amount)->toBe(-2)
        ->and(TokenLedger::find($access->credit_ledger_id)->amount)->toBe('1.6000')
        ->and(app(TokenService::class)->balance($member))->toBe(3); // 5 - 2
});

it('does not charge again for a voice message sent inside an open window', function () {
    Queue::fake();
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // Janela já paga.
    grantChatAccess($member, $conversation);
    expect(app(TokenService::class)->balance($member))->toBe(3);

    $this->actingAs($member)
        ->post(route('chat.messages.audio', $conversation->id), ['audio' => cvmUpload()])
        ->assertStatus(201);

    expect(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(1)
        ->and(app(TokenService::class)->balance($member))->toBe(3); // inalterado
});

// ─── 2. Performer manda de graça ───────────────────────────────────────────────

it('lets the performer send a voice message for free, opening no access window', function () {
    Queue::fake();
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);

    $message = app(ChatService::class)->sendVoiceMessage($conversation, $performer->user, cvmUpload());

    expect($message->audio_status)->toBe(Message::AUDIO_PROCESSING)
        ->and($message->sender_id)->toBe($performer->user_id)
        ->and(ChatAccess::count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'spend_chat_access')->count())->toBe(0);

    Queue::assertPushed(ProcessChatAudio::class);
});

// ─── 3. Saldo insuficiente: recusa, sem mensagem, sem cru órfão ────────────────

it('refuses a voice message when the member cannot afford the window, leaving nothing behind', function () {
    Queue::fake();
    $performer = chatPerformer();
    $member = chatMember(balance: 1); // custo do tier é 2 → insuficiente

    // Conversa aberta manualmente (sem janela paga): o 1º envio tentaria cobrar.
    $conversation = Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->id,
        'status' => 'active',
    ]);

    $this->actingAs($member)
        ->post(route('chat.messages.audio', $conversation->id), ['audio' => cvmUpload()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(Message::count())->toBe(0)
        ->and(ChatAccess::count())->toBe(0);
    // Nenhum cru deixado no disco (o rollback apaga o temporário).
    expect(Storage::disk(ChatAudioStore::DISK)->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});

// ─── 4. Validação de upload ────────────────────────────────────────────────────

it('rejects a non-audio upload (422)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // sample.mp4 é video/mp4 — fora do allowlist de áudio.
    $this->actingAs($member)
        ->post(route('chat.messages.audio', $conversation->id), [
            'audio' => new UploadedFile(base_path('tests/fixtures/sample.mp4'), 'x.mp4', 'video/mp4', null, true),
        ])
        ->assertStatus(422);

    expect(Message::count())->toBe(0);
});

it('rejects an audio upload above the size cap (422)', function () {
    config(['voice.chat_max_bytes' => 1024]); // 1 KB — o fixture de ~33 KB excede
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    $this->actingAs($member)
        ->post(route('chat.messages.audio', $conversation->id), ['audio' => cvmUpload()])
        ->assertStatus(422);
});

// ─── 5. Job de sanitização ─────────────────────────────────────────────────────

it('the job sanitizes, stores the mp3 and marks the message ready', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $performer->user_id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_PROCESSING,
    ]);

    $rawPath = 'tmp/'.$conversation->id.'/'.Str::random(10);
    Storage::disk(ChatAudioStore::DISK)->put($rawPath, 'raw-bytes');

    $voice = Mockery::mock(App\Services\VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'SANITIZED-MP3'));
    $voice->shouldReceive('probeDurationSeconds')->once()->andReturn(9.0);

    (new ProcessChatAudio($message->id, $rawPath))->handle($voice, app(ChatAudioStore::class));

    $message->refresh();
    expect($message->audio_status)->toBe(Message::AUDIO_READY)
        ->and($message->audio_path)->not->toBeNull()
        ->and($message->audio_duration_seconds)->toBe(9)
        ->and($message->audio_content_hash)->not->toBeNull();
    Storage::disk(ChatAudioStore::DISK)->assertExists($message->audio_path)->assertMissing($rawPath);
});

it('the job marks the message failed when ffmpeg fails, cleaning the raw', function () {
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $performer->user_id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_PROCESSING,
    ]);
    $rawPath = 'tmp/'.$conversation->id.'/'.Str::random(10);
    Storage::disk(ChatAudioStore::DISK)->put($rawPath, 'raw');

    $voice = Mockery::mock(App\Services\VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andThrow(App\Exceptions\VoiceProcessingException::encodeFailed());

    (new ProcessChatAudio($message->id, $rawPath))->handle($voice, app(ChatAudioStore::class));

    expect($message->refresh()->audio_status)->toBe(Message::AUDIO_FAILED);
    Storage::disk(ChatAudioStore::DISK)->assertMissing($rawPath);
});

it('the job rejects (failed) when the processed mp3 exceeds the duration cap', function () {
    config(['voice.chat_max_duration_seconds' => 120]);
    $performer = chatPerformer();
    [, $conversation] = chatUnlockedPair($performer);

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $performer->user_id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_PROCESSING,
    ]);
    $rawPath = 'tmp/'.$conversation->id.'/'.Str::random(10);
    Storage::disk(ChatAudioStore::DISK)->put($rawPath, 'raw');

    $voice = Mockery::mock(App\Services\VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andReturnUsing(fn ($s, $d) => file_put_contents($d, 'MP3'));
    $voice->shouldReceive('probeDurationSeconds')->once()->andReturn(200.0); // > 120

    (new ProcessChatAudio($message->id, $rawPath))->handle($voice, app(ChatAudioStore::class));

    expect($message->refresh()->audio_status)->toBe(Message::AUDIO_FAILED);
});

// ─── 6. Serving dos bytes: participação + paywall + ready ──────────────────────

it('serves a ready voice message to the member with an open window', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('serves a ready voice message to the performer (never paywalled)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $message = cvmReadyAudio($conversation, $member);

    $this->actingAs($performer->user)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});

it('returns 404 for a non-participant', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    $outsider = chatMember(balance: 5);

    $this->actingAs($outsider)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertNotFound();
});

it('returns 404 when the paywall is locked (grace/expired reading)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $access = grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    // Janela vencida, dentro da carência: leitura BLOQUEADA (locked).
    $access->forceFill(['expires_at' => now()->subDay(), 'grace_ends_at' => now()->addDays(5)])->save();

    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertNotFound();
});

it('returns 404 for an audio message that is not ready yet', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $performer->user_id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_PROCESSING,
    ]);

    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $message->id]))
        ->assertNotFound();
});

it('returns 404 when the message belongs to another conversation', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    // Áudio pronto, porém de OUTRA conversa: o id não vira janela para esta.
    $otherPerformer = chatPerformer();
    [, $otherConversation] = chatUnlockedPair($otherPerformer, balance: 5);
    $foreign = cvmReadyAudio($otherConversation, $otherPerformer->user);

    $this->actingAs($member)
        ->get(route('chat.audio', [$conversation->id, $foreign->id]))
        ->assertNotFound();
});

// ─── 7. O áudio é servido ao membro na prop da tela só quando ready+unlocked ───

it('exposes the audio_url on the chat screen only when ready and unlocked', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    $this->actingAs($member)
        ->get(route('chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat/Show')
            ->where('messages.data.0.audio_status', Message::AUDIO_READY)
            ->where('messages.data.0.audio_url', fn ($url) => str_contains((string) $url, '/chat/'.$conversation->id.'/audio/'.$message->id))
        );
});

// ─── 8. Moderação: o áudio é denunciável e o moderador ouve a prova ────────────

it('lets a participant report a voice message and the moderator hears the reported audio', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    // O membro denuncia o áudio recebido.
    $this->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'message',
            'reportable_id' => $message->id,
            'reason' => 'coercion',
        ])
        ->assertOk();

    expect(Report::where('reportable_id', $message->id)->count())->toBe(1);

    // O moderador OUVE a prova pelo endpoint dedicado.
    $this->actingAs(cvmModerator())
        ->get(route('moderacao.evidence.message-audio', $message->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('denies the reported audio evidence to a consumer', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);
    $message = cvmReadyAudio($conversation, $performer->user);

    Report::open($member, $message, 'coercion', 'denúncia');

    $this->actingAs(chatMember())
        ->get(route('moderacao.evidence.message-audio', $message->id))
        ->assertForbidden();
});

it('returns 404 for message audio evidence with no open report', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $message = cvmReadyAudio($conversation, $performer->user);

    // Sem denúncia apontando para ela → 404 (indistinguível de inexistente).
    $this->actingAs(cvmModerator())
        ->get(route('moderacao.evidence.message-audio', $message->id))
        ->assertNotFound();
});

it('exposes the audio kind on the report detail page for a reported voice message', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    $message = cvmReadyAudio($conversation, $performer->user);
    $report = Report::open($member, $message, 'coercion', 'denúncia');

    $this->actingAs(cvmModerator())
        ->get(route('moderacao.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('evidence.kind', 'audio')
            ->where('evidence.available', true)
            ->where('evidence.url', fn ($url) => str_contains((string) $url, '/evidencia/mensagem/'.$message->id.'/audio'))
        );
});
