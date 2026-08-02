<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convite via Stories — Sprint 12 (Caminho 2 do Interesse Expandido).
     *
     * `is_invite` marca um Story como ISCA: quando `true`, o feed o exibe com
     * destaque extra para os SEGUIDORES que ainda não têm chat com a performer
     * (badge "💌 Convite" + CTA para o funil pago). Ver
     * PerformerStoryService::publish() e StoryVisibilityService::feedFor().
     *
     * ── É informação da PERFORMER, não do membro ────────────────────────────
     * A coluna diz apenas "esta publicação é um convite"; NÃO existe, e não pode
     * passar a existir, uma tabela de "quem recebeu o convite". O alvo é derivado
     * na LEITURA do feed (seguidor sem chat), nunca materializado — mesma
     * disciplina da ausência de linha em `profile_visits`/`story_views` (§ 2.7):
     * um mapa persistido de alvos seria o dossiê membro→performer que o produto
     * recusa. O selo é por espectador; a coluna é um booleano só.
     *
     * `default(false)` para que todo Story pré-existente e todo Story publicado
     * sem marcar a caixinha continue sendo um Story normal. Não indexado: o único
     * consumidor (o feed) já carrega os stories vivos da performer por outros
     * índices e filtra `is_invite` em memória sobre um conjunto pequeno.
     */
    public function up(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->boolean('is_invite')->default(false)->after('visibility_level');
        });
    }

    public function down(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->dropColumn('is_invite');
        });
    }
};
