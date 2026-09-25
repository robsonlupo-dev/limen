<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por LADO premiado de uma indicação (feat/referral-program): o
     * indicador e o indicado ganham cada um a sua. UNIQUE(referral_id, role) é a
     * trava de idempotência — o hold nunca credita duas vezes o mesmo lado.
     */
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();

            // Quem recebe ESTE crédito.
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['referrer', 'referred']);

            // O que qualificou a indicação (auditoria).
            $table->enum('trigger', ['member_purchase', 'performer_first_earning']);

            // Tokens do bônus (inteiro — bônus é sempre inteiro).
            $table->unsignedInteger('amount');

            // Elo com a linha real do ledger (referral_bonus) quando creditado.
            $table->foreignId('ledger_id')->nullable()->constrained('token_ledger')->nullOnDelete();

            // pending (esperando o hold) → rewarded (creditado) | reversed (clawback)
            // | skipped (não coube sob o teto, ou base revertida antes de creditar).
            $table->enum('status', ['pending', 'rewarded', 'reversed', 'skipped'])
                ->default('pending');

            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();

            $table->unique(['referral_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
