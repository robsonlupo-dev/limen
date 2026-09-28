<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo efêmero (vanish) da conversa (roadmap social, Onda 2).
 *
 * Flag na CONVERSA: qualquer um dos dois participantes liga/desliga. Vale para as
 * mensagens enviadas ENQUANTO está ligado (cada mensagem carimba o próprio flag no
 * envio — ver a coluna em `messages`), então desligar não ressuscita o que já sumiu.
 * Default false = conversa normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('ephemeral')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ephemeral');
        });
    }
};
