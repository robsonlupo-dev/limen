<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only log of every transactional/nurturing email sent to a waitlist
     * entry. It is the idempotency ledger for the drip sequence: one row per
     * (entry, email_key), enforced by a unique index, so re-running the sender
     * can never send the same step twice — the same discipline as the payment
     * webhook. We record the row BEFORE queueing the mail (claim-then-send), so a
     * crash mid-batch leaves a claimed row rather than a duplicate delivery.
     */
    public function up(): void
    {
        Schema::create('waitlist_email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waitlist_entry_id')
                ->constrained('waitlist_entries')
                ->cascadeOnDelete(); // unsubscribe deletes the entry → its log goes too
            $table->string('email_key', 40); // e.g. nurture_1 … nurture_7
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['waitlist_entry_id', 'email_key']);
        });

        // The hourly drip filters waitlist_entries by confirmed_at; index it so
        // the scan stays cheap as the list grows.
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->index('confirmed_at', 'waitlist_entries_confirmed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('waitlist_entries_confirmed_at_index');
        });

        Schema::dropIfExists('waitlist_email_log');
    }
};
