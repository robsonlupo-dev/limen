<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistência dos bytes do Story: higieniza e grava no disco próprio.
 * Ver docs/SECURITY_ISSUES.md § 2.3 e § 2.5.
 *
 * ── Por que NÃO é o MemberPhotoStore, e nem cifra como ele (§ 2.5) ──────────
 * A decisão que amarra as duas features é justamente que elas **não
 * compartilham estratégia de storage**, apesar de a spec original ter dado a
 * ambas uma coluna `*_encrypted`:
 *
 *  - Foto de membro: 1-para-poucos, pequena, altamente sensível, enviada sob
 *    expectativa de privacidade → cifrar vale o custo.
 *  - Story: 1-para-MUITOS, maior, conteúdo que a performer ESCOLHEU publicar →
 *    cifrar compra pouco e custa muito. `Crypt::encryptString` carrega o arquivo
 *    inteiro em memória e não permite Range nem seek; N viewers concorrentes ×
 *    tamanho do arquivo é DoS auto-infligido numa feature desenhada para ter
 *    audiência simultânea. É o mesmo bloqueio que as FC Sessions encontraram, e
 *    envelope encryption + KMS é projeto, não feature.
 *
 * O que substitui a cifra é a autorização a cada request na camada de serving
 * (§ 2.3), sobre um disco com `serve => false` e **sem URL assinada** — assinatura
 * não amarra viewer nenhum, e a URL do membro Black seria um bearer token colável
 * no WhatsApp. O risco residual é "quem tem SSH lê o arquivo", que já é verdade
 * para os avatares. **Não introduza cifra aqui sem refazer a conta do § 2.5, e
 * não retire a autorização por request achando que a cifra cobre.**
 *
 * ── A DISCIPLINA de path é copiada verbatim do MemberPhotoStore ─────────────
 * É ela que fecha traversal: o nome vem do chamador (nunca do usuário), a
 * extensão vem do que o SERVIDOR produziu (nunca do filename do cliente) e o
 * diretório é o id do perfil. O que não é copiado é o sufixo `.enc`, porque aqui
 * o objeto no disco É uma imagem servível — e essa diferença tem de ficar
 * visível no nome.
 */
class PerformerStoryStore
{
    public const DISK = 'performer_stories';

    public function __construct(private ImageProcessingService $images) {}

    /**
     * Higieniza e grava. Devolve o caminho no disco.
     *
     * O strip de EXIF/GPS é OBRIGATÓRIO aqui, não opcional (§ 2.5, consequência
     * nº 2 da decisão nº 2 do PO): imagem publicada por performer também carrega
     * coordenadas, e um Story revelaria a localização de alguém cujo documento e
     * selfie estão na plataforma. O re-encode mata EXIF e polyglot no mesmo passo
     * — o arquivo servido deixa de ser o arquivo enviado —, e isso vale ainda mais
     * aqui do que na foto do membro, porque estes bytes serão servidos a MUITAS
     * pessoas e não estão atrás de uma camada de cifra.
     *
     * O nome é aleatório (40 caracteres) e não deriva de nada do upload: nome
     * previsível é alvo para adivinhação em qualquer falha futura de authz, e nome
     * derivado do filename do cliente é o vetor de traversal. A extensão `.jpg` é
     * asserção, não suposição: o ImageProcessingService SEMPRE devolve JPEG,
     * porque o arquivo é gerado a partir do bitmap.
     *
     * ── Devolve caminho E hash, e o hash é calculado AQUI ───────────────────
     * Sobre os bytes que a plataforma acabou de gravar, depois do re-encode
     * (§ 2.4, parte 1). Este é o único ponto do fluxo que tem os bytes finais em
     * memória: calcular fora obrigaria a reler o arquivo do disco, e calcular
     * ANTES do re-encode produziria o hash de um arquivo que nunca existiu do
     * nosso lado. É a prova que sobrevive à destruição do conteúdo — ver a
     * migration `add_content_hash_to_performer_stories`.
     *
     * @return array{path: string, hash: string}
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

            $path = $performerProfileId.'/'.Str::random(40).'.jpg';

            // O retorno é CONFERIDO. O disco roda com `throw => false`, então
            // disco cheio ou permissão errada devolve `false` em silêncio — e sem
            // esta checagem `store()` entregaria um caminho válido para uma
            // gravação que não aconteceu: a linha seria criada, o story apareceria
            // no feed e o serving nunca abriria os bytes.
            if (! Storage::disk(self::DISK)->put($path, $bytes)) {
                // O `write` do Flysystem local é `file_put_contents`: disco cheio
                // devolve `false` DEPOIS de deixar um arquivo truncado. Sem esta
                // limpeza o resto fica no volume sem linha — órfão desde o
                // nascimento, e todo o GC parte da tabela.
                Storage::disk(self::DISK)->delete($path);

                throw new RuntimeException('Falha ao gravar o story no disco.');
            }

            return [
                'path' => $path,
                // Sobre os MESMOS bytes que foram para o disco. `hash()` e não
                // `Hash::` (que é bcrypt, com salt): aqui o valor precisa ser
                // determinístico, senão não casa nada — não é senha, é impressão
                // digital de arquivo.
                'hash' => hash('sha256', $bytes),
            ];
        } finally {
            // O temporário do service é responsabilidade do chamador. O SO limpa
            // o tmp, mas não na hora.
            @unlink($processed);
        }
    }

    /**
     * Bytes do story.
     *
     * **Não confere prazo** — de propósito. A expiração é conferida na leitura
     * pelo `PerformerStoryService`/pelo escopo `active()` (§ 2.8), que é quem
     * enxerga o story E o espectador; um segundo lugar decidindo o mesmo daria
     * duas respostas para divergir. Este método é a porta dos bytes, e ninguém
     * deve chamá-lo direto: o serving do PR 2 entra pelo Service, com a
     * autorização de visibilidade reavaliada no request (§ 2.3).
     *
     * O `Content-Type` da resposta sai de re-sniff no SERVIDOR, nunca do que o
     * upload declarou — mesma regra da foto efêmera.
     */
    public function retrieve(string $path): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);

        if ($bytes === null) {
            // Sem o caminho na mensagem: esta exceção sobe para log, e o caminho
            // é o layout do disco.
            throw new RuntimeException('Story ausente no disco.');
        }

        return $bytes;
    }

    /**
     * Hard delete dos bytes. Não há soft delete de arquivo.
     *
     * **Falha ao apagar LANÇA**, e é o que dá lastro à ordem bytes → banco do
     * `PerformerStoryService::destroy()`. O disco roda com `throw => false` (o
     * `retrieve()` depende de `get()` devolver null em vez de estourar), então
     * quem não confere o retorno não fica sabendo: um `chown` errado num deploy
     * faz `delete()` devolver `false`, o GC segue, soft-deleta a linha e o
     * conteúdo fica no disco — fora do alcance de qualquer rodada seguinte,
     * porque a linha saiu do escopo padrão. Sem esta checagem, a expiração de 24h
     * é cosmética justamente no cenário que o alarme `stale` existe para detectar.
     *
     * Apagar caminho inexistente é sucesso (Flysystem é idempotente aqui), então
     * a re-tentativa do GC sobre um story cujos bytes já saíram não estoura.
     */
    public function delete(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            throw new RuntimeException('Falha ao apagar o story do disco.');
        }
    }

    /**
     * Ainda no disco?
     *
     * É o que dá sentido ao alarme do GC: "vencidos e ainda presentes" só é
     * medível se dá para perguntar pelo arquivo.
     */
    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
