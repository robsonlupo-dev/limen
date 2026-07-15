<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O nome artístico é a identidade comercial da performer: é por ele que o
     * consumidor decide para quem mandar gorjeta. Sem unicidade, uma performer
     * verificada renomeia para o nome de outra, mantém o selo (o KYC valida a
     * identidade legal, não o nome artístico) e desvia o dinheiro.
     *
     * A validação (PerformerProfile::stageNameRules) já barra isso nas três
     * portas; o índice fecha a corrida entre dois cadastros simultâneos, que a
     * validação sozinha não pega.
     *
     * O índice cobre linhas soft-deleted, igual à regra de validação e ao
     * generateSlug(): o nome de quem saiu não é reciclado — reciclar é clonar
     * alguém que não está mais lá para reclamar.
     */
    public function up(): void
    {
        // A criação do índice falharia com um erro de chave duplicada difícil de
        // ler no meio do deploy. Abortar antes, dizendo exatamente quais nomes
        // resolver, é a diferença entre um deploy diagnosticável e um susto.
        $duplicates = DB::table('performer_profiles')
            ->select('stage_name')
            ->groupBy('stage_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('stage_name');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Não é possível tornar stage_name único: já existem nomes repetidos em '
                . 'performer_profiles (inclusive soft-deleted). Resolva antes de migrar. '
                . 'Repetidos: ' . $duplicates->implode(', ')
            );
        }

        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->unique('stage_name');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropUnique(['stage_name']);
        });
    }
};
