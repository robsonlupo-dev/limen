<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Votos de uma enquete de story (roadmap social, Onda 1b).
 *
 * O que a performer vê a partir daqui é AGREGADO (distribuição por opção),
 * **nunca "quem votou"** — mesma disciplina de `story_views`/`story_reactions`. A
 * tabela existe para o `DISTINCT`/contagem por opção, não para virar lista. O
 * anonimato do membro é piso (princípio 1 do CLAUDE.md).
 *
 * ── Um voto por membro (o índice único) ─────────────────────────────────────
 * `UNIQUE(story_interaction_id, member_id)`: voto é IMUTÁVEL (como o Instagram) —
 * tocar de novo devolve o voto existente, não troca nem duplica. Sem isso o mesmo
 * membro empurraria a distribuição sozinho. Serve também de índice da contagem por
 * interação (prefixo da chave).
 *
 * `member_id` (não `user_id`) segue o nome dos sinais irmãos
 * (`story_reactions`/`performer_hearts`). FK para `users`.
 *
 * ── Ghost Mode / Modo Discreto NÃO votam no agregado ────────────────────────
 * Como a view e a reação: o voto de quem tem o perk não é gravado (write-time
 * guard no service, § 2.7). Sem isso, votos > views no mesmo story fingerprinta o
 * tier que comprou invisibilidade.
 *
 * Morre com a interação (FK cascade da interação, que por sua vez sai no
 * `destroy()` do story).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_interaction_id')->constrained('story_interactions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            // Índice da opção escolhida (0..N-1), validado contra o array `options`
            // da interação na aplicação. Tinyint: no máximo poucas opções.
            $table->unsignedTinyInteger('option_index');
            $table->timestamps();

            // Um voto por membro por enquete — ver o docblock.
            $table->unique(['story_interaction_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_poll_votes');
    }
};
