<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca uma peça de conteúdo como ENTREGA de uma encomenda sob medida (Onda 4, §4.3).
 * A presença de `custom_order_id` diz: esta peça NÃO é da vitrine pública — é privada da
 * encomenda, visível só para o membro que encomendou (via content_unlocks, criado na
 * entrega). O `ContentVisibilityService`/`galleryFor`/`forOwner` filtram `whereNull` para
 * ela nunca aparecer no catálogo nem no painel de conteúdo, e o unlock normal é negado.
 *
 * nullOnDelete: apagar o pedido não apaga a peça (a peça é conteúdo real); só solta o
 * ponteiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->foreignId('custom_order_id')->nullable()->after('performer_profile_id')
                ->constrained('custom_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_order_id');
        });
    }
};
