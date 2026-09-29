<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de gorjeta (Onda 4, §4.2). A performer define UMA meta ativa (título + alvo em
 * tokens); uma barra enche com as gorjetas recebidas DESDE o início da meta. É leitura
 * AGREGADA por cima das gorjetas que já existem — NÃO cria tipo de ledger, NÃO mexe em
 * token (a gorjeta segue 80/20 pelo TipService).
 *
 * `started_at` é a âncora do progresso: o total conta as gorjetas com created_at >=
 * started_at. Guarda HISTÓRICO (started_at/ended_at), então NÃO há UNIQUE dura em
 * performer_profile_id — o "uma ativa por vez" é garantido no TipGoalService (encerra a
 * anterior antes de criar). `ended_at` nulo = ativa. Índice por (performer, ended_at)
 * para a busca da ativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tip_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->unsignedInteger('target'); // tokens (inteiro; o membro paga inteiro)
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // null = ativa
            $table->timestamps();

            $table->index(['performer_profile_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tip_goals');
    }
};
