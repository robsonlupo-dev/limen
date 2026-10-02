<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 2 da encomenda sob medida (Onda 4, §4.3) — dois campos de TEXTO novos:
 *
 *  - `delivery_message`: recado opcional que a PERFORMER escreve ao entregar a peça
 *    (foto/vídeo). Passa pelo mesmo filtro anti-contato (SafeProfileText) da descrição
 *    do pedido — é texto que o membro lê. Nullable: entrega sem recado continua válida.
 *
 *  - `dispute_reason`: motivo OBRIGATÓRIO que o MEMBRO escreve ao contestar a entrega.
 *    Sem ele a disputa virava "recusou e sumiu" e a performer saía perdendo sem o
 *    moderador saber o que houve. Nullable no schema (linhas antigas não têm), mas
 *    exigido na porta de contestação (DisputeCustomOrderRequest). Também filtrado.
 *
 * Só o CustomOrderService escreve estes campos (por forceFill, dentro da transação da
 * transição) — ficam FORA do $fillable do model, como o resto do estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->text('delivery_message')->nullable()->after('performer_content_id');
            $table->text('dispute_reason')->nullable()->after('delivery_message');
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_message', 'dispute_reason']);
        });
    }
};
