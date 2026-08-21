<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        private ImageProcessingService $images,
        private CsamScanService $csam,
    ) {}

    /**
     * Higieniza e grava. Devolve ['path' => ..., 'hash' => ...]. O `$uploader` é a
     * conta que envia — para a sinalização anti-CSAM no match.
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     * @throws \App\Exceptions\CsamDetectedException imagem bate na lista de CSAM
     */
    public function store(UploadedFile $file, int $performerProfileId, ?User $uploader = null): array
    {
        $processed = $this->images->process($file);

        try {
            $bytes = file_get_contents($processed);

            if ($bytes === false) {
                throw new RuntimeException('Falha ao ler a imagem higienizada.');
            }

            // Anti-CSAM (Sprint 16): confere o phash ANTES de gravar. Match →
            // bloqueia (nada é gravado). content_id ainda não existe (a linha nasce
            // depois do store); o par context+user identifica a trilha.
            $this->csam->scanBytes($bytes, 'content', $uploader);

            $hash = hash('sha256', $bytes);
            $path = $performerProfileId.'/'.Str::random(40).'.jpg';

            if (! Storage::disk(self::DISK)->put($path, $bytes)) {
                Storage::disk(self::DISK)->delete($path);

                throw new RuntimeException('Falha ao gravar o conteúdo no disco.');
            }

            // Prévia borrada (item 7) para o tile bloqueado. Best-effort: se falhar,
            // o serving 404 e o front cai no placeholder — nunca quebra o upload. O
            // motivo vai para o log (o comando retroativo é quem relata na tela).
            try {
                $this->generateBlur($path, $bytes);
            } catch (\Throwable $e) {
                Log::warning('content.blur.generate_failed', ['path' => $path, 'error' => $e->getMessage()]);
            }

            return ['path' => $path, 'hash' => $hash];
        } finally {
            @unlink($processed);
        }
    }

    /**
     * Guarda o vídeo CRU do upload num caminho temporário do disco (`tmp/…`), para
     * o job assíncrono processar depois que o request acabar (o temporário do PHP
     * some no fim do request). Devolve o path no disco. NÃO higieniza — isso é do
     * ProcessVideoContent + VideoProcessingService.
     */
    public function storeRawVideo(UploadedFile $file, int $performerProfileId): string
    {
        $path = $file->storeAs("tmp/{$performerProfileId}", Str::random(40), self::DISK);

        if ($path === false) {
            throw new RuntimeException('Falha ao guardar o upload de vídeo temporário.');
        }

        return $path;
    }

    /** Caminho ABSOLUTO no filesystem de um path do disco (local) — para o ffmpeg. */
    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * Move o MP4 higienizado + o thumbnail (arquivos locais já processados pelo
     * job) para o disco de conteúdo. Devolve ['path','thumbnail_path','hash'].
     * hash = SHA-256 do MP4 processado (prova, como a foto), lido em stream.
     */
    public function putSanitizedVideo(string $mp4Path, string $thumbPath, int $performerProfileId): array
    {
        $hash = hash_file('sha256', $mp4Path);

        if ($hash === false) {
            throw new RuntimeException('Falha ao ler o vídeo processado.');
        }

        $videoPath = Storage::disk(self::DISK)->putFileAs(
            (string) $performerProfileId,
            new File($mp4Path),
            Str::random(40).'.mp4',
        );

        if ($videoPath === false) {
            throw new RuntimeException('Falha ao gravar o vídeo no disco.');
        }

        $thumbnailPath = Storage::disk(self::DISK)->putFileAs(
            (string) $performerProfileId,
            new File($thumbPath),
            Str::random(40).'.jpg',
        );

        if ($thumbnailPath === false) {
            Storage::disk(self::DISK)->delete($videoPath);

            throw new RuntimeException('Falha ao gravar o thumbnail no disco.');
        }

        // Prévia borrada a partir do POSTER (item 7). Best-effort (log no motivo).
        try {
            $this->generateBlur($thumbnailPath);
        } catch (\Throwable $e) {
            Log::warning('content.blur.generate_failed', ['path' => $thumbnailPath, 'error' => $e->getMessage()]);
        }

        return ['path' => $videoPath, 'thumbnail_path' => $thumbnailPath, 'hash' => $hash];
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
     * Caminho da prévia borrada de um arquivo-fonte: `{nome}.jpg` → `{nome}.blur.jpg`
     * (vale para a foto e para o poster do vídeo). DERIVADO, sem coluna nova.
     */
    public function blurPathFor(string $sourcePath): string
    {
        $dot = strrpos($sourcePath, '.');
        $base = $dot === false ? $sourcePath : substr($sourcePath, 0, $dot);

        return $base.'.blur.jpg';
    }

    /**
     * Gera e grava a prévia borrada de um arquivo-fonte já no disco. LANÇA com o
     * motivo em cada modo de falha — fonte ausente, imagem indecodificável ou falha
     * de escrita —, para o chamador (o comando) poder RELATAR o porquê por peça. Os
     * chamadores do UPLOAD (store/putSanitizedVideo) engolem a exceção (best-effort:
     * o serving 404 → placeholder). `$sourceBytes` evita reler o disco quando o
     * chamador já tem os bytes (o store da foto).
     *
     * @throws RuntimeException fonte ausente ou falha de escrita
     * @throws ImageProcessingException imagem indecodificável
     */
    public function generateBlur(string $sourcePath, ?string $sourceBytes = null): string
    {
        $bytes = $sourceBytes ?? Storage::disk(self::DISK)->get($sourcePath);

        if ($bytes === null) {
            throw new RuntimeException(sprintf(
                'arquivo-fonte ausente no disco "%s": %s (%s)',
                self::DISK,
                $sourcePath,
                Storage::disk(self::DISK)->path($sourcePath),
            ));
        }

        // Pode lançar ImageProcessingException se os bytes não decodificam.
        $blur = $this->images->blurredPreview($bytes);

        $blurPath = $this->blurPathFor($sourcePath);

        if (! Storage::disk(self::DISK)->put($blurPath, $blur)) {
            // Disco com throw => false: put devolve false em silêncio (ex.: diretório
            // sem permissão de escrita para o usuário do CLI). Explicitamos o motivo.
            throw new RuntimeException(sprintf(
                'falha ao gravar a prévia (permissão de escrita ou disco cheio?): %s (%s)',
                $blurPath,
                Storage::disk(self::DISK)->path($blurPath),
            ));
        }

        return $blurPath;
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
