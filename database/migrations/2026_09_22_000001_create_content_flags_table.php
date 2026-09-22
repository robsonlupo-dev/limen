<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinalizações automáticas de conteúdo (feat/flagged-content-queue, Fase 4c).
 *
 * Um evento por vez que um usuário tropeça no filtro de CONDUTA (chat 1:1, chat
 * ao vivo e, adiante, outros sinais). NUNCA guarda o corpo da mensagem — só o
 * HMAC da regra (`rule_hash`, como o audit_logs) e a fonte. A moderação age por
 * REINCIDÊNCIA: a fila agrega por usuário e ordena por contagem.
 *
 * `status` é autoridade do servidor: 'pending' até um moderador dispensar
 * ('dismissed'). Advertir/suspender não muda o flag — some da fila via dispensar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_flags', function (Blueprint $table) {
            $table->id();
            // O usuário SINALIZADO (quem enviou o conteúdo bloqueado).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // De onde veio o sinal: 'chat', 'live_chat' (extensível: 'profile_text'…).
            $table->string('source', 32);
            // Categoria do filtro — por ora só 'conduct' é sinalizado.
            $table->string('category', 16);
            // HMAC da regra (ChatContentFilter::digest) — para calibração; nunca o corpo.
            $table->string('rule_hash', 64);
            // 'pending' | 'dismissed'. Autoridade do servidor (forceFill/update).
            $table->string('status', 16)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A fila lê pendentes por usuário; o audit/calibração lê por recência.
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_flags');
    }
};
