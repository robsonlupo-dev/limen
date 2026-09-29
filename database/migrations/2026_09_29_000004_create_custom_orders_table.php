<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encomenda de conteúdo sob medida com ESCROW (Onda 4, §4.3). O membro pede uma peça
 * (descrição + valor oferecido); a performer aceita ou recusa. No ACEITE, os tokens do
 * membro são DEBITADOS e ficam retidos (a linha é o registro do escrow); na ENTREGA a
 * performer vincula uma peça do cofre; depois de um prazo de contestação (ou aprovação
 * do membro) o dinheiro LIBERA 80/20; recusa/expiração/não-entrega ESTORNA 100%.
 *
 * Espelha o depósito da chamada agendada (`CallReservationService`): `escrow_settled` é
 * o INVARIANTE de idempotência — todo movimento one-time (release/refund) só ocorre com
 * escrow_settled=false e o marca true, sob `lockForUpdate`. Campos de máquina de estado
 * ficam FORA do $fillable (só o CustomOrderService escreve, por forceFill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->text('description');                 // pedido do membro (SafeProfileText)
            $table->unsignedInteger('offered_price_tokens');

            $table->enum('status', [
                'requested', 'accepted', 'delivered', 'released',
                'refunded', 'declined', 'disputed', 'cancelled', 'expired',
            ])->default('requested');

            // Peça entregue (do cofre, marcada como da encomenda). nullOnDelete: a peça
            // apagada deixa o ponteiro nulo sem derrubar a linha do pedido.
            $table->foreignId('performer_content_id')->nullable()
                ->constrained('performer_content')->nullOnDelete();

            // Ledger dos movimentos (auditoria; sem FK dura — o ledger é append-only).
            $table->unsignedBigInteger('spend_ledger_id')->nullable();
            $table->unsignedBigInteger('settle_ledger_id')->nullable();

            // Invariante de idempotência do escrow (release/refund uma vez só).
            $table->boolean('escrow_settled')->default(false);

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('dispute_deadline_at')->nullable(); // fim da janela de contestação
            $table->timestamp('resolved_at')->nullable();         // release/refund/decline/expire
            $table->timestamps();

            $table->index(['status', 'dispute_deadline_at']);
            $table->index(['member_id', 'status']);
            $table->index(['performer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_orders');
    }
};
