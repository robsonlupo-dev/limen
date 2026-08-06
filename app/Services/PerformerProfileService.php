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
        // O caminho depende da extensão, então trocar jpg→png deixaria o antigo
        // órfão no disco se não apagássemos aqui.
        if ($profile->avatar_path) {
            Storage::disk('local')->delete($profile->avatar_path);
        }

        $path = $file->storeAs(
            "performer-media/{$profile->user_id}",
            'avatar.'.$file->extension(),
            'local',
        );

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

        $path = $file->storeAs(
            "performer-media/{$profile->user_id}",
            'cover.'.$file->extension(),
            'local',
        );

        $profile->update(['cover_path' => $path]);

        return $path;
    }
}
