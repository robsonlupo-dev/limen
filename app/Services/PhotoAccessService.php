<?php

namespace App\Services;

use App\Exceptions\PhotoGalleryException;
use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Models\PhotoGrant;
use App\Models\User;
use App\Support\FanAlias;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Visibilidade e acesso por foto da galeria (Sprint 13) — a dona ÚNICA da regra.
 *
 * Duas superfícies leem daqui e NÃO podem divergir: o serving dos bytes
 * (`canView`) e o presenter que desenha o carrossel (`viewerStatesFor`). Se
 * discordassem, o par "tile borrado na tela / 200 no serving" viraria oráculo do
 * que foi publicado como privado — o mesmo buraco que o `StoryVisibilityService`
 * fecha sendo dono das duas pontas (§ 2.3). Por isso as duas derivam do mesmo
 * predicado ("dona OU grant aprovado"), expresso aqui.
 *
 * Toda exposição do MEMBRO à performer passa por `FanAlias`: a fila de pedidos e
 * o approve/revoke falam por `label`/`handle`, nunca pelo `user_id` (que é
 * `$hidden` em `PhotoGrant`). Solicitar acesso é ato DELIBERADO do membro (como
 * uma gorjeta) — expõe o alias, sem Piso de Anonimato, e **não cria Follow**.
 */
