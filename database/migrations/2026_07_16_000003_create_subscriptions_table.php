<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('circle_id')->constrained();
            $table->string('asaas_subscription_id')->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'past_due', 'canceled', 'expired'])->default('pending');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->date('next_due_date')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedInteger('price_cents');

            // Card: NUNCA o PAN. Só o token reusável do Asaas (cifrado em repouso
            // via cast 'encrypted' no model) + os 4 últimos dígitos e a bandeira,
            // que são exibíveis e não são dado de cartão sensível.
            $table->text('card_token')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand', 30)->nullable();

            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            // Um único registro ATIVO por usuário. active_lock = user_id enquanto
            // status='active', senão NULL (mantido pelo model, ver Subscription::booted).
            // O índice único deixa passar múltiplos NULL (histórico de canceladas/
            // expiradas) mas barra dois ativos para o mesmo usuário.
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->unique('active_lock');

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
