<?php

use App\Support\BrazilianCities;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filtro de cidade CONSENTIDO (item 4 da fila de melhorias).
 *
 * A cidade da performer NUNCA foi (e continua NÃO sendo) publicada — só a UF
 * aparece no catálogo (invariante travado por PerformerLocationTest). O que
 * muda é que a performer pode OPTAR por ser "encontrável por cidade": um flag
 * default OFF, e só quem liga entra no resultado do filtro de cidade. Quem não
 * liga se comporta exatamente como hoje.
 *
 * Duas colunas:
 * - `performer_profiles.findable_by_city` — o opt-in (default false).
 * - `performer_locations.city_normalized` — chave de busca acento-insensível
 *   (o filtro casa por ela, não pela `city` crua, que pode ter acento/caixa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            // Opt-in de findability por cidade. Fora do $fillable (escrito só
            // pelo endpoint dedicado, como discrete_mode / available_for_chat_at).
            $table->boolean('findable_by_city')->default(false)->after('city');
        });

        Schema::table('performer_locations', function (Blueprint $table) {
            // Chave normalizada (sem acento, minúscula) que o filtro consulta.
            // Mantida em sincronia por PerformerLocationService::setLocations.
            $table->string('city_normalized', 120)->nullable()->after('city');
            $table->index('city_normalized');
        });

        // Backfill: normaliza a cidade já gravada. Em PHP para bater EXATAMENTE
        // com BrazilianCities::normalize (o SQL não tem strip de acento confiável).
        DB::table('performer_locations')
            ->whereNotNull('city')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('performer_locations')
                        ->where('id', $row->id)
                        ->update(['city_normalized' => BrazilianCities::normalize((string) $row->city)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('performer_locations', function (Blueprint $table) {
            $table->dropIndex(['city_normalized']);
            $table->dropColumn('city_normalized');
        });

        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('findable_by_city');
        });
    }
};
