<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinal (não bloqueio) de que a conta se cadastrou com um CPF/documento que já
 * esteve na lista negra antifraude. Deliberadamente NÃO barra o cadastro — a
 * mesma disciplina do shared-IP flag: sinaliza para a fila humana decidir, sem
 * negar acesso automaticamente (CPF pode ser reciclado, e um falso-positivo que
 * tranca o acesso é pior que um alerta que um humano descarta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('blacklist_hit')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('blacklist_hit');
        });
    }
};
