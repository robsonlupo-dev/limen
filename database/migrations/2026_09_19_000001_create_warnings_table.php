<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertências de moderação (feat/moderator-actions). Registro append-only de
 * cada advertência que um moderador/admin emite a um usuário — a base do
 * "N advertências" que aparece no card da denúncia. Nunca guarda PII: só o
 * user_id do alvo, quem emitiu, o motivo e (opcional) a denúncia de origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // alvo
            $table->foreignId('issued_by')->constrained('users'); // moderador/admin
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warnings');
    }
};
