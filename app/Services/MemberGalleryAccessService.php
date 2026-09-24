<?php

namespace App\Services;

use App\Models\MemberGalleryAccess;
use App\Models\MemberGalleryPhoto;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Liberação das fotos PRIVADAS do membro para uma performer
 * (feat/member-gallery-access-requests, Etapa 2). Dona ÚNICA da regra "esta
 * performer pode ver as privadas deste membro". Espelho invertido do
 * PhotoAccessService, com granularidade POR PAR (decisão do PO): liberar abre
 * TODAS as privadas do membro para aquela performer; revogar re-tranca todas.
 *
 * Assimetria de identidade: quem PEDE é a performer (figura pública — nome de
 * palco, slug). O membro vê quem pediu e decide; a identidade DELE segue atrás do
 * FanAlias no serving. Por isso, ao contrário do PhotoAccessService, não há
 * resolução por handle no lado do membro: ele age sobre `performer_profile_id`
 * direto, e só sobre as próprias linhas (member_id = ele).
 *
 * O predicado de leitura (`hasGrant`) é consumido pelo MemberGalleryService
 * (serving + presenter) — as duas pontas derivam daqui, então não divergem
 * (mesma disciplina anti-oráculo da Etapa 1).
 */
class MemberGalleryAccessService
{
    /**
     * A performer solicita acesso às privadas do membro. Idempotente: reenviar
     * devolve o estado atual ('pending'|'granted'), sem duplicar (lock + catch do
     * unique, precedente do FavoriteService/PhotoAccessService).
     *
     * 404 uniforme (indistinguível de "membro não existe") quando o perfil não
     * está visível OU o membro não tem nenhuma foto privada aprovada — não há o
     * que solicitar, e o par não pode virar oráculo. NÃO cria Follow nem vínculo.
     */
    public function request(PerformerProfile $performer, User $member): string
    {
        if (! $member->profile_visible || ! $this->memberHasPrivatePhotos($member)) {
            throw (new ModelNotFoundException)->setModel(MemberGalleryAccess::class);
        }

        try {
            return DB::transaction(function () use ($performer, $member) {
                $existing = MemberGalleryAccess::where('member_id', $member->id)
                    ->where('performer_profile_id', $performer->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing->granted_at !== null ? 'granted' : 'pending';
                }

                $row = new MemberGalleryAccess;
                $row->member_id = $member->id;
                $row->performer_profile_id = $performer->id;
                $row->granted_at = null;
                $row->save();

                return 'pending';
            });
        } catch (UniqueConstraintViolationException) {
            $row = MemberGalleryAccess::where('member_id', $member->id)
                ->where('performer_profile_id', $performer->id)
                ->first();

            return $row?->granted_at !== null ? 'granted' : 'pending';
        }
    }

    /**
     * O membro LIBERA uma performer (aprova o pedido). Só sobre uma linha que
     * EXISTE (a performer pediu) — o membro não "convida" quem não pediu; 404
     * uniforme senão (espelha grant só-sobre-pendente do PhotoAccessService).
     * Idempotente: aprovar de novo é no-op.
     */
    public function grant(User $member, PerformerProfile $performer, ?Request $request = null): void
    {
        DB::transaction(function () use ($member, $performer) {
            $row = MemberGalleryAccess::where('member_id', $member->id)
                ->where('performer_profile_id', $performer->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw (new ModelNotFoundException)->setModel(MemberGalleryAccess::class);
            }

            if ($row->granted_at === null) {
                $row->granted_at = now();
                $row->save();
            }
        });

        Audit::log('member_gallery.access_granted', $member, ['performer_profile_id' => $performer->id], $request);
    }

