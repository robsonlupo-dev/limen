<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso de uma PERFORMER às fotos PRIVADAS de um MEMBRO
 * (feat/member-gallery-access-requests, Etapa 2). Espelho invertido do
 * `photo_grants` da galeria da performer, com uma diferença de granularidade
 * (decisão do PO): o acesso é POR PAR (membro↔performer), não por foto — liberar
 * uma performer abre TODAS as fotos privadas do membro para ela; revogar re-tranca
 * todas.
 *
 * `granted_at` discrimina os dois estados: NULL = pedido pendente (a performer
 * solicitou), preenchido = liberado (o membro aprovou). Uma linha por par
 * (unique) — reenviar o pedido é no-op idempotente, sem duplicata a desempatar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_gallery_access', function (Blueprint $table) {
            $table->id();

            // O DONO das fotos (o membro). cascadeOnDelete cobre a remoção REAL da
            // conta; o Hard Delete LGPD (anonimização) varre explicitamente, como
            // na própria galeria.
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();

            // A performer que pediu / recebeu acesso. Chaveado pelo PERFIL (não
            // pelo user) — é a identidade pública dela, a mesma do catálogo.
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();

            // NULL enquanto pendente; preenchido quando o membro libera.
            $table->timestamp('granted_at')->nullable();

            $table->timestamps();

            // Um pedido/concessão por par (idempotência).
            $table->unique(['member_id', 'performer_profile_id']);
            // A caixa de pedidos do membro lê pendentes/concedidos por member_id.
            $table->index(['member_id', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_gallery_access');
    }
};
