<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido + concessão de acesso a uma foto PRIVADA da galeria (Sprint 13).
 *
 * UMA linha por par (foto, membro), e o `granted_at` é o que distingue os dois
 * estados que o fluxo tem — sem uma segunda tabela de "pedidos":
 *   - `granted_at` NULL      → PEDIDO pendente (o membro clicou "Solicitar acesso");
 *   - `granted_at` preenchido → ACESSO concedido (a performer aprovou).
 * O `created_at` é quando o membro pediu; o `granted_at`, quando ela aprovou.
 * Revogar/recusar é DELETE da linha (o par (foto, membro) volta ao estado zero).
 *
 * PRIVACIDADE (as invariantes do FanAlias e do Hard Delete valem aqui):
 *  - `user_id` é chave interna e NUNCA sai para o front — a performer vê o membro
 *    só por `FanAlias` (label + handle). É `$hidden` no model PhotoGrant.
 *  - Solicitar é um ato DELIBERADO do membro (como uma gorjeta), então o pedido
 *    expõe o membro à performer por FanAlias, sem Piso de Anonimato — mas NÃO
 *    cria Follow nem qualquer outro vínculo.
 *  - As duas FKs são `cascadeOnDelete`, mas o Hard Delete NÃO conta com o cascade
 *    do lado do MEMBRO: `anonymizeUser()` só soft-deleta o `users`, então a linha
 *    é varrida explicitamente por `user_id` (item 11 do CLAUDE.md). Do lado da
 *    PERFORMER o cascade dispara de fato — `purgePerformerPhotos` faz DELETE real
 *    das fotos, e a linha de grant cai junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('performer_photo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // NULL enquanto o pedido está pendente; preenchido quando a performer
            // aprova. Ver o cabeçalho — é o discriminador dos dois estados.
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            // Um pedido/concessão por par: reenviar o pedido é no-op (upsert
            // idempotente no service), e não há linha duplicada a desempatar.
            $table->unique(['performer_photo_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_grants');
    }
};
