<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência das fotos efêmeras: higieniza, cifra e grava no disco próprio.
 *
 * ── Por que não é o KycDocumentStore (§ 1.10) ───────────────────────────────
 * A DISCIPLINA de path dele é copiada verbatim, porque é ela que fecha
 * traversal: o nome vem do chamador (nunca do usuário), a extensão vem do que o
 * servidor produziu (nunca do filename do cliente) e o sufixo `.enc` marca o
 * objeto como ciphertext, não como imagem servível. A CLASSE é separada porque
 * as regras de retenção divergem e o DeletionService trata os dois discos de
 * forma diferente — o `kyc` é "destruir no encerramento", este é "expira
 * sozinho e fica fora do backup".
 *
 * ── Ordem das operações, e ela não é indiferente ────────────────────────────
 * Higienizar ANTES de cifrar. O re-encode do ImageProcessingService (§ 1.4) mata
 * EXIF/GPS e polyglot porque o arquivo servido deixa de ser o arquivo enviado;
 * cifrar primeiro só produziria um envelope em volta das coordenadas de casa do
 * membro, que a performer pode baixar antes do TTL. Apagar depois não desfaz o
 * download.
 *
 * ── Custo em disco ──────────────────────────────────────────────────────────
 * `Crypt::encryptString` tem overhead medido de **1.78x** (base64 aplicado duas
 * vezes na serialização). É o número que sustenta o cap de 5 fotos do § 1.6 —
 * ~9 MB por foto no teto de upload, ~45 MB por membro, e o teto não cresce com o
 * tempo porque o que se conta são ativas, não uploads.
 */
class MemberPhotoStore
{
    public const DISK = 'member_photos';

    public function __construct(private ImageProcessingService $images) {}

    /**
     * Higieniza, cifra e grava. Devolve o caminho no disco.
     *
     * O nome é aleatório (40 caracteres) e não deriva de nada do upload: um nome
     * previsível daria a quem tivesse leitura no disco um alvo para adivinhar, e
     * um nome derivado do filename do cliente é o vetor de traversal que o
     * KycDocumentStore já fecha. A extensão é `.jpg` fixa e isso é asserção, não
     * suposição: o ImageProcessingService SEMPRE devolve JPEG, porque o arquivo
     * é gerado a partir do bitmap.
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     */
    public function store(UploadedFile $file, int $userId): string
    {
        $processed = $this->images->process($file);

        try {
            $bytes = file_get_contents($processed);

            if ($bytes === false) {
                throw new RuntimeException('Falha ao ler a imagem higienizada.');
            }

            $path = $userId.'/'.Str::random(40).'.jpg.enc';

            Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes));

            return $path;
        } finally {
            // O temporário do service é responsabilidade do chamador. O SO limpa
            // o tmp, mas não na hora — e até lá é uma cópia EM CLARO da foto,
            // fora do disco cifrado e fora de qualquer TTL.
            @unlink($processed);
        }
    }

    /**
     * Decifra e devolve os bytes.
     *
     * **Não confere prazo** — de propósito. A expiração é conferida na leitura
     * pelo MemberPhotoService (§ 1.3), que é quem enxerga a foto E o acesso; um
     * segundo lugar decidindo o mesmo daria duas respostas para divergir. Este
     * método é a porta dos bytes, e ninguém deve chamá-lo direto.
     */
    public function retrieve(string $path): string
    {
        $payload = Storage::disk(self::DISK)->get($path);

        if ($payload === null) {
            // Sem o caminho na mensagem: ele carrega o id do titular, e esta
            // exceção sobe para log. Ver o princípio 4 do CLAUDE.md.
            throw new RuntimeException('Foto efêmera ausente no disco.');
        }

        return Crypt::decryptString($payload);
    }

    /** Hard delete. Não há soft delete de BYTES — é o produto inteiro. */
    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Ainda no disco?
     *
     * É o que dá sentido ao alarme do GC (§ 1.3): "vencidas e ainda presentes"
     * só é medível se dá para perguntar pelo arquivo sem decifrá-lo.
     */
    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /** Bytes ocupados no disco — o ciphertext, que é o que enche o volume. */
    public function size(string $path): int
    {
        return (int) Storage::disk(self::DISK)->size($path);
    }
}
