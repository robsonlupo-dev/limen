<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desbloqueio PERMANENTE de uma peça por um membro (M.4). Uma linha por par
     * (peça, membro) — UNIQUE é a rede de idempotência contra duplo-submit (não
     * cobra duas vezes). Só existe para desbloqueio PAGO: o acesso grátis do
     * assinante ao conteúdo Aberto é DERIVADO na leitura, nunca materializado.
     *
     * spend_ledger_id/credit_ledger_id ancoram a linha no ledger append-only (que
     * permanece no Hard Delete — lastro fiscal, só desvinculado).
     */
    public function up(): void
    {
        Schema::create('content_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_content_id')->constrained('performer_content')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tokens_paid');
            // Referências ao ledger (imutável, nunca deletado) — sem FK para não
            // acoplar ao append-only; são ids escalares de auditoria.
            $table->unsignedBigInteger('spend_ledger_id')->nullable();
            $table->unsignedBigInteger('credit_ledger_id')->nullable();
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->unique(['performer_content_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_unlocks');
    }
};
