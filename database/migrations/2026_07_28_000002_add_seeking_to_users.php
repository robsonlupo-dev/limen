<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "O que estou buscando" — texto livre auto-declarado pelo MEMBRO.
 *
 * Coluna em `users` e não em junção: é um parágrafo por pessoa, sem faceta nem
 * filtro. A decisão espelha a de `looking_for` na performer, e a assimetria com
 * `member_interest` (que é junção) é deliberada — lá há cardinalidade e um
 * filtro pela direção inversa, aqui não.
 *
 * PRIVACIDADE: ao contrário do `looking_for` da performer, este campo NÃO é
 * publicado em lugar nenhum. `looking_for` mora numa página pública indexável;
 * `seeking` não é exposto nem à performer que o membro segue. Serve ao
 * cruzamento de afinidade do Sprint 10 e a nada mais. Nova superfície que
 * mostre membro à performer não o inclui — mesma disciplina do FanAlias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('seeking')->nullable()->after('preferred_world');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('seeking');
        });
    }
};
