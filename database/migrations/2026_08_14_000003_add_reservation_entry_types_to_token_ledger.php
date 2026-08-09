<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agendamento de chamada (feat/scheduled-call-v1): três tipos novos no enum do
     * ledger append-only (princípio nº 2 — cada movimento é linha nova, NUNCA
     * UPDATE de saldo):
     *
     *  - `spend_call_reservation` — DÉBITO do depósito do membro no ato do
     *    agendamento (trava os tokens; indisponíveis até resolver).
     *  - `call_reservation_refund` — CRÉDITO 100% ao membro (cancel grátis,
     *    reserva não-confirmada, no-show da performer). *_credit-like de devolução:
     *    NUNCA respeita teto (M.13.9, fora de cap_respecting_entry_types) e FORA do
     *    allowlist de payout (não é ganho — é devolução do próprio dinheiro).
     *  - `call_noshow_credit` — CRÉDITO 100/0 à performer no no-show do MEMBRO
     *    (compensação pela reserva do horário). É GANHO sacável → entra em
     *    payout.earning_entry_types (config/monetization.php).
     *
     * A entrada bem-sucedida (minuto 1 pago pelo depósito) reusa `call_credit`
     * (split 70/30, applied_rate=70) do PR #140 — não precisa de tipo novo.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit','spend_call_reservation','call_reservation_refund','call_noshow_credit') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant','spend_chat_access','chat_access_credit','spend_boost','spend_content','content_credit','spend_gift','gift_credit','spend_live','live_credit','spend_call','call_credit') NOT NULL");
        }
    }
};
