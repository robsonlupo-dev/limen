<?php

namespace App\Console\Commands;

use App\Services\ProfileVisitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Expurgo de `profile_visits` por retenção.
 *
 * O painel da performer consome 24h. Guardar as visitas para sempre seria
 * manter o mapa de interesses de cada membro ativo sem finalidade que o
 * justifique — o oposto do princípio da necessidade da LGPD, e o dado mais
 * sensível a aparecer num dump. Ver ProfileVisitService::RETENTION_DAYS.
 */
class PurgeExpiredProfileVisits extends Command
{
    protected $signature = 'visits:purge';

    protected $description = 'Apaga visitas a perfis fora da janela de retenção.';

    public function handle(ProfileVisitService $visits): int
    {
        $deleted = $visits->purgeExpired();

        $this->info("deleted={$deleted}");

        // Só a contagem: quem visitou quem é justamente o que estamos apagando.
        Log::info('visits:purge', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
