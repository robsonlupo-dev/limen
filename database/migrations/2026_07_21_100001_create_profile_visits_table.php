<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem passou pelo perfil da performer. Alimenta UMA tela: "visitantes
     * recentes" no painel dela, sempre sob FanAlias — o id do membro nunca
     * chega lá (mesma regra de gorjetas e seguidores).
     *
     * Só grava visita de quem NÃO tem Ghost Mode. A ausência de linha é o
     * produto: para a performer, o visitante Black simplesmente não passou.
     * Por isso a tabela não tem coluna `ghost`/`hidden` — guardar a visita
     * marcada como oculta manteria o dado a um JOIN de distância de vazar, e
     * transformaria um bug de query no vazamento exato que o perk vende.
     *
     * Sem soft delete: dado comportamental, sem valor fiscal nem trilha legal.
     * No encerramento de conta as linhas do titular saem de fato (DeletionService).
     */
    public function up(): void
    {
        Schema::create('profile_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->timestamps();

            // Cobre as duas leituras: a janela de 24h do painel da performer
            // (prefixo performer_profile_id + visited_at) e a checagem de
            // deduplicação, que busca o par exato na última meia hora.
            $table->index(['performer_profile_id', 'visited_at']);
            $table->index(['visitor_id', 'performer_profile_id', 'visited_at'], 'profile_visits_pair_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_visits');
    }
};
