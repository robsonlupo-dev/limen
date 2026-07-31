<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favoritos do membro (Sprint 10) — bookmark PRIVADO.
 *
 * Tabela irmã de `follows` na forma e oposta no destino: follow é público (a
 * performer conta seguidores e vê a lista a partir do Piso de Anonimato),
 * favorito nunca sai do lado do membro. Por isso não há coluna de contador aqui
 * e não existe `favorites_count` em `performer_profiles`: contador é a primeira
 * coisa que alguém acabaria exibindo no painel dela.
 *
 * `created_at` sozinho, sem `updated_at`: a linha nasce e morre, nunca é
 * editada. Toggle é DELETE + INSERT, não UPDATE.
 *
 * O UNIQUE (user_id, performer_profile_id) é o que torna o toggle seguro contra
 * duplo-submit — ver FavoriteService::toggle(), que trata a corrida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'performer_profile_id']);

            // A lista do membro sai ordenada por "salvo mais recentemente".
            // O índice do UNIQUE já cobre o `where user_id`, mas não a ordenação
            // por data dentro dele.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
