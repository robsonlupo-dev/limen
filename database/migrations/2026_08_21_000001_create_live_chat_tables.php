<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat da LIVE (feat/live-room-console). Antes a live subia "no escuro" — sem chat
 * dos dois lados. Agora performer e espectadores conversam na sala, em tempo real
 * pelo Reverb (o mesmo do <LiveOverlay>), com o filtro de conteúdo do chat aplicado.
 *
 *  - `live_chat_messages`: as mensagens da sala. Persistidas (não só broadcast) para
 *    a MODERAÇÃO resolver o autor de uma mensagem sem expor o handle do FanAlias a
 *    todos os espectadores — a performer silencia clicando na própria mensagem, o
 *    servidor resolve o remetente. Efêmeras: apagadas no encerramento da live
 *    (LiveSessionService) e no Hard Delete. O CORPO nunca vai para audit_logs (mesma
 *    disciplina do chat 1:1).
 *  - `live_chat_mutes`: quem a performer silenciou NAQUELA sessão. Membro silenciado
 *    não envia mais (checado no send) — UNIQUE por (sessão, membro), idempotente.
 *
 * `is_performer` distingue a fala da dona da sala (sempre pode falar, nunca é
 * silenciada) da do espectador, sem um segundo JOIN em cada render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_performer')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['live_session_id', 'id']);
        });

        Schema::create('live_chat_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['live_session_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_mutes');
        Schema::dropIfExists('live_chat_messages');
    }
};
