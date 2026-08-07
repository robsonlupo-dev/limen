<?php

namespace App\Services;

use App\Exceptions\PhotoGalleryException;
use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ciclo de vida da galeria de fotos do perfil (Sprint 10): adicionar sob o cap
 * de 6, apagar (só a dona) e reordenar. É a dona única dessas três regras — o
 * controller só traduz para HTTP, e uma segunda cópia da regra do cap divergiria
 * exatamente no sentido que fura o teto.
 *
 * A fronteira com o PerformerPhotoStore é a mesma do par Story/StoryStore: este
 * serviço decide QUANDO e SE, o Store cuida dos bytes. `PerformerProfile::MAX_PHOTOS`
 * é a fonte do número, aplicada aqui sob lock.
 */
class PerformerPhotoService
{
    public function __construct(private PerformerPhotoStore $store) {}

    /**
     * As fotos da galeria, na ordem do carrossel. Passa pela relação (que já
     * ordena por `position`) para que toda leitura veja a mesma sequência.
     *
     * @return Collection<int, PerformerPhoto>
     */
    public function forProfile(PerformerProfile $profile): Collection
    {
        return $profile->photos()->get();
    }

    /**
     * Adiciona uma foto, respeitando o cap de 6 SOB LOCK.
     *
     * ── Ordem bytes → banco, e o cap conferido dentro da transação ──────────
     * O re-encode + gravação acontece ANTES da transação: é a operação cara, e
     * segurá-la sob o lock da linha do perfil serializaria uploads concorrentes
     * pelo tempo todo do processamento. Já a checagem do cap e o INSERT vão
     * DENTRO da transação, com a linha do perfil travada (`lockForUpdate`): sem
     * isso, dois uploads simultâneos na 6ª vaga (o throttle permite 10/min)
     * leriam os dois "tem 5" e gravariam a 7ª foto.
     *
     * Se o cap estourar (ou o INSERT falhar) depois de os bytes já terem sido
     * gravados, eles são apagados no catch — senão o arquivo ficaria órfão no
     * disco, sem linha, fora do alcance de qualquer varredura.
     *
     * @throws \App\Exceptions\ImageProcessingException entrada de imagem recusada
     * @throws PhotoGalleryException cap atingido
     */
    public function add(PerformerProfile $profile, UploadedFile $file): PerformerPhoto
    {
        $path = $this->store->store($file, (int) $profile->getKey(), $profile->user);

        try {
            return DB::transaction(function () use ($profile, $path) {
                // Trava a linha do perfil para serializar uploads concorrentes —
                // o cap tem de ser contado e o INSERT feito atomicamente.
                PerformerProfile::whereKey($profile->getKey())->lockForUpdate()->first();

                $count = PerformerPhoto::where('performer_profile_id', $profile->getKey())->count();

                if ($count >= PerformerProfile::MAX_PHOTOS) {
                    throw PhotoGalleryException::capReached(PerformerProfile::MAX_PHOTOS);
                }

                // Vai para o FIM do carrossel. `max('position')` já sob o lock:
                // é o maior valor atual + 1, sem colisão com um upload paralelo.
                $nextPosition = (int) PerformerPhoto::where('performer_profile_id', $profile->getKey())
                    ->max('position');

                return $profile->photos()->create([
                    'path' => $path,
                    'position' => $nextPosition + 1,
                ]);
            });
        } catch (Throwable $e) {
            // Bytes órfãos: a linha não foi criada (cap ou falha), então os bytes
            // gravados acima não têm dono. Limpeza best-effort — se o disco
            // recusar, não mascara a exceção original.
            try {
                $this->store->delete($path);
            } catch (Throwable) {
                // engolido de propósito: a exceção que importa é a de baixo
            }

            throw $e;
        }
    }

    /**
     * Apaga uma foto. Só a dona — foto de outra performer devolve NOT_OWNER (o
     * controller traduz para 403), com a MESMA mensagem de "não disponível" para
     * a resposta não confirmar que o id existe e é de outra pessoa.
     *
     * Bytes ANTES do banco (precedente do PerformerStoryStore): se a remoção do
     * arquivo falhar, ela LANÇA e a linha continua de pé — melhor uma linha viva
     * apontando para bytes existentes do que uma linha morta e um arquivo órfão.
     *
     * @throws PhotoGalleryException a foto não é desta performer
     */
    public function delete(PerformerProfile $profile, PerformerPhoto $photo): void
    {
        if ((int) $photo->performer_profile_id !== (int) $profile->getKey()) {
            throw PhotoGalleryException::notOwner();
        }

        $this->store->delete($photo->path);

        $photo->delete();
    }

    /**
     * Reordena a galeria segundo a lista de ids recebida.
     *
     * A lista tem de bater EXATAMENTE com o conjunto de fotos da performer — nem
     * faltando, nem sobrando, nem com id de outra pessoa. Qualquer divergência
     * recusa em bloco (INVALID_ORDER): uma reordenação parcial deixaria posições
     * inconsistentes, e um id alheio na lista seria a porta para escrever na
     * galeria de outra performer.
     *
     * A nova `position` é o ÍNDICE na lista (0,1,2,…) — não há unique em
     * `position`, então a reescrita direta não colide. Tudo numa transação.
     *
     * @param  array<int, int>  $orderedIds  ids na nova ordem
     *
     * @throws PhotoGalleryException a lista não corresponde à galeria
     */
    public function reorder(PerformerProfile $profile, array $orderedIds): void
    {
        $orderedIds = array_values(array_map('intval', $orderedIds));

        $ownedIds = PerformerPhoto::where('performer_profile_id', $profile->getKey())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Conjuntos idênticos (mesmo tamanho + mesmos elementos, sem repetição).
        sort($ownedIds);
        $sortedGiven = $orderedIds;
        sort($sortedGiven);

        if (count($orderedIds) !== count($ownedIds) || $sortedGiven !== $ownedIds) {
            throw PhotoGalleryException::invalidOrder();
        }

        DB::transaction(function () use ($profile, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                PerformerPhoto::where('performer_profile_id', $profile->getKey())
                    ->where('id', $id)
                    ->update(['position' => $index]);
            }
        });
    }
}
