<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca por-ciclo da franquia já concedida (M.13.4/M.13.8). É a chave que o
     * command de reconciliação `subscriptions:grant-monthly` e o webhook de
     * cobrança (recordChargeAndGrant) COMPARTILHAM para nunca conceder a mesma
     * franquia duas vezes: cada concessão grava aqui o current_period_start do
     * ciclo concedido; o command só concede ciclos ainda não marcados.
     *
     * NÃO é saldo nem dinheiro — é estado de controle. Fica FORA do $fillable do
     * model (só o servidor escreve, nunca um request).
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('last_grant_period_start')->nullable()->after('current_period_end');
        });

        // Backfill: toda assinatura EXISTENTE com um ciclo aberto já teve esse
        // ciclo concedido pelo caminho do webhook (recordChargeAndGrant concede E
        // abre o período no mesmo passo). Marcar o ciclo corrente como concedido
        // impede o command de re-conceder na primeira rodada pós-deploy. Marcar
        // linhas não-ativas também é inócuo — o filtro de status do command as
        // exclui de qualquer forma.
        DB::table('subscriptions')
            ->whereNotNull('current_period_start')
            ->update(['last_grant_period_start' => DB::raw('current_period_start')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_grant_period_start');
        });
    }
};
