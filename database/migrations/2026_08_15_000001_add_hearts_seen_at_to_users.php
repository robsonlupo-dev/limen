<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca d'água de "corações vistos" do MEMBRO (feat/activity-badges).
 *
 * Alimenta o contador de não-vistos ao lado de "Interessadas" na nav: o número é
 * a contagem de corações RECEBIDOS com `created_at` posterior a este carimbo.
 * Quando o membro abre a tela de corações, o carimbo vira `now()` e o contador
 * zera — mesma mecânica do `read_at` das mensagens, aqui como um único watermark
 * por membro em vez de uma coluna por linha de coração (o coração é sempre
 * visível ao membro; o que precisamos rastrear é só "já olhou desde quando").
 *
 * NULLABLE, default null. `null` é "nunca abriu a tela" — todo coração recebido
 * conta como novo até a primeira visita. Sem backfill: contas existentes nascem
 * `null` e o primeiro acesso à tela assenta o watermark. O carimbo é `$hidden` no
 * User e nunca sai como timestamp (só o CONTADOR derivado dele vai ao front).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('hearts_seen_at')
                ->nullable()
                ->default(null)
                ->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hearts_seen_at');
        });
    }
};
