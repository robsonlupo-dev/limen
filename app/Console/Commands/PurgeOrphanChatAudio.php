<?php

namespace App\Console\Commands;

use App\Services\ChatAudioStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GC dos uploads de áudio CRUS órfãos do chat (feat/chat-audio-retention-gc).
 * Espelho fiel do `voice:purge-orphan-raw`: o ProcessChatAudio apaga o cru no
 * finally/failed/early-return, mas um job DESCARTADO sem executar nem falhar
 * (worker morto entre reserva e handle, flush de fila) deixa o arquivo em
 * `tmp/{conversationId}/…` órfão. Sem varredura, o disco enche em silêncio.
 *
 * O cru é privado (disco `serve => false`) e nunca servido: isto é higiene de
 * disco, não de vazamento.
 */
class PurgeOrphanChatAudio extends Command
{
    protected $signature = 'chat:purge-orphan-raw';

    protected $description = 'Apaga uploads de áudio crus órfãos do chat (tmp/) além do prazo do job.';

    public function handle(): int
    {
        $disk = Storage::disk(ChatAudioStore::DISK);

        // O diretório tmp/ só existe depois do primeiro upload; listar um path
        // inexistente estoura no Flysystem — sai cedo (lição do live-previews).
        if (! $disk->directoryExists('tmp')) {
            return self::SUCCESS;
        }

        // Órfão = mais velho que 2× o timeout do job (folga para o re-encode em voo
        // não ser varrido no meio). Reusa o mesmo timeout do pipeline de voz.
        $cutoff = now()->getTimestamp() - ((int) config('voice.process_timeout', 120) * 2);
        $deleted = 0;

        foreach ($disk->allFiles('tmp') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        Log::info('chat:purge-orphan-raw', ['deleted' => $deleted]);
        $this->info("Removidos {$deleted} áudio(s) cru(s) órfão(s) do chat.");

        return self::SUCCESS;
    }
}
