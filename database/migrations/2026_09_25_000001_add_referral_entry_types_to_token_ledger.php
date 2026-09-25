<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Programa de indicação (feat/referral-program): bônus creditado aos dois lados
     * de uma indicação que converteu, e o estorno (clawback) se a base for revertida.
     * Princípio nº 2: cada tipo novo é migration no enum, nunca UPDATE de saldo.
     *
     * - `referral_bonus`   → crédito de BÔNUS. Entra em cap_respecting_entry_types
     *   (respeita o teto, como purchase/bonus/subscription_grant) e FICA FORA do
     *   allowlist de payout (`monetization.payout.earning_entry_types`): é
     *   NÃO-SACÁVEL por construção — nunca vira R$0,60/token no saque.
     * - `referral_bonus_reversal` → DÉBITO de clawback quando a compra/ganho de base
     *   é estornado. Nem crédito de teto nem ganho; não entra em nenhum dos dois
     *   allowlists.
     *
     * A lista vem da última migration de enum (reservation, 2026_08_14) + os dois
     * tipos novos ao fim.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit') NOT NULL");
        }
    }
};
