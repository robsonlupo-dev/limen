<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de um presente enviado por um membro a uma performer (M.13.6).
     * Espelha `tips`: registro financeiro imutável, com split congelado na linha
     * (applied_rate=75) e âncora no ledger append-only. A performer só vê o
     * remetente via FanAlias — `sender_id` é chave interna, nunca exposta
     * (M.13.10). Idempotência por `idempotency_key` (UNIQUE), como o Tip: o mesmo
     * envio nunca cobra duas vezes.
     *
     * Sem SoftDeletes e fora do Hard Delete: é lastro fiscal, preservado como as
     * linhas de `tips` e do próprio ledger. Não há texto livre de terceiro a
     * scrubar (presente não tem mensagem).
     */
    public function up(): void
    {
        Schema::create('gift_sends', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete espelha `tips`: gift_sends é lastro fiscal preservado,
            // não apagado no encerramento de conta. Na prática o restrict nunca
            // dispara — `anonymizeUser()` soft-deleta o `users` (item 11 do CLAUDE.md).
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->foreignId('gift_id')->constrained('gifts')->restrictOnDelete();
            $table->unsignedInteger('tokens');
            $table->unsignedInteger('performer_amount');
            $table->unsignedInteger('platform_amount');
            // Taxa de split CONGELADA na linha (M.13.7) — nunca recalculada.
            $table->unsignedTinyInteger('applied_rate');
            // Idempotência ESCOPADA POR REMETENTE (UNIQUE composto): a chave de um
            // membro nunca colide com nem devolve a linha de outro (achado #6 da
            // revisão de segurança). Mais forte que o UNIQUE global do Tip.
            $table->uuid('idempotency_key');
            // Âncoras no ledger (imutável, nunca deletado) — sem FK para não
            // acoplar ao append-only; ids escalares de auditoria/reconciliação.
            $table->unsignedBigInteger('sender_ledger_id')->nullable();
            $table->unsignedBigInteger('performer_ledger_id')->nullable();
            $table->timestamps();

            $table->unique(['sender_id', 'idempotency_key']);
            $table->index(['performer_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_sends');
    }
};
