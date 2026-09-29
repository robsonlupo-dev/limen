<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encomenda sob medida com escrow (Onda 4, §4.3): tipos de lançamento do escrow.
 * Princípio nº 2: cada tipo novo é migration no enum, nunca UPDATE de saldo.
 *
 * - `spend_custom_order`   → DÉBITO do membro no ACEITE (entra em escrow). Não é
 *   crédito, não respeita teto, não é sacável.
 * - `custom_order_credit`  → CRÉDITO 80/20 da performer na LIBERAÇÃO (rate 'content').
 *   Como todo `*_credit`: nunca respeita teto e ENTRA no allowlist de payout (ganho
 *   sacável, igual ao content_credit).
 * - `custom_order_refund`  → CRÉDITO 100% de volta ao membro (recusa/expiração/
 *   não-entrega/disputa a favor). Nunca respeita teto e NÃO entra no payout (é
 *   devolução do dinheiro do membro, não ganho — igual ao call_reservation_refund).
 *
 * Lista vem da última migration de enum (ppv, 2026_09_29_000002) + os 3 tipos novos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal','spend_ppv_message','ppv_message_credit','spend_custom_order','custom_order_credit','custom_order_refund') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit','referral_bonus','referral_bonus_reversal','spend_ppv_message','ppv_message_credit') NOT NULL");
        }
    }
};
