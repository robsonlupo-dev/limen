<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            // Quem denunciou. restrictOnDelete de propósito: uma denúncia de
            // conteúdo ilegal é o lastro de que a plataforma foi notificada —
            // apagar o usuário não pode apagar o registro em cascata.
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            // Alvo morfável (perfil de performer, mensagem, ...). Sem FK por
            // definição — a coluna de tipo escolhe a tabela em runtime.
            $table->morphs('reportable');
            $table->enum('reason', [
                'underage_content',
                'non_consensual',
                'coercion',
                'impersonation',
                'spam',
                'other',
            ]);
            $table->text('details')->nullable();
            $table->enum('status', ['pending', 'reviewed', 'resolved', 'dismissed'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Fila do admin: pendentes primeiro, mais recentes no topo.
            $table->index(['status', 'created_at']);
            // Janela anti-spam de 24h: (denunciante, alvo, motivo) recentes.
            $table->index(['reporter_id', 'reportable_type', 'reportable_id', 'reason'], 'reports_dedup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
