<?php

namespace App\Services;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência dos bytes da intro de voz. Espelho do ContentStore no que toca a
 * vídeo (upload cru em `tmp/`, sanitização no job, hash dos bytes processados):
 *
 *  - **Sem cifra**: áudio em claro servido a muitos (1:N como o Story/Content). O
 *    que substitui a cifra é servir por request num disco `serve => false`, com
 *    Content-Type FIXO no servidor (nós produzimos o MP3) — nunca URL de disco.
 *  - **content_hash = SHA-256 dos bytes JÁ PROCESSADOS** — casa contra listas de
 *    hash conhecidas e vira prova sob denúncia.
 *  - **put/delete CONFERIDOS**: o disco roda `throw => false`; este Store lança.
 */
class VoiceIntroStore
{
    public const DISK = 'performer_voice_intros';

    /**
     * Guarda o áudio CRU do upload num caminho temporário do disco (`tmp/…`), para
     * o job assíncrono processar depois que o request acabar (o temporário do PHP
     * some no fim do request). Devolve o path no disco. NÃO higieniza — isso é do
     * ProcessVoiceIntro + VoiceProcessingService.
     */
    public function storeRaw(UploadedFile $file, int $performerProfileId): string
    {
        $path = $file->storeAs("tmp/{$performerProfileId}", Str::random(40), self::DISK);

        if ($path === false) {
            throw new RuntimeException('Falha ao guardar o upload de áudio temporário.');
        }

        return $path;
    }

    /** Caminho ABSOLUTO no filesystem de um path do disco (local) — para o ffmpeg. */
    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * Move o MP3 higienizado (arquivo local já processado pelo job) para o disco.
     * Devolve ['path' => ..., 'hash' => ...]. hash = SHA-256 do MP3 processado
     * (prova), lido em stream.
     */
    public function putSanitized(string $mp3Path, int $performerProfileId): array
    {
        $hash = hash_file('sha256', $mp3Path);

        if ($hash === false) {
            throw new RuntimeException('Falha ao ler o áudio processado.');
        }

        $path = Storage::disk(self::DISK)->putFileAs(
            (string) $performerProfileId,
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

    /**
     * Hard delete dos bytes. Falha ao apagar LANÇA — dá lastro à ordem bytes →
     * banco do serviço. Apagar inexistente (path vazio já filtrado pelo chamador)
     * é sucesso.
     */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar o áudio do disco.');
        }
    }
}
