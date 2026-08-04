<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo FIXO de presentes da Limen (M.12/M.13.6). Preços definidos pela
     * plataforma (não pela performer), em múltiplos de 4 tokens (invariante
     * validada em TokenCreditPolicy::isValidGiftPrice). Reference data pura,
     * semeada por GiftSeeder (idempotente, roda em produção). `active` desativa
     * um presente sem apagar a linha — a FK de gift_sends guarda o histórico.
     */
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('price_tokens');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
