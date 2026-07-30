<?php

namespace App\Console\Commands;

use App\Services\PerformerStoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Garbage collection dos stories vencidos.
 *
 * **Não é o mecanismo de expiração** (docs/SECURITY_ISSUES.md § 2.8, a mesma
 * inversão do § 1.3). Quem nega o story vencido é a LEITURA — o escopo
 * `PerformerStory::active()` e o `isExpired()`. Se o corte dependesse deste
 * comando, um job parado não custaria disco: custaria a promessa de 24h que o
 * produto vende. Aqui só se recolhem os bytes que ninguém mais pode ver.
 *
 * O agravante que é só desta feature: um story pode ter sido DENUNCIADO. Se o
 * job apagasse na hora 24 uma denúncia entrada na hora 23, a evidência sumiria —
 * por isso o service congela o GC do story denunciado (§ 2.4, parte 2) e o
 * comando reporta `quarantined` separado de `stale`.
 *
 * Comando e não Job enfileirado, no padrão de `member-photos:purge` e
 * `visits:purge`: os dois Jobs do repo são de e-mail, e a varredura é trabalho
 * agendado, não reação a evento.
 */
class DeleteExpiredStories extends Command
{
    protected $signature = 'stories:purge';

    protected $description = 'Apaga do disco os stories vencidos e as views deles.';

    public function handle(PerformerStoryService $stories): int
    {
        $counts = $stories->purgeExpired();

        $this->info(sprintf(
            'expired=%d deleted=%d quarantined=%d stale=%d failed=%d',
            $counts['expired'],
            $counts['deleted'],
            $counts['quarantined'],
            $counts['stale'],
            $counts['failed'],
        ));

        // Só contadores. Quem viu o story de quem é justamente o que está sendo
        // apagado — não é para reaparecer no log (princípio 4 do CLAUDE.md, mesma
        // disciplina de `member-photos:purge` e `visits:purge`).
        Log::info('stories:purge', $counts);

        // O alarme. `stale` são stories que já estavam vencidos na rodada
        // ANTERIOR e ainda têm arquivo no disco: em operação normal é zero, porque
        // cada rodada limpa o que venceu na sua hora. Persistente e diferente de
        // zero significa GC sem conseguir apagar — hoje ninguém é notificado
        // disso, então o warning é o que resta até existir alerta de job no
        // projeto.
        //
        // `quarantined` NÃO entra aqui: story congelado por denúncia é vencido e
        // presente por DESIGN, e alarmar sobre ele treinaria quem lê o log a
        // ignorar o alarme.
        if ($counts['stale'] > 0 || $counts['failed'] > 0) {
            Log::warning('stories:purge encontrou stories vencidos ainda no disco', $counts);
        }

        return self::SUCCESS;
    }
}
