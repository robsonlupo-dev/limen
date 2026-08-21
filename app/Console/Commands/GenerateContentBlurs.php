<?php

namespace App\Console\Commands;

use App\Models\PerformerContent;
use App\Services\ContentStore;
use Illuminate\Console\Command;

/**
 * Gera a prévia borrada (feat/content-showcase, item 7) das peças PRONTAS que ainda
 * não têm uma — as publicadas antes desta feature. Best-effort por peça: uma que
 * falha (fonte ausente/indecodificável) é pulada e contada, sem parar o resto.
 *
 * `--force` regenera mesmo as que já têm blur (ex.: ao mudar os parâmetros do blur).
 */
class GenerateContentBlurs extends Command
{
    protected $signature = 'content:generate-blurs {--force : Regenera mesmo se já existir}';

    protected $description = 'Gera a prévia borrada dos tiles de conteúdo bloqueado (retroativo)';

    public function handle(ContentStore $store): int
    {
        $force = (bool) $this->option('force');
        $done = 0;
        $skipped = 0;
        $failed = 0;

        PerformerContent::query()
            ->where('status', PerformerContent::STATUS_READY)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($store, $force, &$done, &$skipped, &$failed) {
                foreach ($chunk as $content) {
                    $source = $content->isVideo() ? $content->thumbnail_path : $content->path;

                    if ($source === null) {
                        $failed++;

                        continue;
                    }

                    if (! $force && $store->exists($store->blurPathFor($source))) {
                        $skipped++;

                        continue;
                    }

                    $store->generateBlur($source) !== null ? $done++ : $failed++;
                }
            });

        $this->info("Prévias geradas: {$done} · já existentes: {$skipped} · falhas: {$failed}.");

        return self::SUCCESS;
    }
}
