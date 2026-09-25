<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Desfazer envio" no chat (feat/chat-unsend-message). O remetente pode redigir
 * a PRÓPRIA mensagem numa janela curta (config chat.redact_window_minutes). É uma
 * REDAÇÃO de EXIBIÇÃO, não um delete: `redacted_at` esconde o conteúdo na tela
 * das duas pontas, mas o `body`/áudio ORIGINAL fica no banco — a moderação segue
 * lendo a prova (princípio nº 1: retenção para trilha de abuso/legal). Nunca
 * hard-delete por ação do usuário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('redacted_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('redacted_at');
        });
    }
};
