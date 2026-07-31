<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Última atividade" da performer (Sprint 10): o carimbo do último request
 * autenticado dela, atualizado com throttle de 5 min pelo TrackPerformerActivity.
 *
 * O TIMESTAMP nunca sai da aplicação — ele é `$hidden` no User e nenhum resource
 * o expõe. O que o público vê é a FAIXA derivada por App\Support\ActivitySlot
 * ("Ativa hoje" / "esta semana" / "este mês"), nunca o relógio, pela mesma
 * disciplina do painel de visitantes e da ExpirySlot. A coluna existe só para
 * alimentar essa faixa.
 *
 * NULLABLE, com default null. `null` é "nunca registrou atividade" — o estado em
 * que toda conta existente nasce, e também o estado de quem tem Ghost Mode ou
 * Status Invisível ligados (a atualização é suprimida). A migration NÃO faz
 * backfill: inventar um "esteve ativa" para quem nunca gerou o carimbo seria
 * fabricar o sinal que a feature vende como real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')
                ->nullable()
                ->default(null)
                ->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
