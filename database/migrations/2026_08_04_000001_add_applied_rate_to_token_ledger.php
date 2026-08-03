<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congelamento da taxa de split na linha do ledger (M.13.7 item 1-2).
 *
 * `applied_rate` guarda, em INTEIRO (70/75/80), a taxa usada no split percentual
 * daquela transação. É gravada no crédito e NUNCA recalculada: o `amount` da linha
 * continua sendo a fonte de verdade do valor creditado; a taxa é auditoria e o que
 * torna a linha reproduzível mesmo depois de a config mudar.
 *
 * NULLABLE: linhas anteriores ao PR (e todo crédito que não é split percentual —
 * subscription_grant, purchase, chat fixo, refund, etc.) ficam com `null`. Quem lê
 * relatório deve tratar `null` como "sem taxa de split" (não dividir por ela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_ledger', function (Blueprint $table) {
            $table->unsignedTinyInteger('applied_rate')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('token_ledger', function (Blueprint $table) {
            $table->dropColumn('applied_rate');
        });
    }
};
