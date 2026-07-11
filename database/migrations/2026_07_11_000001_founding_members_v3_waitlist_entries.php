<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            // Position is now counted separately per role (a member and a
            // performer each have their own "#N"). Frozen at signup.
            $table->unsignedInteger('position_in_role')->nullable()->after('referred_by');

            // Role-specific tiers replace the generic `tier`. Only the column
            // matching the entry's role is populated; the other stays null.
            $table->string('tier_member', 20)->nullable()->after('referral_count');
            $table->string('tier_performer', 20)->nullable()->after('tier_member');
        });

        // Backfill position_in_role independently per role, ordered by signup.
        foreach (['member', 'performer'] as $role) {
            $position = 0;
            DB::table('waitlist_entries')->where('role', $role)
                ->orderBy('created_at')->orderBy('id')
                ->each(function ($row) use (&$position) {
                    DB::table('waitlist_entries')->where('id', $row->id)
                        ->update(['position_in_role' => ++$position]);
                });
        }

        // Seed base tiers by role. Non-base tiers re-derive automatically the
        // next time any referral edge changes (TierCalculator via the observer);
        // pre-launch volume makes a stale higher tier a non-issue.
        DB::table('waitlist_entries')->where('role', 'member')->update(['tier_member' => 'curious']);
        DB::table('waitlist_entries')->where('role', 'performer')->update(['tier_performer' => 'candidate']);

        // Drop the generic tier column now that role-specific ones exist.
        if (Schema::hasColumn('waitlist_entries', 'tier')) {
            Schema::table('waitlist_entries', function (Blueprint $table) {
                $table->dropColumn('tier');
            });
        }
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->string('tier', 20)->default('curious')->after('referral_count');
            $table->dropColumn(['position_in_role', 'tier_member', 'tier_performer']);
        });
    }
};
