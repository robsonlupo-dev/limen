<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destaques (Highlights) da performer (roadmap social, Onda 1a): coleções
     * permanentes fixadas no perfil. O story some em 24h, então adicionar ao
     * destaque COPIA a mídia (ver story_highlight_items). Teto de coleções no
     * config/stories.php (poupar disco).
     */
    public function up(): void
    {
        Schema::create('story_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title', 30);
            // A CAPA é o primeiro item da coleção (sem coluna dedicada no MVP).
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['performer_profile_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_highlights');
    }
};
