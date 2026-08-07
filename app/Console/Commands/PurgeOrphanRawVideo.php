<?php

namespace App\Console\Commands;

use App\Services\ContentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GC dos uploads de vídeo CRUS órfãos (Sprint 16). O ProcessVideoContent apaga o
 * cru no finally/failed/early-return, mas um job DESCARTADO sem executar nem
 * falhar (worker morto entre reserva e handle, flush de fila) deixa o arquivo em
 * `tmp/{profile}/…` — até 500 MB — órfão. Sem varredura, o disco enche em
 * silêncio. Precedente: live-previews:purge / stories:purge / otp:purge.
 *
 * O cru é privado (disco `serve => false`) e nunca servido: isto é higiene de
 * disco, não de vazamento.
 */
class PurgeOrphanRawVideo extends Command
{
    protected $signature = 'content:purge-orphan-raw';

    protected $description = 'Apaga uploads de vídeo crus órfãos (tmp/) além do prazo do job.';

    public function handle(): int
    {
        $disk = Storage::disk(ContentStore::DISK);

        // O diretório tmp/ só existe depois do primeiro upload de vídeo. Listar um
        // path inexistente estoura no Flysystem — sai cedo (lição do live-previews).
        if (! $disk->directoryExists('tmp')) {
            return self::SUCCESS;
        }

        // Órfão = mais velho que 2× o timeout do job (folga para o re-encode em voo
        // não ser varrido no meio).
        $cutoff = now()->getTimestamp() - ((int) config('video.process_timeout') * 2);
        $deleted = 0;

        foreach ($disk->allFiles('tmp') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        Log::info('content:purge-orphan-raw', ['deleted' => $deleted]);
        $this->info("Removidos {$deleted} vídeo(s) cru(s) órfão(s).");

        return self::SUCCESS;
    }
}
