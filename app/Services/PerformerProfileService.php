<?php

namespace App\Services;

use App\Models\PerformerProfile;
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

        if ($newName !== null && $newName !== $profile->stage_name) {
            // O slug carrega o nome artístico. Mantê-lo após um rename deixaria
            // o nome antigo público na URL para sempre, que é exatamente o que
            // quem troca de identidade quer descartar. O custo assumido é que
            // links antigos passam a dar 404; follows e interesses não quebram
            // porque referenciam o id.
            $data['slug'] = PerformerProfile::generateSlug($newName);
        } elseif (! $profile->slug) {
            $data['slug'] = PerformerProfile::generateSlug($newName ?? $profile->stage_name);
        }

        $profile->update($data);

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
            'avatar.' . $file->extension(),
            'local',
        );

        $profile->update(['avatar_path' => $path]);

        return $path;
    }
}
