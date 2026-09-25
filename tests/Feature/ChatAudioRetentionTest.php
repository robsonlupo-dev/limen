<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Services\ChatAudioStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Retenção/GC do áudio das mensagens de voz (feat/chat-audio-retention-gc).
 * Eixo: (1) recolhe os bytes de mensagens já soft-deletadas (fora da retenção),
 * mantendo a linha e o hash; (2) NUNCA recolhe áudio sob denúncia aberta; (3) NÃO
 * toca em mensagem viva (bolha tocável não pode ficar sem som); (4) o purge de
 * crus órfãos limpa o tmp/ e poupa os recentes.
 */
beforeEach(function () {
    Storage::fake(ChatAudioStore::DISK);
});

function garAudioMessage(bool $trashed = true): Message
{
    $performer = chatPerformer();
    $member = chatMember();
    $conversation = Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->id,
        'status' => 'active',
    ]);

    $path = $conversation->id.'/'.Str::random(20).'.mp3';
    Storage::disk(ChatAudioStore::DISK)->put($path, 'MP3-BYTES');

    $message = Message::forceCreate([
        'conversation_id' => $conversation->id,
        'sender_id' => $member->id,
        'body' => 'Mensagem de voz',
        'audio_status' => Message::AUDIO_READY,
        'audio_path' => $path,
        'audio_duration_seconds' => 6,
        'audio_content_hash' => hash('sha256', 'MP3-BYTES'),
    ]);

    if ($trashed) {
        $message->delete(); // soft-delete = fim da retenção (o que o purge de acesso faz)
    }

    return $message;
}

// ─── 1. Recolhe os bytes de mensagem retida, mantendo linha + hash ─────────────

it('purges the audio bytes of a soft-deleted message, keeping the row and hash', function () {
    $message = garAudioMessage(trashed: true);
    $path = $message->audio_path;
    $hash = $message->audio_content_hash;

    $this->artisan('chat:purge-audio')->assertSuccessful();

    Storage::disk(ChatAudioStore::DISK)->assertMissing($path);
    $fresh = Message::withTrashed()->find($message->id);
    expect($fresh->audio_path)->toBeNull()          // ponteiro dos bytes some
        ->and($fresh->audio_content_hash)->toBe($hash) // hash (prova) fica
        ->and($fresh->trashed())->toBeTrue();        // a linha continua retida
});

// ─── 2. Áudio sob denúncia ABERTA é intocável ──────────────────────────────────

it('never purges audio while an open report points at the message', function () {
    $message = garAudioMessage(trashed: true);
    $reporter = chatMember();
    Report::open($reporter, $message, 'coercion', 'denúncia');
    $path = $message->audio_path;

    $this->artisan('chat:purge-audio')->assertSuccessful();

    Storage::disk(ChatAudioStore::DISK)->assertExists($path);
    expect(Message::withTrashed()->find($message->id)->audio_path)->toBe($path);
});

it('purges once the report is resolved', function () {
    $message = garAudioMessage(trashed: true);
    $reporter = chatMember();
    $report = Report::open($reporter, $message, 'coercion', 'denúncia');
    $path = $message->audio_path;

    // Fila aberta → retém.
    $this->artisan('chat:purge-audio')->assertSuccessful();
    Storage::disk(ChatAudioStore::DISK)->assertExists($path);

    // Fechada → a próxima rodada recolhe.
    $report->forceFill(['status' => 'resolved'])->save();
    $this->artisan('chat:purge-audio')->assertSuccessful();
    Storage::disk(ChatAudioStore::DISK)->assertMissing($path);
});

// ─── 3. Mensagem VIVA (não retida) não é tocada ────────────────────────────────

it('does not touch the audio of a live (non-soft-deleted) message', function () {
    $message = garAudioMessage(trashed: false);
    $path = $message->audio_path;

    $this->artisan('chat:purge-audio')->assertSuccessful();

    Storage::disk(ChatAudioStore::DISK)->assertExists($path);
    expect(Message::find($message->id)->audio_path)->toBe($path);
});

// ─── 4. Purge de crus órfãos ───────────────────────────────────────────────────

it('chat:purge-orphan-raw deletes stale raw uploads and spares recent ones', function () {
    $disk = Storage::disk(ChatAudioStore::DISK);
    $disk->put('tmp/1/old', 'x');
    $disk->put('tmp/1/fresh', 'y');
    touch($disk->path('tmp/1/old'), now()->getTimestamp() - (config('voice.process_timeout') * 2) - 100);

    $this->artisan('chat:purge-orphan-raw')->assertSuccessful();

    $disk->assertMissing('tmp/1/old')->assertExists('tmp/1/fresh');
});

it('chat:purge-orphan-raw does not blow up without a tmp/ dir', function () {
    $this->artisan('chat:purge-orphan-raw')->assertSuccessful();
});
