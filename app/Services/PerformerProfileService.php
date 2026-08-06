<?php

namespace App\Services;

use App\Models\PerformerProfile;
use App\Models\PerformerProfilePreviousSlug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Mutações do perfil de performer, compartilhadas pelo onboarding e pela tela
 * de edição. Viver num só lugar é o que impede as duas telas de divergirem na
 * regra do slug e no descarte do avatar antigo.
 */
class PerformerProfileService
{
    /**
     * Capa da vitrine: 3:1 (banner), redimensionada no upload (UAT fase 1, R3).
     * Sem o corte, uma foto retrato de celular subia em tamanho original e
     * quebrava o layout do card/perfil no catálogo.
     */
    public const COVER_WIDTH = 1200;

    public const COVER_HEIGHT = 400;

    /**
     * Avatar: teto de exibição. Passa pelo MESMO pipeline higienizador da capa
     * (strip de EXIF/GPS + re-encode que mata polyglot + guarda de imagem-bomba).
     * O avatar é PÚBLICO e a foto de celular carrega coordenadas GPS — sem a
     * higienização, a localização da performer vazaria no arquivo servido
     * (leak de segurança, UAT R3). `scaleDown` (sem crop) só REDUZ e preserva o
     * enquadramento; o recorte circular fica com o front (object-cover).
     */
    public const AVATAR_MAX = 512;

    public function __construct(private ImageProcessingService $imageProcessor) {}

    /**
     * @param  array<string, mixed>  $data  já validado (UpdatePerformerProfileRequest)
     */
    public function update(PerformerProfile $profile, array $data): PerformerProfile
    {
        $newName = $data['stage_name'] ?? null;
        $vacatedSlug = null;

        if ($newName !== null && $newName !== $profile->stage_name) {
            // O slug carrega o nome artístico. Mantê-lo após um rename deixaria
            // o nome antigo público em toda URL NOVA, que é o que quem troca de
            // identidade quer descartar. O slug antigo é GUARDADO (não descartado)
            // só para 301-redirecionar links já em circulação — o público que
            // chega depois nunca vê o nome antigo. Follows/interesses não quebram
            // porque referenciam o id.
            $vacatedSlug = $profile->slug;
            $data['slug'] = PerformerProfile::generateSlug($newName);
        } elseif (! $profile->slug) {
            $data['slug'] = PerformerProfile::generateSlug($newName ?? $profile->stage_name);
        }

        $profile->update($data);

        // Registrado DEPOIS do update: o slug novo já está reservado (unique) e o
        // antigo entra no histórico para o redirect. insertOrIgnore porque um
        // rename de volta ao nome anterior geraria um slug diferente (sufixo
        // aleatório), então colisão real é improvável — mas idempotência primeiro.
        if ($vacatedSlug !== null && $vacatedSlug !== $data['slug']) {
            PerformerProfilePreviousSlug::query()->insertOrIgnore([
                'performer_profile_id' => $profile->id,
                'slug' => $vacatedSlug,
                'created_at' => now(),
            ]);
        }

        return $profile;
    }

    /**
     * Substitui o avatar, descartando o arquivo anterior. Fica no disco privado
     * `local` — a leitura é só por rota assinada e expirável.
     */
    public function replaceAvatar(PerformerProfile $profile, UploadedFile $file): string
    {
        if ($profile->avatar_path) {
            Storage::disk('local')->delete($profile->avatar_path);
        }

        // Higieniza ANTES de guardar (UAT R3): strip de EXIF/GPS + re-encode que
        // mata polyglot, com a guarda de imagem-bomba (dimensões lidas do header
        // antes de decodificar). Mesmo pipeline endurecido da capa; `scaleDown`
        // (sem crop) só reduz e preserva o enquadramento — o recorte circular é
        // do front (object-cover). Saída sempre JPEG → caminho fixo `avatar.jpg`
        // (não depende mais da extensão do upload; sobrescrever dispensa o
        // descarte por-extensão).
        $clean = $this->imageProcessor->process($file, self::AVATAR_MAX, self::AVATAR_MAX);

        $path = "performer-media/{$profile->user_id}/avatar.jpg";

        try {
            Storage::disk('local')->put($path, file_get_contents($clean));
        } finally {
            @unlink($clean);
        }

        $profile->update(['avatar_path' => $path]);

        return $path;
    }

    /**
     * Substitui a foto de capa. Gêmea de replaceAvatar — mesma disciplina de
     * disco privado (leitura só por rota assinada, como o avatar/cover que o
     * PerformerPublicResource já serve) e de descarte do arquivo anterior para
     * não deixar órfão quando a extensão muda.
     */
    public function replaceCover(PerformerProfile $profile, UploadedFile $file): string
    {
        if ($profile->cover_path) {
            Storage::disk('local')->delete($profile->cover_path);
        }

        // Redimensiona server-side para 1200x400 (3:1) ANTES de guardar (UAT R3):
        // a capa é banner de vitrine e, sem o corte, subia em tamanho original e
        // estourava o card/perfil do catálogo. Reusa o pipeline endurecido do
        // ImageProcessingService — guarda de imagem-bomba (dimensões lidas do
        // header antes de decodificar), strip de EXIF/GPS e re-encode que mata
        // polyglot —, com `crop` para a proporção exata. A saída é sempre JPEG,
        // então o caminho é fixo `cover.jpg` (não depende mais da extensão do
        // upload), o que dispensa o descarte por-extensão do avatar.
        $clean = $this->imageProcessor->process($file, self::COVER_WIDTH, self::COVER_HEIGHT, crop: true);

        $path = "performer-media/{$profile->user_id}/cover.jpg";

        try {
            Storage::disk('local')->put($path, file_get_contents($clean));
        } finally {
            @unlink($clean);
        }

        $profile->update(['cover_path' => $path]);

        return $path;
    }
}
