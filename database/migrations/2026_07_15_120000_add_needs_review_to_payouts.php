<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            // Start of the current streak of lookups that could not resolve the payout;
            // null whenever there is no such streak. This is what the reconcile's
            // review deadline counts from — NOT requested_at, which also runs during a
            // gateway outage when no lookup is even attempted.
            $table->timestamp('unresolved_since')->nullable()->after('processed_at');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            // Appending a value to an enum that stays within 1 byte is INSTANT-eligible;
            // asking for it explicitly turns a silent full table copy (which would lock
            // payout writes during deploy) into a loud failure. INSTANT already implies
            // no locking — pairing it with an explicit LOCK clause is an error (1221).
            DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM('pending','processing','paid','failed','cancelled','needs_review') NOT NULL DEFAULT 'pending', ALGORITHM=INSTANT");
        }
    }

    public function down(): void
    {
        // 'needs_review' rows have reserved tokens and no resolution yet; dropping the
        // value would silently rewrite them to '' (or fail in strict mode). Park them
        // back in 'processing' so the reconcile picks them up again on rollback.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('payouts')->where('status', 'needs_review')->update(['status' => 'processing']);

            // Removing a value is not INSTANT-eligible — let MySQL pick the algorithm.
            DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM('pending','processing','paid','failed','cancelled') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('unresolved_since');
        });
    }
};
