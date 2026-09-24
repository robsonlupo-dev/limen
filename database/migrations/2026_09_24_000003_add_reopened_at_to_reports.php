<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de reabertura da denúncia (feat/moderation-reversal-action).
 *
 * Reabrir uma decisão devolve a denúncia à fila como `pending`, mas o
 * `created_at` continua sendo o da abertura ORIGINAL. Sem uma marca própria, o
 * relógio de SLA e o tempo de resolução leriam esse `created_at` antigo — uma
 * denúncia reaberta hoje nasceria "atrasada há meses" e, ao ser re-fechada,
 * reportaria um tempo de resolução de meses. `reopened_at` é o novo início do
 * relógio: `COALESCE(reopened_at, created_at)` mede o CICLO atual, não a idade
 * total. Nula em toda denúncia nunca reaberta → o comportamento existente não
 * muda (o coalesce cai em `created_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('reopened_at');
        });
    }
};
