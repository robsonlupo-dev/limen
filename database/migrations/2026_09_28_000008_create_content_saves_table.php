<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Salvos" do membro — coleções privadas do membro, v1 (roadmap social, Onda 3 §3.1).
 *
 * O membro salva uma PEÇA de conteúdo para ver depois. **A performer NUNCA sabe** —
 * mesma disciplina de `favorites` (favorito de PERFIL): sem relação inversa no lado
 * dela, sem contador em superfície nenhuma, nada em `audit_logs`. Ver o cabeçalho de
 * `App\Models\ContentSave` e de `App\Models\Favorite`.
 *
 * Só nasce por `user_id` + `performer_content_id` já resolvidos (fora do $fillable).
 * UNIQUE por par (idempotência do toggle). Só `created_at` (a linha nasce e morre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performer_content_id')->constrained('performer_content')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // Um salvo por par (membro, peça) — idempotência do toggle.
            $table->unique(['user_id', 'performer_content_id']);
            // Listagem "meus salvos, mais recentes primeiro".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_saves');
    }
};
