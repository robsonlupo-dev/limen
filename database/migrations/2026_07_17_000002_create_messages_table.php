<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            // Débito do remetente (membro sem Círculo) — 1 linha no ledger
            // append-only. Null quando a mensagem foi grátis (assinante ou
            // performer). Amarra a mensagem paga ao débito, como a gorjeta.
            $table->foreignId('spend_ledger_id')->nullable()->constrained('token_ledger')->restrictOnDelete();
            // Crédito à performer (split do custo). Null quando grátis.
            $table->foreignId('credit_ledger_id')->nullable()->constrained('token_ledger')->restrictOnDelete();
            $table->timestamps();

            // Paginação de mensagens de uma conversa em ordem cronológica.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
