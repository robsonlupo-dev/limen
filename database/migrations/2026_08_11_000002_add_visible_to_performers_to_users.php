<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilidade do membro no catálogo de membros da performer (Sprint 16).
 *
 * TRI-STATE de propósito (decisão do PO): a coluna é NULLABLE, sem default no
 * banco — `null` = "nunca escolheu". O efetivo é resolvido em
 * User::isVisibleToPerformers():
 *   - valor explícito (true/false) manda;
 *   - `null` → padrão POR TIER: Black/FC ocultos, demais visíveis.
 *
 * BACKFILL das contas existentes para `true`: a regra do PO é "membros
 * existentes mantêm o default ON e podem desligar". Sem o backfill, um Black/FC
 * já existente cairia no padrão-por-tier (oculto) e sumiria do catálogo sem ter
 * pedido — reexpor/esconder por lapso é o que a regra proíbe. Contas NOVAS
 * nascem `null` (a coluna não tem default), então só quem virar Black/FC daqui
 * pra frente e nunca mexer no toggle nasce oculto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('visible_to_performers')->nullable()->after('interests_opt_out');
        });

        // Contas existentes: escolha EXPLÍCITA = visível. Mantém o ON de quem já
        // estava no ar (inclusive Black/FC atuais), como manda o PO.
        DB::table('users')->update(['visible_to_performers' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('visible_to_performers');
        });
    }
};
