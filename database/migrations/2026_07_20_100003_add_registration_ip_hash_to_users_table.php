<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IP de cadastro da PERFORMER, como HMAC (ver App\Support\ClientFingerprint).
     *
     * Motivo jurídico: várias performers cadastradas do mesmo IP pode indicar
     * rede de exploração — alguém cadastrando pessoas sob coerção. O sinal é
     * fraco sozinho (NAT de operadora, universidade, lan house e mesmo prédio
     * compartilham IP legitimamente), então serve para revisão humana, nunca
     * para bloqueio automático.
     *
     * Só performer preenche. Membro fica NULL de propósito: a hipótese é sobre
     * quem é recrutado para produzir conteúdo, e coletar o IP de cadastro de
     * todo mundo seria coleta de dado pessoal sem finalidade declarada (LGPD
     * pede finalidade específica, não "pode ser útil um dia").
     *
     * Nullable também porque cadastro fora de request HTTP (seeder, console,
     * factory) não tem IP real: gravar o 127.0.0.1 do console faria a massa
     * sintética inteira colidir num hash só e nascer sinalizada.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // sha256 hex: o cru não é necessário para detectar colisão —
            // comparar digests responde a mesma pergunta.
            //
            // ATENÇÃO ao alcance dessa proteção: ela vale para ESTA coluna. O
            // `Audit::log('auth.register_performer')` do mesmo request grava o
            // IP em texto puro em `audit_logs.ip`, então hoje dá para
            // correlacionar performers por IP sem a APP_KEY. Ver
            // docs/SECURITY_ISSUES.md — é decisão pendente do PO, não descuido.
            $table->char('registration_ip_hash', 64)->nullable()->after('status');

            // Índice, não unique: IP repetido é exatamente o que procuramos, e
            // o agrupamento por hash é a query quente do painel.
            $table->index('registration_ip_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['registration_ip_hash']);
            $table->dropColumn('registration_ip_hash');
        });
    }
};
