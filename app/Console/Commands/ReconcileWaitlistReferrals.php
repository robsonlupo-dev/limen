<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ponte pré-lançamento → go-live (feat/referral-waitlist-bridge). Varre as
 * indicações feitas na LISTA DE ESPERA e, para os pares cujos dois lados já
 * criaram conta real, grava o vínculo no sistema de indicação real — cobrindo o
 * caso em que o indicador criou a conta DEPOIS do indicado (o atalho em tempo de
 * cadastro só liga quando o indicador já tem conta). Idempotente: rode à vontade.
 *
 * Quando rodar: a partir do GO-LIVE (quando contas reais começam a existir).
 * Agende de hora em hora nos primeiros dias para fechar os pares conforme as
 * pessoas migram da lista para a conta real.
 */
class ReconcileWaitlistReferrals extends Command
{
    protected $signature = 'referral:reconcile-waitlist';

    protected $description = 'Indicação: liga as indicações da lista de espera às contas reais quando os dois lados já se cadastraram';

    public function handle(ReferralService $referrals): int
    {
        $linked = $referrals->reconcileWaitlistReferrals();

        $this->info("linked={$linked}");
        Log::info('referral:reconcile-waitlist', ['linked' => $linked]);

        return self::SUCCESS;
    }
}
