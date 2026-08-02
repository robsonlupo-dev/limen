<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor de roles (Sprint 13): a fila de moderação `/moderacao/*` deixa o
 * revisor anotar por que fechou a denúncia (revisada / resolvida / descartada).
 *
 * A nota é da EQUIPE sobre a denúncia — não é conteúdo do membro nem do
 * denunciado, então fica em claro (ao contrário da nota da performer sobre o
 * membro, cifrada). Nullable: denúncia fechada sem comentário é o caso comum, e
 * a coluna não pode forçar texto onde "revisei, nada a fazer" basta.
 *
 * `reviewed_by`/`reviewed_at` já existem (create_reports_table) e continuam sendo
 * a autoridade do servidor gravada por forceFill; a nota entra pela mesma porta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('moderator_notes')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('moderator_notes');
        });
    }
};
