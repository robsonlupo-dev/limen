<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perks de privacidade de Black / Founders Circle: Ghost Mode (visita
     * invisível), Status Invisível (presença não exposta) e Read Receipts
     * (confirmação de leitura desligável).
     *
     * NULL não é "desligado" — é "nunca escolheu", e aí vale o padrão do tier
     * (PrivacyPerkService::effective). Um boolean NOT NULL com default forçaria
     * a escolher um padrão único para todo mundo, e o padrão aqui É o produto:
     * privado por omissão para quem paga Black/FC, público por omissão para os
     * outros. Guardar só a escolha EXPLÍCITA também deixa a intenção do usuário
     * distinguível do padrão vigente — se o produto mudar de ideia sobre o
     * padrão, quem escolheu à mão não é atropelado.
     *
     * Fora do $fillable do User de propósito, como discrete_mode: privilégio de
     * tier não pode entrar por mass assignment. A troca passa pelo service.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ghost_mode')->nullable()->after('discrete_mode');
            $table->boolean('invisible_status')->nullable()->after('ghost_mode');
            $table->boolean('read_receipts_enabled')->nullable()->after('invisible_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ghost_mode', 'invisible_status', 'read_receipts_enabled']);
        });
    }
};
