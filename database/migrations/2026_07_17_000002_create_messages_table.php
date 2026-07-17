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
            // A cobrança do chat é por ACESSO (tabela chat_access), não por
            // mensagem — a mensagem em si não carrega lançamento de ledger.
            $table->timestamps();
            // Soft delete: ao vencer a carência, a mensagem é ocultada da UI mas
            // RETIDA no servidor (trilha de abuso/legal). Nunca hard-delete aqui.
            $table->softDeletes();

            // Paginação de mensagens de uma conversa em ordem cronológica.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
