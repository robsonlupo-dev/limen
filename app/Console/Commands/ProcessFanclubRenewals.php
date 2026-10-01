<?php

namespace App\Console\Commands;

use App\Services\FanclubService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Motor de tempo do Fã-Clube (Onda 4 — fork da assinatura). Roda periodicamente e, para
 * cada assinatura ativa VENCIDA: encerra canceladas, renova cobrando o saldo de token,
 * entra em carência quando falta token (avisa-e-pausa) e pausa quando a carência vence.
 * Idempotente por linha (a renovação empurra o período; reprocessar não recobra).
 */
class ProcessFanclubRenewals extends Command
{
    protected $signature = 'fanclub:process';

    protected $description = 'Renova/pausa/encerra assinaturas de fã-clube vencidas (cobrança em token).';

    public function handle(FanclubService $fanclub): int
    {
        $c = $fanclub->processRenewals();

        $this->info("renewed={$c['renewed']} graced={$c['graced']} paused={$c['paused']} cancelled={$c['cancelled']} skipped={$c['skipped']}");
        Log::info('fanclub:process', $c);

        return self::SUCCESS;
    }
}
