<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal','staging_seed_backfill') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL refuses to drop an enum value still in use, so remap any
            // backfill rows to 'adjustment' before narrowing the column.
            DB::table('token_ledger')
                ->where('entry_type', 'staging_seed_backfill')
                ->update(['entry_type' => 'adjustment']);

            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit','payout_reversal') NOT NULL");
        }
    }
};
