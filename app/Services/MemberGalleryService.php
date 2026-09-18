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

    /** Disco privado, o mesmo do avatar do membro. */
    public const DISK = 'local';

    public function __construct(
        private ImageProcessingService $imageProcessor,
        private CsamScanService $csam,
    ) {}

    /**
     * Sanitiza, escaneia (anti-CSAM) e grava uma nova foto `pending`. Recusa
     * (MemberGalleryException) sem gravar nada se o membro já tem 4 ativas; recusa
     * (CsamDetectedException) sem gravar em caso de match — o gate roda ANTES de
     * qualquer escrita em disco. A primeira foto do membro não vira principal aqui:
     * `is_primary` só é designada entre APPROVED (na aprovação/setPrimary).
     */
    public function add(User $user, UploadedFile $file, ?Request $request = null): MemberGalleryPhoto
    {
        // Teto de slots ativos. Rejeitada não conta (o membro pode reenviar).
        if (MemberGalleryPhoto::where('user_id', $user->id)->active()->count() >= MemberGalleryPhoto::MAX_ACTIVE) {
            throw MemberGalleryException::limitReached();
        }

        $clean = $this->imageProcessor->process($file, self::MAX_DIMENSION, self::MAX_DIMENSION, crop: false);

        // Caminho imprevisível (nome aleatório), nunca do cliente.
        $path = "member-gallery/{$user->id}/".Str::random(40).'.jpg';

        try {
            $bytes = file_get_contents($clean);

            // Anti-CSAM: mesmo gate dos outros caminhos de imagem. Match → exceção
            // antes de qualquer gravação.
            $this->csam->scanBytes($bytes, 'member_gallery', $user);

            Storage::disk(self::DISK)->put($path, $bytes);
            $hash = hash('sha256', $bytes);
        } finally {
            @unlink($clean);
        }

        // path/token/content_hash fora do $fillable — atribuição direta no serviço,
        // nunca payload. Token novo por foto: a URL assinada é chaveada nele.
        $photo = new MemberGalleryPhoto;
        $photo->user_id = $user->id;
        $photo->path = $path;
        $photo->token = Str::random(48);
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

        if ($photo->path !== '') {
            Storage::disk(self::DISK)->delete($photo->path);
        }

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

        if ($photo->path !== '') {
            Storage::disk(self::DISK)->delete($photo->path);
        }

        $wasPrimary = $photo->is_primary;

        $photo->forceFill([
            'status' => MemberGalleryPhoto::STATUS_REJECTED,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'reject_reason' => $reason,
            'is_primary' => false,
            'path' => '',
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
    public function approvedFor(User $user): Collection
    {
        return MemberGalleryPhoto::where('user_id', $user->id)
            ->approved()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (MemberGalleryPhoto $photo) => [
                'id' => $photo->id,
                'is_primary' => $photo->is_primary,
                'url' => $photo->mediaUrl(),
            ]);
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
}
