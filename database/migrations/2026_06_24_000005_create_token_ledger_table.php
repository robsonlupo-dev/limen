<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('token_wallets')->cascadeOnDelete();
            $table->enum('entry_type', [
                'purchase', 'spend_tip', 'spend_private', 'spend_camera',
                'payout_reserve', 'refund', 'bonus', 'adjustment',
            ]);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_ledger');
    }
};
