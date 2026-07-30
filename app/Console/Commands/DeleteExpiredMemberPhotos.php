<?php

namespace App\Console\Commands;

use App\Services\MemberPhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Garbage collection das fotos efêmeras vencidas.
 *
 * **Não é o mecanismo de expiração** (docs/SECURITY_ISSUES.md § 1.3). Quem nega
 * a foto vencida é a LEITURA, no MemberPhotoService — se o corte de acesso
 * dependesse deste comando, um job parado não custaria disco, custaria
 * privacidade. Aqui só se recolhem os bytes que ninguém mais pode ver.
 *
 * O precedente é ChatAccess/PurgeExpiredChatAccess: o model confere a janela no
 * acesso e o comando apenas varre.
 *
 * Comando e não Job enfileirado, no padrão de `visits:purge`: os dois Jobs do
 * repo são de e-mail, e a varredura é trabalho agendado, não reação a evento.
 */
class DeleteExpiredMemberPhotos extends Command
{
    protected $signature = 'member-photos:purge';

    protected $description = 'Apaga do disco as fotos efêmeras vencidas e os acessos delas.';

    public function handle(MemberPhotoService $photos): int
    {
        $counts = $photos->purgeExpired();

        $this->info(sprintf(
            'expired=%d deleted=%d quarantined=%d stale=%d failed=%d',
            $counts['expired'],
            $counts['deleted'],
            $counts['quarantined'],
            $counts['stale'],
            $counts['failed'],
        ));

        // Só contadores. Quem mandou foto para quem é justamente o que está
        // sendo apagado — não é para reaparecer no log (princípio 4 do CLAUDE.md,
        // mesma disciplina de `visits:purge`).
        Log::info('member-photos:purge', $counts);

        // O alarme do § 1.3, e o único sinal que hoje existe de que o GC parou.
        // `stale` são fotos que já estavam vencidas na rodada ANTERIOR e ainda
        // têm arquivo no disco: em operação normal é zero, porque cada rodada
        // limpa o que venceu na sua hora. Persistente e diferente de zero
        // significa GC sem conseguir apagar — hoje ninguém é notificado disso,
        // então o warning é o que resta até existir alerta de job no projeto.
        if ($counts['stale'] > 0 || $counts['failed'] > 0) {
            Log::warning('member-photos:purge encontrou fotos vencidas ainda no disco', $counts);
        }

        return self::SUCCESS;
    }
}
