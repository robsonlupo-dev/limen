<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itens de um destaque (roadmap social, Onda 1a). Cada item guarda a PRÓPRIA
     * cópia permanente da mídia (`media_path` no disco 'performer_highlights'), com
     * `content_hash` re-calculado na cópia (moderação). O story de origem pode
     * expirar e ser recolhido; o destaque sobrevive (`source_story_id` nullOnDelete,
     * só proveniência).
     *
     * MVP: só stories PÚBLICOS podem virar destaque (vitrine pública, paywall
     * intacto), então `visibility_level` é sempre 'public' por ora — a coluna já
     * existe para o PR de Stories VIP por tier estender depois.
     */
    public function up(): void
    {
        Schema::create('story_highlight_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_highlight_id')->constrained()->cascadeOnDelete();
            $table->string('media_path');
            $table->string('content_hash', 64);
            $table->string('visibility_level', 20)->default('public');
            $table->foreignId('source_story_id')->nullable()
                ->constrained('performer_stories')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['story_highlight_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_highlight_items');
    }
};
