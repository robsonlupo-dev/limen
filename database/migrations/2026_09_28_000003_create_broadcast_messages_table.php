<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de transmissão da performer (roadmap social, Onda 2). Mensagem
 * 1-para-muitos para os SEGUIDORES (`follows`), sem resposta no canal.
 *
 * ── Só texto na v1 ──────────────────────────────────────────────────────────
 * Sem mídia por ora (decisão do PO): imagem exigiria re-scan CSAM + storage +
 * re-encode como stories/destaques, e o servidor é de 2 vCPU. `body` é texto DA
 * performer e passa pelo filtro anti-contato (SafeProfileText) na aplicação — um
 * broadcast é o vetor perfeito de fuga de contato grátis (mandar o zap a todos os
 * seguidores de uma vez), então o filtro é ainda mais crítico aqui que na bio.
 *
 * ── Persistência, não fila efêmera ──────────────────────────────────────────
 * A mensagem FICA: o seguidor que estava offline lê depois, na aba "Canais". É o
 * que torna o canal um motor de retenção, não um sino que toca no vazio. (O push
 * em tempo real via Reverb fica para uma PR seguinte — o servidor atual não
 * comporta fan-out largo, e a retenção vem do persistido + o badge de não-visto.)
 *
 * Índice por (performer, id) para a listagem "as últimas desta performer" e para
 * o feed do seguidor (mais recentes primeiro) não varrer a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();

            $table->index(['performer_profile_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_messages');
    }
};
