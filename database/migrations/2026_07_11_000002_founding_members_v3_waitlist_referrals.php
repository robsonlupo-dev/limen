<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_referrals', function (Blueprint $table) {
            // same_role: referrer and referred picked the same role (member→member
            // / performer→performer). cross_role: e.g. performer→member. Tier
            // thresholds treat the two differently.
            $table->string('referral_type', 12)->default('same_role')->after('confirmed');

            // Set post-launch when the referred person becomes a real registered
            // user. Distinct from `confirmed` (email opt-in) — it is the stronger
            // signal that drives the founder/patron/ambassador tiers.
            $table->timestamp('converted_at')->nullable()->after('referral_type');

            $table->index(['referrer_id', 'referral_type', 'confirmed'], 'wr_referrer_type_confirmed_idx');
            $table->index(['referrer_id', 'referral_type', 'converted_at'], 'wr_referrer_type_converted_idx');
        });

        // Backfill referral_type for existing edges by comparing the two roles.
        DB::table('waitlist_referrals AS wr')
            ->join('waitlist_entries AS r', 'r.id', '=', 'wr.referrer_id')
            ->join('waitlist_entries AS d', 'd.id', '=', 'wr.referred_id')
            ->update(['wr.referral_type' => DB::raw("IF(r.role = d.role, 'same_role', 'cross_role')")]);
    }

    public function down(): void
    {
        Schema::table('waitlist_referrals', function (Blueprint $table) {
            $table->dropIndex('wr_referrer_type_confirmed_idx');
            $table->dropIndex('wr_referrer_type_converted_idx');
            $table->dropColumn(['referral_type', 'converted_at']);
        });
    }
};
