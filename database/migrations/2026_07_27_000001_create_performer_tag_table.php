<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags da performer numa tabela de junção, e não num `tags` json[] na
 * performer_profiles. É a resposta à ressalva R8 do backlog do Sprint 9:
 * `whereJsonContains` não usa índice, e o catálogo combina ~12 facetas por
 * request — o filtro por tag viraria full scan. Aqui o filtro é
 * `whereHas('tags', fn ($q) => $q->where('tag_slug', ...))`, que entra pelo
 * índice de `tag_slug`.
 *
 * Não há tabela `tags`: o slug é o próprio valor. O conjunto válido é
 * PerformerProfile::TAGS e a validação é do Form Request, não do banco —
 * acrescentar uma tag ao catálogo não deve exigir migration.
 *
 * Os dois índices têm papéis distintos:
 *  - o único (performer_profile_id, tag_slug) impede a mesma tag duas vezes no
 *    mesmo perfil e serve a leitura "as tags DESTA performer";
 *  - o de `tag_slug` sozinho serve a direção inversa, que é a do filtro do
 *    catálogo ("quem tem a tag X"), e o composto não a cobre porque
 *    performer_profile_id é a coluna à esquerda.
 *
 * `cascadeOnDelete` é rede de segurança, não o caminho real: `performer_profiles`
 * usa SoftDeletes, então a FK nunca dispara na exclusão de conta. Quem apaga as
 * tags no Hard Delete é DeletionService::anonymizePerformerProfile(). Mesma
 * armadilha registrada no item 11 do CLAUDE.md para profile_visits — não
 * escreva código contando com o cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_tag', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->string('tag_slug', 50);

            $table->unique(['performer_profile_id', 'tag_slug']);
            $table->index('tag_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_tag');
    }
};