    /**
     * O membro REVOGA um acesso OU RECUSA um pedido — o mesmo verbo, porque os
     * dois são "essa linha deixa de existir". Idempotente: apagar o que já saiu é
     * no-op. Age só sobre as próprias linhas do membro.
     */
    public function revoke(User $member, PerformerProfile $performer, ?Request $request = null): void
    {
        MemberGalleryAccess::where('member_id', $member->id)
            ->where('performer_profile_id', $performer->id)
            ->delete();

        Audit::log('member_gallery.access_revoked', $member, ['performer_profile_id' => $performer->id], $request);
    }

    /**
     * A performer (viewer) tem acesso LIBERADO às privadas deste membro? Predicado
     * de serving — o MemberGalleryService lê daqui, e o presenter também, para as
     * duas concordarem. Viewer sem perfil de performer (ou deslogado) → false.
     */
    public function hasGrant(User $member, ?User $viewer): bool
    {
        $performerProfileId = $viewer?->performerProfile?->id;

        if ($performerProfileId === null) {
            return false;
        }

        return MemberGalleryAccess::where('member_id', $member->id)
            ->where('performer_profile_id', $performerProfileId)
            ->granted()
            ->exists();
    }

    /**
     * Estado do acesso desta performer às privadas do membro, para o botão do
     * perfil: 'none' | 'pending' | 'granted'. Uma query.
     */
    public function stateFor(User $member, ?User $viewer): string
    {
        $performerProfileId = $viewer?->performerProfile?->id;

        if ($performerProfileId === null) {
            return 'none';
        }

        $row = MemberGalleryAccess::where('member_id', $member->id)
            ->where('performer_profile_id', $performerProfileId)
            ->first(['granted_at']);

        if ($row === null) {
            return 'none';
        }

        return $row->granted_at !== null ? 'granted' : 'pending';
    }

    /**
     * Pedidos PENDENTES para a caixa do membro (quem pediu para ver as privadas).
     * A performer é pública: nome de palco, slug e avatar. Ordena por chegada.
     *
     * @return array<int, array{performer_profile_id:int, stage_name:string, slug:string, avatar_url:?string}>
     */
    public function pendingRequestsFor(User $member): array
    {
        return $this->mapRows(
            MemberGalleryAccess::where('member_id', $member->id)->pending()->orderBy('created_at')
        );
    }

    /**
     * Performers com acesso LIBERADO (para a lista "quem tem acesso" + revogar).
     *
     * @return array<int, array{performer_profile_id:int, stage_name:string, slug:string, avatar_url:?string}>
     */
    public function grantedFor(User $member): array
    {
        return $this->mapRows(
            MemberGalleryAccess::where('member_id', $member->id)->granted()->orderByDesc('granted_at')
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<MemberGalleryAccess>  $query
     * @return array<int, array{performer_profile_id:int, stage_name:string, slug:string, avatar_url:?string}>
     */
    private function mapRows($query): array
    {
        return $query->with('performerProfile:id,stage_name,slug,avatar_path')
            ->get()
            ->filter(fn (MemberGalleryAccess $row) => $row->performerProfile !== null)
            ->map(fn (MemberGalleryAccess $row) => [
                'performer_profile_id' => (int) $row->performer_profile_id,
                'stage_name' => (string) $row->performerProfile->stage_name,
                'slug' => (string) $row->performerProfile->slug,
                'avatar_url' => $this->performerAvatarUrl($row->performerProfile),
            ])
            ->values()
            ->all();
    }

    /** URL assinada do avatar da performer (mesma do card público), ou null. */
    private function performerAvatarUrl(PerformerProfile $profile): ?string
    {
        if (! $profile->avatar_path) {
            return null;
        }

        // Chaveada no id do PERFIL, nunca no user_id (mesma disciplina do
        // PerformerPublicResource::mediaUrl).
        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $profile->id, 'type' => 'avatar'],
        );
    }

    private function memberHasPrivatePhotos(User $member): bool
    {
        return MemberGalleryPhoto::where('user_id', $member->id)
            ->approved()
            ->where('is_private', true)
            ->exists();
    }
}
