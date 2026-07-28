<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carimbo do e-mail de boas-vindas dos fundadores (Sprint 9).
 *
 * É a trava de idempotência da carta: `KycService::approve()` é chamado por três
 * caminhos (webhook do Didit, painel admin web, admin da API), e uma
 * reaprovação — ou um retry do job na fila — não pode fazer o Robson e o Bruno
 * se apresentarem duas vezes à mesma pessoa. Uma carta pessoal repetida
 * desmente o próprio tom.
 *
 * Timestamp, e não boolean: além de responder "já foi?", diz QUANDO foi, que é
 * o que permite auditar um disparo em lote ou datar a reclamação de quem não
 * recebeu. Custa o mesmo.
 *
 * Fica FORA do `$fillable` do User (mesma regra de `discrete_mode` e do 2FA):
 * é controle interno, nunca payload de request. Quem escreve é o
 * SendWelcomeEmail, por forceFill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable()->after('age_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_email_sent_at');
        });
    }
};
