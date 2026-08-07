<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência dos bytes da galeria de fotos: higieniza e grava no disco próprio.
 *
 * É a cópia deliberada do PerformerStoryStore, MENOS o `content_hash` — foto de
 * perfil é conteúdo público que não passa pelo pipeline de moderação por hash
 * (não é denunciável como o story/foto efêmera na v1). O que se mantém idêntico,
 * e é o que importa:
 *
 *  - **Sem cifra** (§ R1 do PO): como o story, é imagem em claro servida a muitos.
 *    O que substitui a cifra é servir os bytes por request num disco `serve =>
 *    false`, com Content-Type de re-sniff no servidor — nunca uma URL de disco.
 *  - **Re-encode obrigatório**: `ImageProcessingService` mata EXIF/GPS e polyglot
 *    no mesmo passo. Foto de perfil também carrega coordenadas, e o original cru
 *    NÃO é guardado (§ R1). O arquivo servido deixa de ser o arquivo enviado.
 *  - **Disciplina de path**: nome aleatório (nunca derivado do upload → traversal),
 *    diretório = id do perfil, extensão `.jpg` asseverada (o service SEMPRE
 *    devolve JPEG, gerado a partir do bitmap).
 *  - **put/delete CONFERIDOS**: o disco roda `throw => false`, então falha volta
 *    `false` em silêncio — este Store lança ele mesmo, como o do story.
 */
class PerformerPhotoStore
{
    public const DISK = 'performer_photos';

    public function __construct(
        private ImageProcessingService $images,
        private CsamScanService $csam,
    ) {}

    /**
     * Higieniza e grava. Devolve o caminho no disco.
     *
     * O temporário do service é apagado no `finally` — responsabilidade do
     * chamador (o SO limpa o tmp, mas não na hora).
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     */
    public function store(UploadedFile $file, int $performerProfileId, ?User $uploader = null): string
    {
        $processed = $this->images->process($file);

        try {
            $bytes = file_get_contents($processed);

            if ($bytes === false) {
                throw new RuntimeException('Falha ao ler a imagem higienizada.');
            }

            // Anti-CSAM (Sprint 16): confere o phash antes de gravar.
            $this->csam->scanBytes($bytes, 'gallery', $uploader);

            $path = $performerProfileId.'/'.Str::random(40).'.jpg';

            // Retorno CONFERIDO: com `throw => false`, disco cheio devolve `false`
            // DEPOIS de deixar um arquivo truncado. Sem a limpeza + o throw, a
            // linha seria criada apontando para bytes que não existem.
            if (! Storage::disk(self::DISK)->put($path, $bytes)) {
                Storage::disk(self::DISK)->delete($path);

                throw new RuntimeException('Falha ao gravar a foto no disco.');
            }

            return $path;
        } finally {
            @unlink($processed);
        }
    }

    /**
     * Bytes da foto. O Content-Type da resposta sai de re-sniff no SERVIDOR
     * (ServesPhotoBytes), nunca do que o upload declarou.
     */
    public function retrieve(string $path): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);

        if ($bytes === null) {
            // Sem o caminho na mensagem: ela sobe para log, e o caminho é o
            // layout do disco.
            throw new RuntimeException('Foto ausente no disco.');
        }

        return $bytes;
    }

    /**
     * Ainda no disco? (usado por checagens/GC eventuais.)
     */
    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Hard delete dos bytes. **Falha ao apagar LANÇA**, e é o que dá lastro à
     * ordem bytes → banco do PerformerPhotoService::delete(): um `chown` errado
     * num deploy faz o disco (`throw => false`) devolver `false` em silêncio, e
     * sem esta checagem a linha sumiria deixando o arquivo órfão para sempre.
     *
     * Apagar caminho inexistente é sucesso (Flysystem é idempotente aqui).
     */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar a foto do disco.');
        }
    }
}
