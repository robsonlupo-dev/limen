<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência dos bytes do conteúdo permanente. Cópia deliberada do
 * PerformerPhotoStore, MAIS o `content_hash` (como o Story): conteúdo permanente é
 * denunciável (princípio nº 1), e o hash é a prova que sobrevive ao arquivo.
 *
 *  - **Sem cifra**: imagem em claro servida a muitos (1:N como o Story). O que
 *    substitui a cifra é servir por request num disco `serve => false`, com
 *    Content-Type de re-sniff no servidor — nunca URL de disco.
 *  - **Re-encode obrigatório** (ImageProcessingService): mata EXIF/GPS e polyglot;
 *    o arquivo servido deixa de ser o arquivo enviado. Vídeo NÃO passa por aqui —
 *    GD não processa vídeo, então vídeo é um pipeline próprio (fora desta v1).
 *  - **content_hash = SHA-256 dos bytes JÁ PROCESSADOS**, antes de qualquer
 *    gravação — casa contra listas de hash conhecidas e vira prova sob denúncia.
 *  - **put/delete CONFERIDOS**: disco roda `throw => false`; este Store lança.
 */
class ContentStore
{
    public const DISK = 'performer_content';

    public function __construct(private ImageProcessingService $images) {}

    /**
     * Higieniza e grava. Devolve ['path' => ..., 'hash' => ...].
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     */
    public function store(UploadedFile $file, int $performerProfileId): array
    {
        $processed = $this->images->process($file);

        try {
            $bytes = file_get_contents($processed);

            if ($bytes === false) {
                throw new RuntimeException('Falha ao ler a imagem higienizada.');
            }

            $hash = hash('sha256', $bytes);
            $path = $performerProfileId.'/'.Str::random(40).'.jpg';

            if (! Storage::disk(self::DISK)->put($path, $bytes)) {
                Storage::disk(self::DISK)->delete($path);

                throw new RuntimeException('Falha ao gravar o conteúdo no disco.');
            }

            return ['path' => $path, 'hash' => $hash];
        } finally {
            @unlink($processed);
        }
    }

    public function retrieve(string $path): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);

        if ($bytes === null) {
            throw new RuntimeException('Conteúdo ausente no disco.');
        }

        return $bytes;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Hard delete dos bytes. Falha ao apagar LANÇA — dá lastro à ordem bytes →
     * banco do PerformerContentService::remove(). Apagar inexistente é sucesso.
     */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar o conteúdo do disco.');
        }
    }
}
