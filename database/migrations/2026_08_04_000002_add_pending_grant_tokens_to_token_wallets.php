<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila de franquia pendente do teto escalonado (M.13.8).
 *
 * Quando um `subscription_grant` não cabe sob o teto, credita o que couber e
 * PENDURA o excedente aqui. A pendência: (1) tem máximo de 1 franquia — ciclo novo
 * SUBSTITUI, não empilha; (2) NÃO expira; (3) é consumida automaticamente, parcial
 * se for o caso, a cada gasto (a `TokenCreditPolicy` a libera no débito, sob o lock
 * do wallet).
 *
 * É COLUNA, não tabela (o mais simples): o pending é estado de fila mutável,
 * análogo ao cache `balance` — NÃO é saldo e NUNCA entra no cálculo de saldo. O
 * saldo do membro só cresce quando a liberação grava uma linha `subscription_grant`
 * REAL no ledger append-only. Default 0 (toda carteira existente nasce sem fila).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_wallets', function (Blueprint $table) {
            $table->unsignedInteger('pending_grant_tokens')->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('token_wallets', function (Blueprint $table) {
            $table->dropColumn('pending_grant_tokens');
        });
    }
};
