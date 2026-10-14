<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat de TEXTO da chamada 1:1 (feat/in-call-chat). As mensagens vivem só durante
 * a chamada na UI, mas FICAM no banco para a moderação (mesma disciplina de
 * retenção do chat/áudio). `sender_id` é chave interna (FK users), como no ledger
 * — a apresentação para a performer usa o FanAlias, nunca o member_id (M.13.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained('call_sessions')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['call_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_messages');
    }
};
