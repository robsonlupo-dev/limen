<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acesso ao chat comprado por um membro SEM assinatura, por performer.
     * Assinantes têm chat livre e NÃO geram linha aqui. Ver config/chat.php e
     * docs/COMMUNICATION_ECONOMY.md §2.
     *
     * Janela: active_days de acesso total (expires_at); depois grace_days de
     * carência (grace_ends_at) com histórico bloqueado; passada a carência, o
     * job soft-deleta as mensagens e marca status='deleted'.
     */
    public function up(): void
    {
        Schema::create('chat_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamp('expires_at');      // acesso total até aqui
            $table->timestamp('grace_ends_at');   // carência (bloqueado) até aqui
            $table->timestamp('renewed_at')->nullable();
            $table->enum('status', ['active', 'expired', 'deleted'])->default('active');
            // Débito do acesso (50 tokens) e crédito do split à performer — do
            // último open/renovação (a linha é reusada por par).
            $table->foreignId('spend_ledger_id')->nullable()->constrained('token_ledger')->restrictOnDelete();
            $table->foreignId('credit_ledger_id')->nullable()->constrained('token_ledger')->restrictOnDelete();
            // Idempotência do open/renew: um double-submit com a mesma chave não
            // cobra 50 tokens duas vezes (princípio nº 3 do CLAUDE.md).
            $table->uuid('last_idempotency_key')->nullable();
            $table->timestamps();

            // Um acesso por par — renovação atualiza a mesma linha.
            $table->unique(['member_id', 'performer_profile_id']);
            // Varredura do job diário por prazo/estado.
            $table->index(['status', 'grace_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_access');
    }
};
