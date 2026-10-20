<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Com rosto" (roadmap Fatal Model, Onda B §4.1 — base do rosto pago).
 *
 * A performer marca MANUALMENTE que uma peça mostra o rosto dela (sem detecção
 * por IA na v1 — ela escolhe). Alimenta o filtro "Com rosto" do catálogo e, nas
 * peças seguintes da Onda B, a política de rosto pago + a prévia borrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->boolean('shows_face')->default(false)->after('access_level');
            // O filtro "Com rosto" pergunta "a performer tem peça pronta com
            // rosto?" — um índice por (performer, shows_face) serve essa subquery.
            $table->index(['performer_profile_id', 'shows_face']);
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropIndex(['performer_profile_id', 'shows_face']);
            $table->dropColumn('shows_face');
        });
    }
};
