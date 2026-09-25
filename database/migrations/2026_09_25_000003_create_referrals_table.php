<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por PESSOA INDICADA (feat/referral-program). O vínculo é gravado no
     * cadastro e é imutável; a conversão e o hold evoluem o `status`.
     */
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            // UNIQUE: cada pessoa só pode ter UMA indicação (um indicador). O vínculo
            // é gravado uma vez no cadastro e nunca muda.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Papel do indicado no momento do cadastro — decide QUAL conversão vale
            // (compra de membro × KYC+1º ganho de performer) e o valor do bônus.
            $table->enum('referred_role_at_signup', ['member', 'performer']);

            // pending → qualified → (hold) → rewarded no referral_rewards; clawed_back
            // se a base for revertida durante o hold.
            $table->enum('status', ['pending', 'qualified', 'rewarded', 'clawed_back'])
                ->default('pending');

            // Marca de que a performer indicada já teve KYC aprovado (parte 1 da
            // conversão 3.B). A conversão só fecha quando ALÉM disso houver o 1º
            // ganho de um terceiro.
            $table->timestamp('kyc_approved_at')->nullable();

            $table->timestamp('qualified_at')->nullable();
            // Fim do hold anti-estorno (14 dias por padrão) — quando passa, o
            // referral:process-holds credita os dois lados.
            $table->timestamp('hold_until')->nullable();

            // Só auditoria/observabilidade quando uma indicação é recusada
            // (auto-indicação, contas ligadas, base estornada).
            $table->string('rejection_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['status', 'hold_until']);
            $table->index('referrer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