class PhotoAccessService
{
    /**
     * O predicado de acesso a UMA foto — usado pelo serving. Foto pública é de
     * todos; privada, só da dona ou de quem tem grant APROVADO. Guest não vê
     * privada.
     */
    public function canView(?User $viewer, PerformerPhoto $photo): bool
    {
        if (! $photo->is_private) {
            return true;
        }

        if ($this->isOwner($viewer, $photo)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        return PhotoGrant::where('performer_photo_id', $photo->id)
            ->where('user_id', $viewer->id)
            ->granted()
            ->exists();
    }

    /**
     * Estado de acesso do espectador a cada foto PRIVADA do perfil, em UMA query
     * — o batch que o presenter usa para não cair em N+1 por card. Retorna
     * `[photoId => 'granted'|'pending'|'none']`. A dona vê tudo como 'granted';
     * o visitante deslogado, tudo como 'none'. Foto pública não entra no mapa (o
     * presenter a trata como visível direto). É o MESMO predicado do `canView`,
     * expresso em lote para as duas concordarem.
     *
     * @return array<int, string>
     */
    public function viewerStatesFor(?User $viewer, PerformerProfile $profile): array
    {
        $privateIds = $profile->photos
            ->where('is_private', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($privateIds === []) {
            return [];
        }

        // A dona vê todas as próprias privadas.
        if ($viewer !== null && (int) ($viewer->performerProfile?->id) === (int) $profile->id) {
            return array_fill_keys($privateIds, 'granted');
        }

        $states = array_fill_keys($privateIds, 'none');

        if ($viewer === null) {
            return $states;
        }

        PhotoGrant::whereIn('performer_photo_id', $privateIds)
            ->where('user_id', $viewer->id)
            ->get(['performer_photo_id', 'granted_at'])
            ->each(function (PhotoGrant $grant) use (&$states) {
                $states[(int) $grant->performer_photo_id] = $grant->granted_at !== null ? 'granted' : 'pending';
            });

        return $states;
    }

    /**
     * O membro solicita acesso a uma foto privada. Idempotente: reenviar devolve
     * o estado atual ('pending' ou 'granted'), sem linha duplicada nem erro sob
     * duplo-submit (lock + catch do unique, precedente do FavoriteService).
     *
     * Recusa (404 uniforme, indistinguível de "foto não existe") quando a foto
     * NÃO é privada — não há o que solicitar numa foto pública — ou o perfil não
     * está no ar: o par não pode virar oráculo de existência de foto privada.
     * **Não cria Follow nem qualquer outro vínculo** além da linha de pedido.
     */
    public function request(User $member, PerformerPhoto $photo): string
    {
        if (! $photo->is_private || ! $this->isLiveProfile($photo)) {
            throw (new ModelNotFoundException)->setModel(PerformerPhoto::class);
        }

        try {
            return DB::transaction(function () use ($member, $photo) {
                $existing = PhotoGrant::where('performer_photo_id', $photo->id)
                    ->where('user_id', $member->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing->granted_at !== null ? 'granted' : 'pending';
                }

                $grant = new PhotoGrant;
                $grant->performer_photo_id = $photo->id;
                $grant->user_id = $member->id;
                $grant->granted_at = null;
                $grant->save();

                return 'pending';
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida perdida: o outro request criou a linha. O estado final é o
            // que importa — releitura fora da transação.
            $row = PhotoGrant::where('performer_photo_id', $photo->id)
                ->where('user_id', $member->id)
                ->first();

            return $row?->granted_at !== null ? 'granted' : 'pending';
        }
    }

    /**
     * A performer aprova um pedido PENDENTE, resolvendo o `member_handle` do
     * FanAlias contra os solicitantes DESTA foto. 404 uniforme (via
     * `resolveTarget`) quando a foto não é dela ou o handle não casa com nenhum
     * pendente — nunca revela "existe mas não é seu" vs "handle inválido".
     */
    public function grant(PerformerProfile $performer, PerformerPhoto $photo, string $handle): void
    {
        $memberId = $this->resolveTarget($performer, $photo, $handle, pendingOnly: true);

        DB::transaction(function () use ($photo, $memberId) {
            $grant = PhotoGrant::where('performer_photo_id', $photo->id)
                ->where('user_id', $memberId)
                ->lockForUpdate()
                ->first();

            // Só carimba o que ainda está pendente — aprovar de novo é no-op.
            if ($grant !== null && $grant->granted_at === null) {
                $grant->granted_at = now();
                $grant->save();
            }
        });
    }

    /**
     * A performer revoga um acesso OU recusa um pedido — o mesmo verbo para os
     * dois, porque os dois são "essa linha deixa de existir". Resolve contra
     * TODAS as linhas da foto (pendente ou concedida). Idempotente: apagar o que
     * já saiu é no-op. 404 uniforme quando não resolve.
     */
    public function revoke(PerformerProfile $performer, PerformerPhoto $photo, string $handle): void
    {
        $memberId = $this->resolveTarget($performer, $photo, $handle, pendingOnly: false);

        PhotoGrant::where('performer_photo_id', $photo->id)
            ->where('user_id', $memberId)
            ->delete();
    }

    /**
     * Alterna a visibilidade de uma foto. Só a dona — foto de outra performer é
     * `NOT_OWNER` (403 uniforme, igual ao delete/reorder). Escrita por atribuição
     * direta: `is_private` está fora do `$fillable`.
     *
     * @throws PhotoGalleryException a foto não é desta performer
     */
    public function setVisibility(PerformerProfile $performer, PerformerPhoto $photo, bool $isPrivate): PerformerPhoto
    {
        if ((int) $photo->performer_profile_id !== (int) $performer->getKey()) {
            throw PhotoGalleryException::notOwner();
        }

        $photo->is_private = $isPrivate;
        $photo->save();

        return $photo;
    }

    /**
     * Os pedidos PENDENTES para as fotos privadas desta performer, para o painel.
     * Cada item já vem pseudonimizado: `FanAlias` (label + handle) e o id da foto,
     * NUNCA o `user_id`. Ordena por chegada (dado interno) — o horário não é
     * exposto, mesma disciplina do painel de visitantes.
     *
     * @return array<int, array{photo_id:int, photo_url:string, fan:string, member_handle:string}>
     */
    public function pendingRequestsFor(PerformerProfile $performer): array
    {
        return PhotoGrant::pending()
            ->whereHas('photo', fn ($q) => $q
                ->where('performer_profile_id', $performer->id)
                ->where('is_private', true))
            ->with('photo')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PhotoGrant $grant) => [
                'photo_id' => (int) $grant->performer_photo_id,
                'photo_url' => route('performer.gallery.image', $grant->performer_photo_id),
                'fan' => FanAlias::label($performer->id, (int) $grant->user_id),
                'member_handle' => FanAlias::handle($performer->id, (int) $grant->user_id),
            ])
            ->all();
    }

    /**
     * Handle → id do membro, contra os candidatos DESTA foto: os pendentes (para
     * aprovar) ou todos (para revogar). A foto tem de ser da performer. Todo modo
     * de falha — foto de outra, handle inventado, ninguém casando — é o MESMO 404,
     * para o par não virar oráculo (disciplina do FanAlias e do MemberNote).
     */
    private function resolveTarget(PerformerProfile $performer, PerformerPhoto $photo, string $handle, bool $pendingOnly): int
    {
        $notFound = fn () => (new ModelNotFoundException)->setModel(PhotoGrant::class);

        if ((int) $photo->performer_profile_id !== (int) $performer->getKey()) {
            throw $notFound();
        }

        $query = PhotoGrant::where('performer_photo_id', $photo->id);

        if ($pendingOnly) {
            $query->pending();
        }

        $candidateIds = $query->pluck('user_id');

        $memberId = FanAlias::resolveHandle($performer->id, $candidateIds, $handle);

        if ($memberId === null) {
            throw $notFound();
        }

        return $memberId;
    }

    private function isOwner(?User $viewer, PerformerPhoto $photo): bool
    {
        if ($viewer === null) {
            return false;
        }

        $profileId = $viewer->performerProfile?->id;

        return $profileId !== null && (int) $profileId === (int) $photo->performer_profile_id;
    }

    /**
     * O perfil dono da foto está no ar? (verificado, conta ativa, não encerrado).
     * É o mesmo recorte do catálogo público — solicitar acesso a foto de perfil
     * fora do ar não deve nem confirmar que a foto existe.
     */
    private function isLiveProfile(PerformerPhoto $photo): bool
    {
        $profile = $photo->performerProfile()->with('user')->first();

        return $profile !== null
            && ! $profile->trashed()
            && (bool) $profile->is_verified
            && $profile->user !== null
            && $profile->user->status === 'active';
    }
}
