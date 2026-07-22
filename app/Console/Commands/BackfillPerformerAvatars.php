<?php

namespace App\Console\Commands;

use App\Models\PerformerProfile;
use App\Support\AvatarPlaceholder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Repara avatares de performer cujo arquivo não existe no disco (S1.4).
 *
 * Sintoma: GET /api/v1/performer-media retorna 404 e os cards do catálogo
 * ficam sem imagem. Causa raiz: o banco tem `avatar_path` apontando para um
 * arquivo que não está em storage/app/private (ex.: DB populado num ambiente,
 * mídia não carregada para o servidor). Este comando regenera o placeholder
 * SVG padrão para todo profile com path nulo OU arquivo ausente, e atualiza o
 * registro. Idempotente: profiles com avatar íntegro são ignorados.
 */
class BackfillPerformerAvatars extends Command
{
    protected $signature = 'performers:backfill-avatars {--disk=local : Disco onde a mídia privada vive}';

    protected $description = 'Regenera avatares de performer ausentes no disco (corrige 404 em performer-media)';

    public function handle(): int
    {
        $disk = $this->option('disk');
        $repaired = 0;
        $ok = 0;

        PerformerProfile::query()->chunkById(100, function ($profiles) use ($disk, &$repaired, &$ok) {
            foreach ($profiles as $profile) {
                if ($profile->avatar_path && Storage::disk($disk)->exists($profile->avatar_path)) {
                    $ok++;

                    continue;
                }

                $path = AvatarPlaceholder::store($disk, $profile->user_id, $profile->stage_name);
                $profile->update(['avatar_path' => $path]);
                $repaired++;
            }
        });

        $this->info("Avatares OK: {$ok} · reparados: {$repaired} (disco: {$disk}).");

        return self::SUCCESS;
    }
}
