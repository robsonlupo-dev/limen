<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story VIP por tier mínimo (roadmap social, Onda 1) — coluna `min_tier`.
 *
 * NULL = comportamento de hoje: o nível decide sozinho quem alcança o story.
 * Preenchido = refinamento ORTOGONAL ao nível: além de passar pelo nível, o
 * membro precisa de um Círculo ativo de `min_tier` ou acima (comparação por
 * RANK, `Circle::tierAtLeast`, fail-closed). Só o nível `subscribers` aceita a
 * coluna (ver `PerformerStory::SUBSCRIBER_MIN_TIERS` e o guard de `publish()`).
 *
 * String e não enum de banco: os slugs de tier já têm dona única em
 * `Circle::TIER_ORDER`, e um enum aqui seria uma segunda lista para divergir na
 * hora em que um tier novo entrar. A validação é da aplicação (Form Request +
 * service), como o `visibility_level`.
 *
 * Sem índice próprio: `min_tier` é sempre predicado SECUNDÁRIO, aplicado sobre
 * um conjunto que os índices de `performer_profile_id`/`expires_at` já
 * estreitaram — um índice de baixa cardinalidade aqui custaria escrita sem
 * pagar leitura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->string('min_tier', 20)->nullable()->after('visibility_level');
        });
    }

    public function down(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->dropColumn('min_tier');
        });
    }
};
