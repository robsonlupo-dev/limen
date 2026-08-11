<?php

namespace App\Console\Commands;

use App\Services\VoiceIntroStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GC dos uploads de áudio CRUS órfãos (feat/voice-intro). O ProcessVoiceIntro
 * apaga o cru no finally/failed/early-return, mas um job DESCARTADO sem executar
 * nem falhar (worker morto entre reserva e handle, flush de fila) deixa o arquivo
 * em `tmp/{profile}/…` órfão. Sem varredura, o disco enche em silêncio. Precedente:
 * content:purge-orphan-raw / stories:purge / otp:purge.
 *
 * O cru é privado (disco `serve => false`) e nunca servido: isto é higiene de
 * disco, não de vazamento.
 */
class PurgeOrphanVoiceIntro extends Command
{
    protected $signature = 'voice:purge-orphan-raw';

    protected $description = 'Apaga uploads de áudio crus órfãos (tmp/) além do prazo do job.';

    public function handle(): int
    {
        $disk = Storage::disk(VoiceIntroStore::DISK);

        // O diretório tmp/ só existe depois do primeiro upload. Listar um path
        // inexistente estoura no Flysystem — sai cedo (lição do live-previews).
        if (! $disk->directoryExists('tmp')) {
            return self::SUCCESS;
        }

        // Órfão = mais velho que 2× o timeout do job (folga para o re-encode em voo
        // não ser varrido no meio).
        $cutoff = now()->getTimestamp() - ((int) config('voice.process_timeout') * 2);
        $deleted = 0;

        foreach ($disk->allFiles('tmp') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        Log::info('voice:purge-orphan-raw', ['deleted' => $deleted]);
        $this->info("Removidos {$deleted} áudio(s) cru(s) órfão(s).");

        return self::SUCCESS;
    }
}
