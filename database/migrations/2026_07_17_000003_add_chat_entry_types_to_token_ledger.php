<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cobrança de ACESSO ao chat (COMMUNICATION_ECONOMY §2): o membro sem
     * Círculo paga um acesso por performer (spend_chat_access); a performer
     * recebe o split (chat_access_credit). Sempre via ledger append-only —
     * nunca UPDATE saldo (princípio nº 2 do CLAUDE.md).
     */
    public function up(): void
    {
        // SQLite has no ENUM — values are TEXT, so no ALTER needed there.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL refuses to drop an enum value still in use; remap first.
            DB::table('token_ledger')
                ->whereIn('entry_type', ['spend_chat_access', 'chat_access_credit'])
                ->update(['entry_type' => 'adjustment']);

            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant') NOT NULL");
        }
    }
};
