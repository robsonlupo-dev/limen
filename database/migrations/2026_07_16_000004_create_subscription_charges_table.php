<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma linha por cobrança recorrente confirmada de uma assinatura. É o âncora de
 * idempotência do grant mensal: criar a linha (provider_event_id único) é o que
 * autoriza o crédito de tokens, então um webhook reprocessado nunca credita duas
 * vezes. provider_event_id guarda o id da cobrança (payment) do Asaas — estável
 * por período —, de modo que CONFIRMED e RECEIVED da mesma cobrança colidem e
 * só concedem uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id')->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('confirmed');
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_charges');
    }
};
