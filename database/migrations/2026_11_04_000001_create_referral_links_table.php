<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links personalizados de indicação (feat/referral-custom-links), estilo
     * Airbnb: além do código padrão (?ref=LM-XXXXX), o usuário cria até N
     * apelidos de link (thelimen.com.br/r/{slug}) que apontam para a própria
     * indicação.
     *
     * Decisões (espelham o Airbnb, registradas no mockup aprovado):
     * - IMUTÁVEIS: não há UPDATE nem DELETE de slug — link publicado num post
     *   antigo nunca morre nem troca de dono. A escrita passa SÓ pelo
     *   ReferralLinkService (criação); nenhuma rota altera/apaga.
     * - Teto por usuário em config('referral.custom_links_max') (2).
     * - `slug` UNIQUE global, minúsculo ([a-z0-9-], 3–20). A validação profunda
     *   (reservadas, anti-personificação de criadora, contato, conduta) vive no
     *   service, com resposta genérica anti-oráculo.
     * - FK cascadeOnDelete: Hard Delete LGPD do usuário leva os links junto
     *   (o slug volta ao pool — conta apagada não "aposenta" nome público).
     */
    public function up(): void
    {
        Schema::create('referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 20)->unique();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_links');
    }
};
