<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Responder story → chat" (feat/story-reply-to-chat). Quando o membro responde a
 * um story da performer, a resposta é uma mensagem de chat normal (entra na
 * economia: 1º envio abre/paga a janela), mas guarda um ponteiro para o story
 * respondido — a bolha mostra "Respondeu ao story" (e a miniatura, para a dona).
 *
 * nullOnDelete: o story é efêmero (24h) e some/é purgado; a mensagem PERMANECE
 * (retenção do chat), só perde o ponteiro. Nunca cascata que apagaria a mensagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reply_to_story_id')
                ->nullable()
                ->after('gift_id')
                ->constrained('performer_stories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_story_id');
        });
    }
};
