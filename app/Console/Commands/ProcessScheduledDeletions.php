<?php

namespace App\Console\Commands;

use App\Services\DeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executa as exclusões cuja carência de 30 dias venceu (LGPD art. 18, VI).
 *
 * Um usuário que falha NÃO derruba o lote: o caso mais comum é o payout que
 * entrou na fila depois do pedido, e travar a varredura nele adiaria
 * indefinidamente a exclusão de todos os outros — cada dia de atraso é
 * descumprimento do prazo legal para os demais titulares.
 */
class ProcessScheduledDeletions extends Command
{
    protected $signature = 'deletions:process';

    protected $description = 'Executa as exclusões de conta cuja carência de 30 dias venceu (LGPD).';

    public function handle(DeletionService $deletion): int
    {
        $due = $deletion->dueForDeletion();

        $executed = 0;
        $skipped = 0;

        foreach ($due as $user) {
            try {
                $deletion->executeDeletion($user);
                $executed++;
            } catch (Throwable $e) {
                $skipped++;
                // Classe da exceção, nunca getMessage(): uma QueryException traz
                // o SQL com os valores bindados junto — ou seja, exatamente a
                // PII do titular, despejada na ferramenta de observabilidade que
                // é o último lugar onde ela pode reaparecer depois de ele ter
                // pedido para sumir. O user_id sozinho basta para investigar.
                Log::warning('deletions:process skipped a user', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                ]);
            }
        }

        $this->info("executed={$executed} skipped={$skipped}");

        Log::info('deletions:process', ['executed' => $executed, 'skipped' => $skipped]);

        return self::SUCCESS;
    }
}
