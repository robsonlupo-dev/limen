<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reações rápidas a stories (roadmap social, Onda 1b). Sinal leve membro→performer
 * (❤️🔥…), sem abrir chat — complementa o "responder ao story".
 *
 * ── O que a performer vê a partir daqui é AGREGADO, nunca lista (decisão do PO) ─
 * Mesma disciplina de `story_views` (§ 2.1): esta tabela existe para o CONTADOR
 * (faixa de membros únicos) e o conjunto de emojis usados, **não** para virar
 * "quem reagiu". O anonimato do membro é piso (CLAUDE.md princípio 1); uma lista
 * por FanAlias é decisão de produto adiada, não o default. E, como o contador de
 * views, a superfície de reação **não existe no nível `exclusive`**: contador
 * sobre público Black seria o oráculo de identificabilidade que a decisão nº 3
 * fecha (o serving da reação recusa o exclusivo; ver StoryReactionService).
 *
 * ── O índice único É a regra "uma reação por membro" ────────────────────────
 * `UNIQUE(performer_story_id, member_id)`: trocar de reação atualiza a MESMA
 * linha, tocar a mesma remove. Sem isso o mesmo membro empilharia reações e
 * inflaria o contador — o mesmo cuidado do par de `story_views` e de
 * `performer_hearts`. Serve também de índice do contador por story (prefixo da
 * chave).
 *
 * `member_id` (não `user_id`) segue o nome do sinal irmão `performer_hearts`: é o
 * membro sinalizando a performer. FK para `users`.
 *
 * ── Retenção ────────────────────────────────────────────────────────────────
 * A reação morre com o story: o `cascadeOnDelete` cobre o hard delete, mas o
 * `stories:purge` soft-deleta a linha do story (item 11 do CLAUDE.md — FK não
 * dispara em UPDATE de `deleted_at`), então quem apaga as reações junto é o
 * `PerformerStoryService::destroy()`, ao lado das views. Não conte com a FK aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_story_id')->constrained('performer_stories')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            // Slug da reação (love/fire/…). String e não enum de banco: o conjunto
            // vive em config/stories.php (fonte única), validado na aplicação — um
            // enum aqui seria uma segunda lista para divergir ao mudar o conjunto.
            $table->string('reaction', 20);
            $table->timestamps();

            // Uma reação por membro por story — ver o docblock.
            $table->unique(['performer_story_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reactions');
    }
};
