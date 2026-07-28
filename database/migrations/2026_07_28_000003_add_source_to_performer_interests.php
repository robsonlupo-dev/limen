<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De onde partiu o interesse (Sprint 9).
 *
 * Até aqui a única origem era a lista de SEGUIDORES, então a coluna não
 * existia. O painel de visitantes vira a segunda, e as duas precisam ser
 * distinguíveis por uma razão concreta: o PO definiu cotas diárias SEPARADAS
 * (5 para seguidores, 3 para visitantes), e sem a coluna a contagem do dia não
 * tem como separar uma da outra.
 *
 * `default('follower')` cobre as linhas já gravadas: toda linha anterior a esta
 * migration veio da lista de seguidores, então o default não é chute — é o
 * valor correto para o histórico inteiro.
 *
 * A origem NUNCA chega ao membro. A caixa dele mostra "uma performer demonstrou
 * interesse" e nada mais; saber que o interesse nasceu de uma visita diria a
 * ele que suas visitas são visíveis para a performer, e essa é uma revelação
 * que este PR não tem mandato para fazer. Ver PerformerInterestResource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_interests', function (Blueprint $table) {
            $table->enum('source', ['follower', 'visitor'])
                ->default('follower')
                ->after('member_id');

            // A cota diária passa a ser contada POR ORIGEM. O índice existente
            // (performer_profile_id, sent_at) deixa de cobrir a consulta, que
            // agora filtra por source no meio — daí o composto próprio.
            $table->index(['performer_profile_id', 'source', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('performer_interests', function (Blueprint $table) {
            $table->dropIndex(['performer_profile_id', 'source', 'sent_at']);
            $table->dropColumn('source');
        });
    }
};
