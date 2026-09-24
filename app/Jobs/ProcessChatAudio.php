<?php

namespace App\Jobs;

use App\Events\MessageSent;
use App\Exceptions\VoiceProcessingException;
use App\Models\Message;
use App\Services\ChatAudioStore;
use App\Services\VoiceProcessingService;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sanitiza a mensagem de voz FORA do request (feat/chat-voice-message): re-encode
 * MP3 + strip de TODO metadado, exatamente como a intro (ProcessVoiceIntro), mas o
 * alvo é uma linha de `messages` em vez de uma intro.
 *
 * `processing` → `ready` (path/hash/duração) no sucesso; `failed` no erro (o
 * remetente reenvia). Ao contrário da intro, NÃO há passo de moderação humana: o
 * chat 1:1 entre dois adultos verificados não é pré-moderado (como o texto não é);
 * a denúncia é o caminho depois (a mensagem já é denunciável). O ffmpeg é a
 * defesa de payload/metadado, não de conteúdo.
 *
 * Sucesso re-emite MessageSent: com o Reverb ligado, o destinatário troca o
 * "processando…" pelo player ao vivo; sem Reverb, aparece no próximo reload.
 */
class ProcessChatAudio implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $messageId,
        public string $rawPath,
    ) {}

    public function handle(VoiceProcessingService $voice, ChatAudioStore $store): void
    {
        $message = Message::find($this->messageId);

        if ($message === null || $message->audio_status !== Message::AUDIO_PROCESSING) {
            $this->cleanupRaw($store);

            return;
        }

        $rawAbs = $store->absolutePath($this->rawPath);
        $mp3Tmp = tempnam(sys_get_temp_dir(), 'limen_chat_audio_');

        try {
            $voice->sanitize($rawAbs, $mp3Tmp);

            $duration = $voice->probeDurationSeconds($mp3Tmp);
            $max = (int) config('voice.chat_max_duration_seconds');

            if ($duration !== null && $duration > $max) {
                throw VoiceProcessingException::tooLong($max);
            }

            $stored = $store->putSanitized($mp3Tmp, $message->conversation_id);

            $message->forceFill([
                'audio_status' => Message::AUDIO_READY,
                'audio_path' => $stored['path'],
                'audio_content_hash' => $stored['hash'],
                'audio_duration_seconds' => $duration !== null ? (int) round($duration) : null,
            ])->save();

            Audit::log('chat.voice_processed', $message);

            // Recipientes trocam "processando…" pelo player (Echo quando ligado).
            event(new MessageSent($message));
        } catch (Throwable $e) {
            $this->markFailed($message, $e);
        } finally {
            @unlink($mp3Tmp);
            $this->cleanupRaw($store);
        }
    }

    public function failed(?Throwable $e): void
    {
        $message = Message::find($this->messageId);

        if ($message !== null && $message->audio_status === Message::AUDIO_PROCESSING) {
            $this->markFailed($message, $e);
        }

        try {
            Storage::disk(ChatAudioStore::DISK)->delete($this->rawPath);
        } catch (Throwable) {
            // best-effort
        }
    }

    private function markFailed(Message $message, ?Throwable $e): void
    {
        $message->forceFill(['audio_status' => Message::AUDIO_FAILED])->save();

        Audit::log('chat.voice_failed', $message, [
            'reason' => $e instanceof VoiceProcessingException ? $e->reason : 'unknown',
        ]);

        event(new MessageSent($message));
    }

    private function cleanupRaw(ChatAudioStore $store): void
    {
        try {
            Storage::disk(ChatAudioStore::DISK)->delete($this->rawPath);
        } catch (Throwable) {
            // best-effort
        }
    }
}
