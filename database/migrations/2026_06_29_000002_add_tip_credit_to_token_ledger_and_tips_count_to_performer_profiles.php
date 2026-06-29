<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite has no ENUM — values are TEXT, so no ALTER needed there
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment','tip_credit') NOT NULL");
        }

        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->unsignedInteger('tips_count')->default(0)->after('followers_count');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('tips_count');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE token_ledger MODIFY COLUMN entry_type ENUM('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment') NOT NULL");
        }
    }
};
