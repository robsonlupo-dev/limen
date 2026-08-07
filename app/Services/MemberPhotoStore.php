<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Models\User;
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

    public function __construct(
        private ImageProcessingService $images,
        private CsamScanService $csam,
    ) {}

    /**
     * Higieniza, cifra e grava. Devolve o caminho no disco e o hash do conteúdo.
     *
     * O nome é aleatório (40 caracteres) e não deriva de nada do upload: um nome
     * previsível daria a quem tivesse leitura no disco um alvo para adivinhar, e
     * um nome derivado do filename do cliente é o vetor de traversal que o
     * KycDocumentStore já fecha. A extensão é `.jpg` fixa e isso é asserção, não
     * suposição: o ImageProcessingService SEMPRE devolve JPEG, porque o arquivo
     * é gerado a partir do bitmap.
     *
     * O HASH é calculado aqui porque é aqui que os bytes processados existem em
     * memória — recalculá-lo depois exigiria reler e DECIFRAR o arquivo. E é
     * sobre os bytes em claro, **antes do `Crypt`**: o ciphertext muda a cada
     * gravação (IV aleatório), então hashear o que foi para o disco daria um
     * valor diferente para o mesmo conteúdo, inútil para matching. É a única
     * diferença de mecânica em relação ao `PerformerStoryStore`, cujo disco não
     * é cifrado.
     *
     * @return array{path:string,hash:string}
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     */
    public function store(UploadedFile $file, int $userId, ?User $uploader = null): array
    {
        $processed = $this->images->process($file);

        try {
            $bytes = file_get_contents($processed);

            if ($bytes === false) {
                throw new RuntimeException('Falha ao ler a imagem higienizada.');
            }

            // Anti-CSAM (Sprint 16): confere o phash dos bytes EM CLARO, antes do
            // Crypt e antes de gravar. Match → bloqueia (nada é gravado/cifrado).
            // Esta é a superfície de maior risco de conteúdo ilegal (§ Foto Efêmera).
            $this->csam->scanBytes($bytes, 'member_photo', $uploader);

            $path = $userId.'/'.Str::random(40).'.jpg.enc';

            // O retorno é CONFERIDO. O disco roda com `throw => false`, então
            // disco cheio ou permissão errada devolve `false` em silêncio — e
            // sem esta checagem `store()` entregaria um caminho válido para uma
            // gravação que não aconteceu. A linha seria criada, a tela do membro
            // listaria "compartilhada" e o serving nunca abriria os bytes:
            // exatamente o estado que a ordem bytes-primeiro existe para evitar.
            if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes))) {
                // O `write` do Flysystem local é `file_put_contents`: disco cheio
                // devolve `false` DEPOIS de deixar um arquivo truncado. Sem esta
                // limpeza, o resto fica no volume como ciphertext indecifrável e
                // sem linha — órfão desde o nascimento, e o GC parte da tabela.
                Storage::disk(self::DISK)->delete($path);

                throw new RuntimeException('Falha ao gravar a foto efêmera no disco.');
            }

            return [
                'path' => $path,
                // `hash()` e não `hash_file()`: os bytes já estão aqui, e o
                // arquivo no disco é o ciphertext — hasheá-lo não serviria.
                'hash' => hash('sha256', $bytes),
            ];
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

    /**
     * Hard delete. Não há soft delete de BYTES — é o produto inteiro.
     *
     * **Falha ao apagar LANÇA**, e isso é o que dá lastro à ordem bytes → banco
     * do `MemberPhotoService::destroy()`. O disco roda com `throw => false`
     * (o `retrieve()` depende do `get()` devolvendo null em vez de estourar),
     * então quem não confere o retorno não fica sabendo: um `chown` errado num
     * deploy faz `delete()` devolver `false`, o GC segue, soft-deleta a linha e
     * o rosto do membro fica no disco — fora do alcance de qualquer rodada
     * seguinte, porque a linha saiu do escopo padrão. Sem esta checagem, a
     * efemeridade é cosmética justamente no cenário que o alarme `stale` existe
     * para detectar.
     *
     * Apagar caminho inexistente é sucesso (Flysystem é idempotente aqui), então
     * a re-tentativa do GC sobre uma foto cujos bytes já saíram não estoura.
     */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            // Sem o caminho na mensagem: ele carrega o id do titular (princípio 4).
            throw new RuntimeException('Falha ao apagar a foto efêmera do disco.');
        }
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
