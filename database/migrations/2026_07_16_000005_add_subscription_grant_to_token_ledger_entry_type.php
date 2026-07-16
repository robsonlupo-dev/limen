<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite has no ENUM — values are TEXT, so no ALTER needed there.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock','subscription_grant') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL refuses to drop an enum value still in use; remap first.
            DB::table('token_ledger')
                ->where('entry_type', 'subscription_grant')
                ->update(['entry_type' => 'adjustment']);

            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill','spend_interest_unlock') NOT NULL");
        }
    }
};
