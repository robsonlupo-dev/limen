<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fã-Clube (Onda 4, `docs/FORK_ASSINATURA.md` §1.1). O "set de fã-clube" é um BALDE
 * novo de conteúdo da performer, separado do cofre normal (Aberto/Premium/Exclusivo/
 * FC-Only) e do PPV. Marcado por `fanclub = true`.
 *
 * Disciplina (espelha `custom_order_id`): peça de fã-clube NÃO vaza na vitrine pública
 * nem no painel de conteúdo (galleryFor/forOwner filtram `fanclub = false`) e NÃO é
 * desbloqueável por peça (denialForUnlock recusa). O acesso é só a ASSINATURA ativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->boolean('fanclub')->default(false)->after('custom_order_id');
            $table->index(['performer_profile_id', 'fanclub']);
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropIndex(['performer_profile_id', 'fanclub']);
            $table->dropColumn('fanclub');
        });
    }
};
