<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interesses do MEMBRO, na mesma forma das tags da performer: tabela de junção
 * indexada, e não um `interests` json[] em `users`. A razão é a mesma da R8 que
 * decidiu `performer_tag` — `whereJsonContains` não usa índice, e o cruzamento
 * de afinidade do Sprint 10 vai ler esta tabela pela DIREÇÃO INVERSA ("quem se
 * interessa por X"), que é exatamente onde o json[] vira full scan.
 *
 * Não há tabela `tags`: o slug é o próprio valor, e o conjunto válido é o MESMO
 * da performer (PerformerProfile::allTags()). Duas listas separadas — uma de
 * tags, outra de interesses — tornariam o cruzamento impossível já no primeiro
 * slug que existisse só de um lado.
 *
 * Os dois índices repetem os papéis do `performer_tag`:
 *  - o único (user_id, tag_slug) impede o mesmo interesse duas vezes no mesmo
 *    membro e serve a leitura "os interesses DESTE membro" (a tela de edição);
 *  - o de `tag_slug` sozinho serve a direção inversa, e o composto não a cobre
 *    porque `user_id` é a coluna à esquerda.
 *
 * `cascadeOnDelete` é rede de segurança, não o caminho real: `users` usa
 * SoftDeletes, então a FK nunca dispara no encerramento de conta. Quem apaga os
 * interesses no Hard Delete é DeletionService::purgeMemberInterests(). Mesma
 * armadilha registrada no item 11 do CLAUDE.md para profile_visits e repetida
 * nas tags da performer — não escreva código contando com o cascade.
 *
 * PRIVACIDADE: esta tabela nunca é lida por uma superfície da performer. Os
 * interesses do membro existem para o cruzamento de afinidade (Sprint 10) e
 * para filtros do catálogo — a decisão está registrada no cabeçalho do
 * App\Models\MemberInterest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_interest', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tag_slug', 50);

            $table->unique(['user_id', 'tag_slug']);
            $table->index('tag_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_interest');
    }
};
