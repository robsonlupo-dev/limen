<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Galeria de fotos do perfil da performer — Sprint 10.
     *
     * Conteúdo PÚBLICO do perfil, na mesma categoria que `avatar_path`/`cover_path`
     * (que vivem em colunas de `performer_profiles`): é a vitrine que a performer
     * ESCOLHEU publicar, não dado sensível. Por isso, ao contrário da Foto Efêmera
     * do membro (§ 1 do CLAUDE.md), aqui NÃO há cifra — decisão nº R1 do PO: o
     * re-encode do `ImageProcessingService` mata EXIF/GPS e polyglot no mesmo passo,
     * e o original cru não é guardado. Mesma estratégia de storage dos Stories, e
     * pelo mesmo motivo (1-para-MUITOS, servida a cada visitante do perfil): disco
     * próprio com `serve => false`, servida por request — ver PerformerPhotoStore.
     *
     * ── `path` é TEXT, não VARCHAR ─────────────────────────────────────────────
     * É caminho gerado pelo Store (id do perfil + nome aleatório), nunca entrada de
     * usuário. Mesma escolha de `performer_stories.media_path`.
     *
     * ── O índice (performer_profile_id, position) ──────────────────────────────
     * É a ordem do carrossel, e toda leitura da galeria é "as fotos deste perfil,
     * na ordem". O composto cobre o `where` + o `order by` numa varredura só.
     *
     * ── `cascadeOnDelete` que NUNCA dispara ────────────────────────────────────
     * Declarado por higiene de schema (espelha `performer_stories`), mas o
     * encerramento de conta é soft-delete/anonimização do perfil — a FK não
     * cascateia (item 11 do CLAUDE.md). A varredura real vive no DeletionService,
     * nos dois sentidos. Sem cifra, o cap de 6 é a única contenção de volume; ver
     * PerformerPhotoService::MAX_PHOTOS.
     */
    public function up(): void
    {
        Schema::create('performer_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            // Caminho no disco `performer_photos`. Gerado pelo Store, sem cifra.
            $table->text('path');
            // Ordem no carrossel. unsigned porque posição não é negativa; a
            // reordenação reescreve este campo (ver PerformerPhotoService::reorder).
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['performer_profile_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_photos');
    }
};
