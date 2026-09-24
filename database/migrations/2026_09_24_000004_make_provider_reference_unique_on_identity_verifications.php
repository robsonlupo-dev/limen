<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `identity_verifications.provider_reference` passa a ser ÚNICO
 * (security/hardening-followups).
 *
 * O webhook da Didit resolve a verificação por `provider_reference` com
 * `->first()`. Com índice apenas comum, duas linhas com a mesma sessão fariam a
 * decisão cair numa delas ao acaso — a errada poderia ser aprovada. Cada sessão
 * Didit é única por tentativa (reenvio = sessão nova), então a unicidade é a
 * regra do domínio, não uma restrição extra. Coluna nullable: vários NULL
 * (verificação manual, sem provedor) continuam permitidos pelo MySQL.
 *
 * Substitui o índice comum de 2026_06_29 pelo único; se algum dado de dev tiver
 * duplicata, a migration falha alto — que é o comportamento desejado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            // Único PRIMEIRO: DDL do MySQL não é transacional — se houver
            // duplicata, falha aqui com o índice antigo intacto e a migration
            // continua re-executável depois da limpeza.
            $table->unique('provider_reference');
            $table->dropIndex(['provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->index('provider_reference');
            $table->dropUnique(['provider_reference']);
        });
    }
};
