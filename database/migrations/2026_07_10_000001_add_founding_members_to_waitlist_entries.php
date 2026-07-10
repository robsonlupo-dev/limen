<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            // Unique per-entry secrets. Nullable at the DB level only so the
            // backfill below can run on rows that already exist on staging; the
            // application always populates both on create.
            $table->string('invite_code')->nullable()->unique()->after('email');
            $table->string('invite_token', 64)->nullable()->unique()->after('invite_code');

            // Self-referential referral graph. nullOnDelete so removing a
            // referrer never cascades away their referreds' rows.
            $table->foreignId('referred_by')->nullable()->after('source')
                ->constrained('waitlist_entries')->nullOnDelete();

            // Double opt-in: null until the person confirms their email. Viral
            // credit is only granted on confirmation (see WaitlistReferral).
            $table->timestamp('confirmed_at')->nullable()->after('age_confirmed');

            // Cache of confirmed-referral count, kept in sync by an observer.
            // Derived value — the source of truth is the waitlist_referrals table.
            $table->unsignedInteger('referral_count')->default(0)->after('confirmed_at');

            $table->string('tier', 20)->default('curious')->after('referral_count');

            $table->index('referred_by', 'waitlist_entries_referred_by_index');
        });

        // Backfill entries that predate this migration (the base waitlist already
        // live on staging) so every row has a unique code/token. Codes use random
        // letters (not name-derived) for the same anti-enumeration reason as new
        // signups (see WaitlistEntry::generateInviteCode).
        DB::table('waitlist_entries')->whereNull('invite_code')->orderBy('id')
            ->each(function ($row) {
                DB::table('waitlist_entries')->where('id', $row->id)->update([
                    'invite_code' => self::backfillCode(),
                    'invite_token' => bin2hex(random_bytes(20)),
                ]);
            });
    }

    /** A unique, non-enumerable code for backfill: LIMEN-XXX-#### (all random). */
    private static function backfillCode(): string
    {
        do {
            $letters = '';
            for ($i = 0; $i < 3; $i++) {
                $letters .= chr(random_int(65, 90));
            }
            $code = sprintf('LIMEN-%s-%04d', $letters, random_int(0, 9999));
        } while (DB::table('waitlist_entries')->where('invite_code', $code)->exists());

        return $code;
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropIndex('waitlist_entries_referred_by_index');
            $table->dropUnique(['invite_code']);
            $table->dropUnique(['invite_token']);
            $table->dropColumn([
                'invite_code', 'invite_token', 'referred_by',
                'confirmed_at', 'referral_count', 'tier',
            ]);
        });
    }
};
