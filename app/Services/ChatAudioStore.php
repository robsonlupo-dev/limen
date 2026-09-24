<?php

namespace App\Services;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bytes das mensagens de voz do chat (feat/chat-voice-message). Espelho fiel do
 * VoiceIntroStore, só trocando "performer" por "conversa" no caminho:
 *
 *  - `serve => false`: o MP3 é servido por request autorizado (participação +
 *    paywall), com Content-Type FIXO — nunca por URL de disco.
 *  - `content_hash` = SHA-256 dos bytes JÁ processados (prova sob denúncia).
 *  - put/delete CONFERIDOS: o disco roda `throw => false`; este Store lança.
 */
class ChatAudioStore
{
    public const DISK = 'chat_audio';

    /** Áudio CRU do upload em `tmp/{conversationId}/…` para o job processar depois. */
    public function storeRaw(UploadedFile $file, int $conversationId): string
    {
        $path = $file->storeAs("tmp/{$conversationId}", Str::random(40), self::DISK);

        if ($path === false) {
            throw new RuntimeException('Falha ao guardar o upload de áudio temporário.');
        }

        return $path;
    }

    /** Caminho ABSOLUTO no filesystem (local) — para o ffmpeg. */
    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * Move o MP3 higienizado para o disco. Devolve ['path' => ..., 'hash' => ...]
     * (hash = SHA-256 do MP3 processado).
     */
    public function putSanitized(string $mp3Path, int $conversationId): array
    {
        $hash = hash_file('sha256', $mp3Path);

        if ($hash === false) {
            throw new RuntimeException('Falha ao ler o áudio processado.');
        }

        $path = Storage::disk(self::DISK)->putFileAs(
            (string) $conversationId,
            new File($mp3Path),
            Str::random(40).'.mp3',
        );

        if ($path === false) {
            throw new RuntimeException('Falha ao gravar o áudio no disco.');
        }

        return ['path' => $path, 'hash' => $hash];
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /** Hard delete dos bytes; falha ao apagar LANÇA. */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar o áudio do disco.');
        }
    }
}
