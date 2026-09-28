<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca a mensagem como EFÊMERA (roadmap social, Onda 2). Carimbada no ENVIO com o
 * estado do modo efêmero da conversa naquele instante — imutável depois.
 *
 * "Ver-uma-vez": a mensagem efêmera some da EXIBIÇÃO depois de vista pelo
 * destinatário (nas duas pontas). O sumiço é REDAÇÃO de exibição derivada de
 * `read_at` (não apaga `body`): o original fica retido para a moderação, como o
 * "desfazer envio" (§ retenção). Presente NUNCA é efêmero (é dinheiro — como não é
 * redigível). Default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('ephemeral')->default(false)->after('redacted_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('ephemeral');
        });
    }
};
