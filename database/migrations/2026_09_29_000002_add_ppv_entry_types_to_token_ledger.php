<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PPV no chat (Onda 4, §4.1): tipos de lançamento do desbloqueio de conteúdo
 * enviado travado na DM. Princípio nº 2: cada tipo novo é migration no enum, nunca
 * UPDATE de saldo.
 *
 * - `spend_ppv_message` → DÉBITO do membro ao desbloquear o PPV. Não é crédito, não
 *   respeita teto, não é sacável.
 * - `ppv_message_credit` → CRÉDITO 80/20 da performer (rate 'content'). Como todo
 *   `*_credit`: NUNCA respeita o teto e ENTRA no allowlist de payout
 *   (`monetization.payout.earning_entry_types`) — é ganho sacável, igual ao
 *   `content_credit`. (Ambas as listas são atualizadas em config/monetization.php.)
 *
 * A lista vem da última migration de enum (referral, 2026_09_25) + os dois tipos
 * novos ao fim.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal','spend_ppv_message','ppv_message_credit') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal') NOT NULL");
        }
    }
};
