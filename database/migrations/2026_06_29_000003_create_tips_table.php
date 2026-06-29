<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('performer_amount');
            $table->unsignedInteger('platform_amount');
            $table->string('message', 200)->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('consumer_ledger_id')->constrained('token_ledger')->restrictOnDelete();
            $table->foreignId('performer_ledger_id')->constrained('token_ledger')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tips');
    }
};
