<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Live pública e chamada privada (Sprint 15, M.13.6): gastos do membro
     * (spend_live/spend_call) e créditos da performer (live_credit/call_credit,
     * split 70/30 com applied_rate congelado). Princípio nº 2: cada tipo novo é
     * migration no enum, nunca UPDATE de saldo.
     *
     * live_credit/call_credit são *_credit → NUNCA respeitam teto (M.13.9); FORA
     * de cap_respecting_entry_types. Entram em payout.earning_entry_types (ganho
     * sacável, como content_credit/gift_credit) — feito em config/monetization.php,
     * não aqui. NENHUM débito/crédito é EMITIDO neste PR: só o enum abre os valores;
     * a cobrança por minuto/bloco é o PR de serving.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit') NOT NULL");
        }
    }
};
