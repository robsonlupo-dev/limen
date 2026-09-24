<?php

namespace App\Services;

use App\Exceptions\MemberGalleryException;
use App\Jobs\SendMemberPhotoRejectedEmail;
use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ciclo de vida da galeria de perfil do MEMBRO (feat/member-gallery-and-profile):
 * subir, remover, designar principal, ligar/desligar o perfil visível, e a
 * MODERAÇÃO (aprovar/recusar). Até 4 fotos ATIVAS (pending+approved) por membro.
 *
 * NÃO é um caminho paralelo de upload: reusa o MESMO pipeline endurecido do avatar
 * — ImageProcessingService (guarda de imagem-bomba, strip de EXIF/GPS, re-encode
 * que mata polyglot) e CsamScanService (phash anti-CSAM ANTES de gravar). A
 * diferença é que a foto de galeria NÃO vai ao ar direto: nasce `pending` e um
 * humano libera (mesmo gate da intro de voz da performer) — porque é rosto de
 * usuário em site adulto (CSAM, foto de terceiro sem consentimento).
 */
class MemberGalleryService
{
    /**
     * Lado máximo (px). Sem crop: a galeria preserva a proporção da foto (retrato
     * ou paisagem), ao contrário do avatar 1:1. scaleDown só REDUZ.
     */
    public const MAX_DIMENSION = 1280;

    /**
     * Variante ENQUADRADA (card/miniatura), proporção 3:4 (feat/member-profile-v2).
     * É o que o membro recortou no ImageCropper; o servidor sanitiza esse recorte,
     * ou — se o cliente não mandou recorte — recorta o original no centro (cover)
     * para a mesma proporção, para o card nunca ficar sem enquadramento.
     */
    public const CARD_WIDTH = 720;

    public const CARD_HEIGHT = 960;

    /** Disco privado, o mesmo do avatar do membro. */
    public const DISK = 'local';

    public function __construct(
        private ImageProcessingService $imageProcessor,
        private CsamScanService $csam,
        private MemberGalleryAccessService $access,
    ) {}

    /**
     * Sanitiza, escaneia (anti-CSAM) e grava uma nova foto `pending`. Recusa
     * (MemberGalleryException) sem gravar nada se o membro já tem 4 ativas; recusa
     * (CsamDetectedException) sem gravar em caso de match — o gate roda ANTES de
     * qualquer escrita em disco. A primeira foto do membro não vira principal aqui:
     * `is_primary` só é designada entre APPROVED (na aprovação/setPrimary).
     */
    public function add(User $user, UploadedFile $file, ?UploadedFile $cropped = null, ?Request $request = null): MemberGalleryPhoto
    {
        // Teto de slots ativos. Rejeitada não conta (o membro pode reenviar).
        if (MemberGalleryPhoto::where('user_id', $user->id)->active()->count() >= MemberGalleryPhoto::MAX_ACTIVE) {
            throw MemberGalleryException::limitReached();
        }

        // Variante COMPLETA (sem corte) — o lightbox do perfil. scaleDown preserva
        // a proporção original.
        $fullClean = $this->imageProcessor->process($file, self::MAX_DIMENSION, self::MAX_DIMENSION, crop: false);

        // Variante ENQUADRADA (3:4) — card/miniatura. Se o cliente mandou o recorte
        // do ImageCropper, sanitiza ESSE recorte (já vem 3:4, então crop:false só
        // reduz+sanitiza — "o que ele confirma é o que aparece"). Sem recorte
        // (JS desligado, API futura), recorta o ORIGINAL no servidor (cover 3:4)
        // — o corte definitivo nunca depende do cliente.
        $cardClean = $cropped !== null
            ? $this->imageProcessor->process($cropped, self::CARD_WIDTH, self::CARD_HEIGHT, crop: false)
            : $this->imageProcessor->process($file, self::CARD_WIDTH, self::CARD_HEIGHT, crop: true);

        // Caminhos imprevisíveis (nome aleatório), nunca do cliente.
        $path = "member-gallery/{$user->id}/".Str::random(40).'.jpg';
        $fullPath = "member-gallery/{$user->id}/".Str::random(40).'.jpg';

        try {
            $cardBytes = file_get_contents($cardClean);
            $fullBytes = file_get_contents($fullClean);

            // Anti-CSAM nas DUAS variantes, ANTES de qualquer gravação. Match em
            // qualquer uma → exceção, nada em disco (o recorte poderia esconder ou
            // revelar o que o original não tinha — as duas passam pelo gate).
            $this->csam->scanBytes($cardBytes, 'member_gallery', $user);
            $this->csam->scanBytes($fullBytes, 'member_gallery', $user);

            Storage::disk(self::DISK)->put($path, $cardBytes);
            Storage::disk(self::DISK)->put($fullPath, $fullBytes);
            $hash = hash('sha256', $cardBytes);
        } finally {
            @unlink($cardClean);
            @unlink($fullClean);
        }

        // path/token/full_*/content_hash fora do $fillable — atribuição direta no
        // serviço, nunca payload. Token novo por VARIANTE: cada URL assinada é
        // chaveada no seu próprio token opaco.
        $photo = new MemberGalleryPhoto;
        $photo->user_id = $user->id;
        $photo->path = $path;
        $photo->token = Str::random(48);
        $photo->full_path = $fullPath;
        $photo->full_token = Str::random(48);
        $photo->status = MemberGalleryPhoto::STATUS_PENDING;
        $photo->content_hash = $hash;
        $photo->save();

        Audit::log('member_gallery.uploaded', $photo, ['id' => $photo->id], $request);

        return $photo;
    }

