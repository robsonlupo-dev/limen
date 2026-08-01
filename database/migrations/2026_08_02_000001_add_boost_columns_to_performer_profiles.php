<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boost pago (Sprint 11): a performer gasta tokens para destacar o perfil no
 * topo do catálogo por tempo limitado. Ver App\Services\BoostService e
 * config/boost.php.
 *
 * Um carimbo só, `boosted_until` — o INSTANTE em que o destaque acaba. Estado
 * DERIVADO na leitura (PerformerProfile::isBoosted): "boostado" é
 * `boosted_until` não-nulo E no futuro, exatamente como `available_for_chat_at`
 * e o `is_live` decidem presença na leitura. Sem job de expiração; o destaque
 * "vence" sozinho quando o relógio passa de `boosted_until`.
 *
 * Guardar o FIM (e não o início + duração) mantém a leitura trivial e imune a
 * uma mudança futura de `BOOST_DURATION_HOURS`: um boost já ativo não encurta
 * nem estica se o config mudar. É $hidden no model — o público só vê o booleano
 * `is_boosted`, nunca o carimbo (mesma disciplina de `available_for_chat_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->timestamp('boosted_until')->nullable()->after('available_for_chat_at');

            // O catálogo ordena "boostados primeiro" e o BoostService conta os
            // ativos (`boosted_until > now()`) para o teto de slots — as duas
            // leituras varrem esta coluna. Índice para o count não fazer full
            // scan e para o ORDER BY do catálogo se apoiar nele.
            $table->index('boosted_until');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropIndex(['boosted_until']);
            $table->dropColumn('boosted_until');
        });
    }
};
