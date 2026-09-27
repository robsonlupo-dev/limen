<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência dos bytes de um item de destaque (roadmap social, Onda 1a).
 *
 * Os bytes vêm de um story JÁ higienizado (EXIF removido, re-encodado, CSAM
 * escaneado no publish). Aqui eles são COPIADOS para o disco permanente
 * 'performer_highlights' — mesma disciplina de path do PerformerStoryStore
 * (nome aleatório, extensão do servidor, diretório = id do perfil), e um
 * re-scan CSAM defensivo (a base pode ter mudado desde o publish do story).
 */
class HighlightStore
{
    public const DISK = 'performer_highlights';

    public function __construct(private CsamScanService $csam) {}

    /**
     * Copia bytes já processados para o disco de destaques. Devolve caminho e hash.
     *
     * @return array{path: string, hash: string}
     *
     * @throws \App\Exceptions\CsamDetectedException  match no re-scan
     */
    public function putBytes(string $bytes, int $performerProfileId, ?User $uploader = null): array
    {
        // Re-scan defensivo: os bytes já passaram no publish do story, mas a base
        // anti-CSAM pode ter mudado. Match → bloqueia, nada é gravado.
        $this->csam->scanBytes($bytes, 'highlight', $uploader);

        $path = $performerProfileId.'/'.Str::random(40).'.jpg';

        if (! Storage::disk(self::DISK)->put($path, $bytes)) {
            Storage::disk(self::DISK)->delete($path); // limpa truncado (put=false deixa lixo)

            throw new RuntimeException('Falha ao gravar o destaque no disco.');
        }

        return ['path' => $path, 'hash' => hash('sha256', $bytes)];
    }

    public function retrieve(string $path): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);

        if ($bytes === null) {
            throw new RuntimeException('Destaque ausente no disco.');
        }

        return $bytes;
    }

    /** Hard delete. Lança se o disco recusar (lastro da ordem bytes → banco). */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar o destaque do disco.');
        }
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
