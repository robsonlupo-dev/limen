<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixar conteúdo na vitrine (roadmap social, Onda 3 — § 3.1). A performer prende
 * peças no TOPO da vitrine (público e painel). Fonte ÚNICA da verdade: `pinned_at`
 * (nullable). Fixado = `pinned_at IS NOT NULL`; a ordem entre fixados é o próprio
 * `pinned_at` desc (o último fixado sobe). Sem bool separado — um `is_pinned` que
 * derivasse do timestamp poderia divergir dele.
 *
 * O teto de fixados (config/content.php `max_pinned`) é regra de serviço, não de
 * schema. O índice cobre a consulta de ordenação por performer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('price_tokens');
            $table->index(['performer_profile_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropIndex(['performer_profile_id', 'pinned_at']);
            $table->dropColumn('pinned_at');
        });
    }
};
