<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagem de voz no chat (feat/chat-voice-message).
 *
 * Mesma tática do presente (`gift_id`): colunas NULÁVEIS numa `messages`; a
 * PRESENÇA de `audio_status` marca a mensagem como de voz — sem coluna de "tipo".
 * Mensagem de texto continua com tudo isto null.
 *
 *  - `audio_status`: processing (subiu, ffmpeg ainda rodando) → ready (servível)
 *    → failed (não deu para processar; o remetente reenvia). Igual à intro de voz.
 *  - `audio_path`: caminho no disco `chat_audio` (só depois do ffmpeg). Fora de
 *    qualquer serialização — servido por request, nunca por URL de disco.
 *  - `audio_duration_seconds`: para a UI mostrar a duração sem ler o arquivo.
 *  - `audio_content_hash`: SHA-256 dos bytes processados — casa com listas de hash
 *    conhecidas e vira prova sob denúncia (a mensagem já é denunciável).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('audio_status', ['processing', 'ready', 'failed'])->nullable()->after('body');
            $table->string('audio_path')->nullable()->after('audio_status');
            $table->unsignedSmallInteger('audio_duration_seconds')->nullable()->after('audio_path');
            $table->char('audio_content_hash', 64)->nullable()->after('audio_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['audio_status', 'audio_path', 'audio_duration_seconds', 'audio_content_hash']);
        });
    }
};
