<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conteúdo permanente da performer (M.4/M.13.13): foto (v1) com nível de acesso
     * e preço em tokens. Desbloqueio é PERMANENTE (content_unlocks), sem TTL — por
     * isso o disco fica sob storage/app/private (entra no backup, ao contrário de
     * story/foto efêmera). `kind` é enum com um valor só hoje; vídeo entra depois,
     * com o próprio pipeline de higienização (GD não processa vídeo).
     *
     * SEM SoftDeletes: remover é DELETE de verdade (bytes + linha); denúncia aberta
     * congela a remoção (anti-destruição de prova), como no Story.
     */
    public function up(): void
    {
        Schema::create('performer_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['photo'])->default('photo');
            $table->enum('access_level', ['open', 'premium', 'exclusive', 'fc_only']);
            $table->unsignedInteger('price_tokens');
            $table->text('path');
            // SHA-256 dos bytes JÁ PROCESSADOS (prova que sobrevive ao arquivo).
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['performer_profile_id', 'created_at']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_content');
    }
};
