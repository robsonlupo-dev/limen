<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridade + base do SLA da fila de moderação (feat/moderation-sla-priority).
 *
 * A prioridade é DERIVADA do motivo na abertura (Report::open) e gravada aqui —
 * coluna indexada para a fila ordenar "urgente primeiro" sem varrer/CASE em toda
 * consulta. Enum declarado em ordem de severidade (urgent < high < normal), então
 * o ordinal do enum já é a ordem de atendimento. O SLA em si (janela por
 * prioridade, "atrasada") é derivado em runtime de `created_at` — sem coluna: o
 * alvo é função da prioridade, e mudar a janela não deve exigir migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->enum('priority', ['urgent', 'high', 'normal'])
                ->default('normal')
                ->after('status');
            // A fila filtra por status e ordena por prioridade + antiguidade.
            $table->index(['status', 'priority', 'created_at'], 'reports_queue_idx');
        });

        // Backfill das denúncias já existentes a partir do motivo — mesma tabela
        // de severidade que Report::priorityForReason aplica na abertura.
        DB::statement(<<<'SQL'
            UPDATE reports SET priority = CASE
                WHEN reason IN ('underage_content', 'non_consensual') THEN 'urgent'
                WHEN reason IN ('coercion', 'impersonation') THEN 'high'
                ELSE 'normal'
            END
        SQL);
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_queue_idx');
            $table->dropColumn('priority');
        });
    }
};