    /**
     * Remove uma foto do membro: apaga os BYTES na hora (revogação imediata) e a
     * linha. Se era a principal, reatribui a principal a outra foto aprovada.
     * Idempotente-por-dono: aborta 404 se a foto não é do usuário.
     */
    public function remove(User $user, MemberGalleryPhoto $photo, ?Request $request = null): void
    {
        $this->assertOwned($user, $photo);

        // Purga AS DUAS variantes (enquadrada + completa) na hora.
        $this->purgeFiles($photo);

        $wasPrimary = $photo->is_primary;
        $photo->delete();

        if ($wasPrimary) {
            $this->promoteNextPrimary($user);
        }

        Audit::log('member_gallery.removed', $photo, ['id' => $photo->id], $request);
    }

    /**
     * Designa a foto PRINCIPAL (thumbnail do card). Só faz sentido sobre uma
     * APPROVED e do próprio membro; zera a principal anterior na mesma transação.
     */
    public function setPrimary(User $user, MemberGalleryPhoto $photo, ?Request $request = null): void
    {
        $this->assertOwned($user, $photo);

        // Só aprovada pode ser exibida — logo, só aprovada vira principal.
        abort_unless($photo->isApproved(), 422);

        // Foto PRIVADA não vira principal: a principal é a cara PÚBLICA do card
        // (feat/member-gallery-per-photo-privacy). Destranque antes — o botão do
        // front já some para foto privada; esta trava fecha a porta pela API.
        abort_if($photo->is_private, 422);

        DB::transaction(function () use ($user, $photo) {
            MemberGalleryPhoto::where('user_id', $user->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $photo->forceFill(['is_primary' => true])->save();
        });

        Audit::log('member_gallery.primary_set', $photo, ['id' => $photo->id], $request);
    }

    /**
     * Liga/desliga o PERFIL VISÍVEL do membro (opt-in mestre da galeria/perfil).
     * `profile_visible` está fora do $fillable — forceFill por aqui, nunca payload.
     * Desligar é revogação imediata do ACESSO (as URLs deixam de ser entregues e a
     * página de perfil passa a 404), mas NÃO purga as fotos: o interruptor é
     * reversível. Purga só na remoção da foto ou no encerramento da conta.
     */
    public function setVisibility(User $user, bool $visible, ?Request $request = null): void
    {
        $user->forceFill(['profile_visible' => $visible])->save();

        Audit::log('member_gallery.visibility_updated', $user, ['visible' => $visible], $request);
    }

    /**
     * Alterna a visibilidade de UMA foto (aberta ↔ privada) —
     * feat/member-gallery-per-photo-privacy. Só o dono (assertOwned → 404
     * indistinguível de inexistente, como remove/setPrimary). `is_private` está
     * fora do $fillable: forceFill, nunca payload. Recusada não tem o que trancar
     * (bytes purgados) → 404.
     */
    public function setPhotoVisibility(User $user, MemberGalleryPhoto $photo, bool $isPrivate, ?Request $request = null): void
    {
        $this->assertOwned($user, $photo);

        abort_if($photo->status === MemberGalleryPhoto::STATUS_REJECTED, 404);

        $photo->forceFill(['is_private' => $isPrivate])->save();

        Audit::log('member_gallery.photo_visibility_updated', $photo, ['id' => $photo->id, 'is_private' => $isPrivate], $request);
    }

    /**
     * Predicado ÚNICO de "estes bytes saem para este espectador" — a dona única
     * da regra de serving (feat/member-gallery-per-photo-privacy). Lido pelo
     * MemberGalleryMediaController; a mesma disciplina do PhotoAccessService da
     * performer (as duas pontas não podem divergir, senão vira oráculo):
     *  - o DONO vê a própria em qualquer status (preview da gestão);
     *  - qualquer outro só recebe bytes de foto APROVADA, de dono com o perfil
     *    visível, e PÚBLICA. Privada = só o dono (na Etapa 2 entram os grants por
     *    performer; até lá, ninguém além do dono).
     */
    /**
     * O membro tem ao menos uma foto PRIVADA aprovada? A performer só vê o botão
     * "Solicitar acesso" quando há o que solicitar (feat/member-gallery-access-
     * requests). Uma query (exists).
     */
    public function hasPrivatePhotos(User $user): bool
    {
        return MemberGalleryPhoto::where('user_id', $user->id)
            ->approved()
            ->where('is_private', true)
            ->exists();
    }

    public function canServeToViewer(MemberGalleryPhoto $photo, ?User $viewer): bool
    {
        if ($viewer !== null && $viewer->id === $photo->user_id) {
            return true;
        }

        if (! $photo->isApproved() || ! (bool) $photo->user?->profile_visible) {
            return false;
        }

        // Pública: qualquer performer que vê o perfil. Privada: só quem o membro
        // LIBEROU (feat/member-gallery-access-requests, Etapa 2) — o predicado do
        // par mora no MemberGalleryAccessService, lido também pelo presenter.
        return $photo->isPublic()
            || ($photo->user !== null && $this->access->hasGrant($photo->user, $viewer));
    }

    /**
     * Moderação: aprova a foto. Só sobre PENDING (a fila só mostra pending);
     * no-op fora disso. Se o membro ainda não tem principal aprovada, esta vira a
     * principal (para o card ter thumbnail assim que a primeira for liberada).
     */
    public function approve(MemberGalleryPhoto $photo, User $moderator): void
    {
        if (! $photo->isPending()) {
            return;
        }

        // Primeira aprovada do membro → principal automática (o card já ganha
        // thumbnail assim que a primeira for liberada).
        $hasPrimary = MemberGalleryPhoto::where('user_id', $photo->user_id)
            ->where('id', '!=', $photo->id)
            ->where('is_primary', true)
            ->exists();

        $photo->forceFill([
            'status' => MemberGalleryPhoto::STATUS_APPROVED,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'reject_reason' => null,
            'is_primary' => ! $hasPrimary,
        ])->save();

        Audit::log('member_gallery.approved', $photo, ['moderator_id' => $moderator->id]);
    }

    /**
     * Moderação: recusa a foto (com motivo). Ao contrário da intro de voz, os
     * BYTES SÃO PURGADOS na hora: manter no disco imagem de usuário recusada
     * (foto de terceiro, não-consentida) é risco de privacidade que não se
     * justifica — o membro sabe o que subiu; a linha fica só com o motivo, para
     * ele ver e reenviar. Se era principal, reatribui.
     */
    public function reject(MemberGalleryPhoto $photo, User $moderator, ?string $reason = null): void
    {
        if (! $photo->isPending()) {
            return;
        }

        // Purga AS DUAS variantes (enquadrada + completa) — imagem de usuário
        // recusada não fica no disco em nenhuma forma.
        $this->purgeFiles($photo);

        $wasPrimary = $photo->is_primary;

        $photo->forceFill([
            'status' => MemberGalleryPhoto::STATUS_REJECTED,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'reject_reason' => $reason,
            'is_primary' => false,
            'path' => '',
            'full_path' => '',
        ])->save();

        if ($wasPrimary) {
            $this->promoteNextPrimary($photo->user);
        }

        Audit::log('member_gallery.rejected', $photo, ['moderator_id' => $moderator->id]);

        // Avisa o membro da recusa (com o motivo). Sem afterCommit (a moderação não
        // roda em transação; mesma convenção da intro de voz).
        $user = $photo->user;
        if ($user !== null && $user->email !== null) {
            SendMemberPhotoRejectedEmail::dispatch($user, $reason);
        }
    }

    /**
     * A galeria da PRÓPRIA tela de gestão do membro: TODAS as fotos (qualquer
     * status), com a URL de preview (o dono vê a própria pendente/aprovada). A
     * recusada some do disco (bytes purgados), então mediaUrl é null nela — a tela
     * mostra só o motivo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forOwner(User $user): array
    {
        return MemberGalleryPhoto::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (MemberGalleryPhoto $photo) => [
                'id' => $photo->id,
                'status' => $photo->status,
                'is_primary' => $photo->is_primary,
                // Nível de visibilidade (feat/member-gallery-per-photo-privacy): a
                // tela de gestão desenha o cadeado e o botão trancar/destrancar.
                'is_private' => $photo->is_private,
                'reject_reason' => $photo->status === MemberGalleryPhoto::STATUS_REJECTED ? $photo->reject_reason : null,
                'url' => $photo->mediaUrl(),
            ])
            ->all();
    }

    /**
     * As fotos APROVADAS do membro para a página de perfil vista pela performer,
     * com a principal primeiro. Só o serviço monta estas URLs — e o chamador
     * (perfil/catálogo) já garantiu profile_visible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function approvedFor(User $user, ?User $viewer = null): Collection
    {
        // A performer que vê o perfil LIBERADA às privadas deste membro? Uma query
        // só (não por foto): a liberação é por par (Etapa 2). O serving reconfere o
        // MESMO predicado (canServeToViewer), então url-vs-cadeado nunca divergem.
        $unlocked = $viewer !== null && $this->access->hasGrant($user, $viewer);

        return MemberGalleryPhoto::where('user_id', $user->id)
            ->approved()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(function (MemberGalleryPhoto $photo) use ($unlocked) {
                // Foto PRIVADA: só emite bytes se a performer foi liberada; senão
                // cadeado (url null). Na Etapa 1 não havia liberação; na 2, `unlocked`
                // abre todas as privadas do membro para esta performer.
                if ($photo->is_private && ! $unlocked) {
                    return [
                        'id' => $photo->id,
                        'is_primary' => $photo->is_primary,
                        'is_private' => true,
                        'locked' => true,
                        'url' => null,
                        'full_url' => null,
                    ];
                }

                return [
                    'id' => $photo->id,
                    'is_primary' => $photo->is_primary,
                    'is_private' => $photo->is_private,
                    'locked' => false,
                    // Enquadrada (miniatura) + completa (lightbox). full_url cai na
                    // enquadrada quando a linha é antiga (sem variante completa).
                    'url' => $photo->mediaUrl(),
                    'full_url' => $photo->fullMediaUrl() ?? $photo->mediaUrl(),
                ];
            });
    }

    /**
     * URL da foto PRINCIPAL aprovada do membro, ou null. É o thumbnail do card do
     * catálogo. Prefere a `is_primary`; senão a primeira aprovada. O chamador
     * (mask do catálogo) só a usa quando profile_visible está ON.
     */
    public function primaryApprovedUrlFor(User $user): ?string
    {
        $photo = MemberGalleryPhoto::where('user_id', $user->id)
            ->approved()
            // Só PÚBLICA vira thumbnail do catálogo: a foto privada nunca sai sem
            // pedido, então não pode virar a cara pública do card por ser a
            // principal (feat/member-gallery-per-photo-privacy).
            ->where('is_private', false)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $photo?->mediaUrl();
    }

    /**
     * Reatribui a principal à foto aprovada mais antiga do membro (menor id),
     * quando a principal atual saiu (removida/recusada). Nenhuma aprovada → sem
     * principal (o card cai no avatar/silhueta).
     */
    private function promoteNextPrimary(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $next = MemberGalleryPhoto::where('user_id', $user->id)
            ->approved()
            ->orderBy('id')
            ->first();

        $next?->forceFill(['is_primary' => true])->save();
    }

    /** A foto é do membro? Senão 404 (indistinguível de inexistente). */
    private function assertOwned(User $user, MemberGalleryPhoto $photo): void
    {
        abort_unless($photo->user_id === $user->id, 404);
    }

    /** Apaga os BYTES das duas variantes (enquadrada + completa) no disco. */
    private function purgeFiles(MemberGalleryPhoto $photo): void
    {
        foreach ([$photo->path, $photo->full_path] as $path) {
            if ($path !== '' && $path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }
}
