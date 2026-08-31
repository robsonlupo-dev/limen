<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto de perfil do MEMBRO (fix/member-photo-and-crop).
 *
 * NÃO é um caminho paralelo de upload: reusa o MESMO pipeline endurecido da
 * performer — ImageProcessingService (guarda de imagem-bomba lendo o header antes
 * de decodificar, strip de EXIF/GPS, re-encode que mata polyglot, crop 1:1
 * FORÇADO no servidor) e CsamScanService (phash anti-CSAM ANTES de gravar). É a
 * gêmea de PerformerProfileService::replaceAvatar; a única diferença é a entidade
 * (User em vez de PerformerProfile) e o disco de destino.
 *
 * PRIVACIDADE: a foto é servida à performer no catálogo (decisão do PO), mas por
 * URL chaveada no `avatar_token` OPACO — nunca o user_id, que o FanAlias esconde.
 * O token rotaciona a cada upload, então URLs assinadas antigas morrem.
 */
class MemberAvatarService
{
    /** 1:1, mesmo lado do avatar da performer. */
    public const AVATAR_SIZE = 512;

    public function __construct(
        private ImageProcessingService $imageProcessor,
        private CsamScanService $csam,
    ) {}

    /**
     * Sanitiza, escaneia e grava a nova foto; devolve o caminho no disco.
     * Bloqueia (CsamDetectedException) sem gravar nada em caso de match, e sem
     * destruir a foto anterior — igual ao caminho da performer.
     */
    public function replace(User $user, UploadedFile $file, ?Request $request = null): string
    {
        $clean = $this->imageProcessor->process($file, self::AVATAR_SIZE, self::AVATAR_SIZE, crop: true);

        $path = "member-media/{$user->id}/avatar.jpg";

        try {
            $bytes = file_get_contents($clean);

            // Anti-CSAM: mesmo gate dos 6 caminhos de imagem. Match → exceção
            // antes de qualquer gravação; escaneia ANTES de apagar o avatar
            // anterior, para um upload bloqueado não destruir o que já estava no ar.
            $this->csam->scanBytes($bytes, 'member_avatar', $user);

            if ($user->avatar_path && $user->avatar_path !== $path) {
                Storage::disk('local')->delete($user->avatar_path);
            }

            Storage::disk('local')->put($path, $bytes);
        } finally {
            @unlink($clean);
        }

        // avatar_path e avatar_token estão fora do $fillable — forceFill, nunca
        // payload. Token novo a cada upload: as URLs assinadas da foto antiga
        // deixam de resolver.
        $user->forceFill([
            'avatar_path' => $path,
            'avatar_token' => Str::random(48),
        ])->save();

        // Audit sem bytes/caminho — só o fato. Mesma disciplina do resto.
        Audit::log('member_avatar_updated', $user, [], $request);

        return $path;
    }

    /** Remove a foto: apaga os bytes e zera path/token. Idempotente. */
    public function remove(User $user, ?Request $request = null): void
    {
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }

        $user->forceFill([
            'avatar_path' => null,
            'avatar_token' => null,
        ])->save();

        Audit::log('member_avatar_removed', $user, [], $request);
    }
}
