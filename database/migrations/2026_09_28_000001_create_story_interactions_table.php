<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interação presa a um story (roadmap social, Onda 1b): hoje ENQUETE (poll).
 *
 * Uma interação por story (`UNIQUE(performer_story_id)`), como o sticker do
 * Instagram. `type` já nasce genérico ('poll' agora; 'question'/"pergunte-me" na
 * PR seguinte, que rota a resposta pro chat pago e não guarda texto aqui), para o
 * próximo tipo não precisar de migração. `options` é JSON só para poll (as
 * alternativas que a performer escreveu); nulo nos outros tipos.
 *
 * `prompt` e `options` são texto DA PERFORMER e passam pelo filtro anti-contato
 * (SafeProfileText) na aplicação — uma opção de enquete não pode virar "meu zap
 * é...". String, não enum de banco, pelo mesmo motivo dos outros: o conjunto de
 * tipos tem dona na aplicação.
 *
 * ── Exclusivo não tem interação (decisão nº 3) ──────────────────────────────
 * Enquete é superfície de audiência (a performer vê a distribuição); no story
 * exclusivo isso revelaria o tamanho do público Black — o mesmo oráculo que tira
 * o contador do Nível 3. O bloqueio é na aplicação (attach recusa exclusivo).
 *
 * Morre com o story: FK cascade cobre hard delete; o `stories:purge` soft-deleta
 * (item 11 do CLAUDE.md), então o `PerformerStoryService::destroy()` apaga a
 * interação junto (e os votos vão por cascade da interação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_story_id')->constrained('performer_stories')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('prompt', 120);
            $table->json('options')->nullable();
            $table->timestamps();

            // Uma interação por story — ver o docblock.
            $table->unique('performer_story_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_interactions');
    }
};
