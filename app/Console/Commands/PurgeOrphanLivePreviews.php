<?php

namespace App\Console\Commands;

use App\Services\LivePreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * GC dos frames de preview de live órfãos (Sprint 15, PR #143). O frame morre no
 * fim da live (stop/ban, via LiveSessionService::endSession) e na reconciliação
 * na leitura de uma live abandonada. Isto é a rede de segurança para o que
 * escapar: um frame sem novo quadro há mais de 1h é de uma live que encerrou sem
 * passar por nenhum dos dois caminhos. Uma live viva sobrescreve a cada 10s, então
 * nunca é alcançada. Precedente `stories:purge`/`calls:reap-stale`.
 */
class PurgeOrphanLivePreviews extends Command
{
    protected $signature = 'live-previews:purge';

    protected $description = 'Apaga frames de preview de live órfãos (sem novo quadro há mais de 1h).';

    public function handle(LivePreviewService $previews): int
    {
        $deleted = $previews->purgeOrphans();

        $this->info("deleted={$deleted}");
        Log::info('live-previews:purge', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
