<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Boost pago (Sprint 11): o débito dos tokens do destaque é uma linha nova no
 * `token_ledger` com `entry_type = 'spend_boost'` — append-only, nunca UPDATE de
 * saldo (princípio nº 2 do CLAUDE.md). Este passo só abre o valor no enum; quem
 * grava é TokenService::debit, via BoostService.
 *
 * Receita indireta da plataforma: a performer precisa COMPRAR tokens (PIX) para
 * ter o que gastar aqui. O boost não credita ninguém — é 100% plataforma, como o
 * desbloqueio de Interesse (spend_interest_unlock).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite não tem ENUM — a coluna é TEXT e aceita qualquer valor; nada a
        // alterar lá. Só o MySQL precisa do novo valor no tipo.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // O MySQL recusa remover um valor de enum ainda em uso — remapeia
            // primeiro (mesmo cuidado das migrations irmãs).
            DB::table('token_ledger')
                ->where('entry_type', 'spend_boost')
                ->update(['entry_type' => 'adjustment']);

            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit') NOT NULL");
        }
    }
};
