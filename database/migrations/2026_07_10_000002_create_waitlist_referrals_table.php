<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('waitlist_entries')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('waitlist_entries')->cascadeOnDelete();
            // Viral credit is only counted once the referred person confirms
            // their email; this flips false -> true at that moment.
            $table->boolean('confirmed')->default(false);
            // Hashed signup IP of the referred person (never the raw IP — PII
            // minimization). Used only for the "max 3 referrals/IP/24h" cap.
            $table->string('referred_ip_hash', 64)->nullable();
            $table->timestamps();

            // One referral edge per referred person (a signup has at most one
            // referrer). Enforces the graph and makes the credit idempotent.
            $table->unique('referred_id');
            $table->index(['referrer_id', 'confirmed']);
            $table->index(['referred_ip_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_referrals');
    }
};
