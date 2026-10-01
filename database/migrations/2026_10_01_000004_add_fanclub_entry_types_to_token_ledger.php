<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fã-Clube (Onda 4, `docs/FORK_ASSINATURA.md` §2). Tipos de lançamento da assinatura
 * por-performer. Princípio nº 2: cada tipo novo é migration no enum, nunca UPDATE de saldo.
 *
 * - `spend_fanclub_sub`   → DÉBITO do membro na assinatura/renovação do fã-clube. Gasto;
 *   não é crédito, não respeita teto, não é sacável.
 * - `fanclub_sub_credit`  → CRÉDITO 80/20 da performer (rate 'content'). Como todo
 *   `*_credit`: nunca respeita teto e ENTRA no allowlist de payout (ganho sacável, igual
 *   ao content_credit/ppv_message_credit/custom_order_credit).
 *
 * Lista = última migration de enum (custom_order, 2026_09_29_000006) + os 2 tipos novos.
 */
return new class extends Migration
{
    private const WITH_FANCLUB = "ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal','spend_ppv_message','ppv_message_credit','spend_custom_order','custom_order_credit','custom_order_refund','spend_fanclub_sub','fanclub_sub_credit')";

    private const WITHOUT_FANCLUB = "ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal','spend_ppv_message','ppv_message_credit','spend_custom_order','custom_order_credit','custom_order_refund')";

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE token_ledger MODIFY COLUMN entry_type '.self::WITH_FANCLUB.' NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE token_ledger MODIFY COLUMN entry_type '.self::WITHOUT_FANCLUB.' NOT NULL');
        }
    }
};
