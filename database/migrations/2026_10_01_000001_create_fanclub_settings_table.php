<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fã-Clube da performer (Onda 4 — fork da assinatura, `docs/FORK_ASSINATURA.md` §3/§9).
 * A CONFIG do fã-clube de cada performer: se está aberto e os preços (em token).
 *
 * - `price_public_tokens` — preço que TODOS pagam por padrão (free, …, Black, FC).
 * - `vip_enabled` + `price_vip_tokens` — desconto OPCIONAL da performer para Black/FC.
 *   Trava de negócio (no service E no Form Request): `price_vip_tokens <= price_public_tokens`
 *   (público ≥ VIP). NUNCA a plataforma força o desconto; é escolha dela.
 *
 * Uma linha por performer (UNIQUE). Campos escritos só pelo FanclubService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fanclub_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_open')->default(false);
            $table->unsignedInteger('price_public_tokens')->nullable(); // null = não configurado
            $table->boolean('vip_enabled')->default(false);
            $table->unsignedInteger('price_vip_tokens')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fanclub_settings');
    }
};
