<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            // The member (consumer) who received the interest signal.
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['sent', 'unlocked'])->default('sent');
            $table->timestamp('sent_at');
            $table->timestamp('unlocked_at')->nullable();
            // Set once, when the member pays to unlock; ties the reveal to the
            // append-only ledger debit. Null when the reveal was free (the member
            // had already unlocked this performer in a prior interest).
            $table->foreignId('unlock_ledger_id')->nullable()->constrained('token_ledger')->restrictOnDelete();
            $table->timestamps();

            // Member's inbox listing (locked/unlocked).
            $table->index(['member_id', 'status']);
            // Cooldown + daily-limit lookups by performer.
            $table->index(['performer_profile_id', 'sent_at']);
            // Pair lookups (cooldown window, prior unlock for the pair).
            $table->index(['performer_profile_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_interests');
    }
};
