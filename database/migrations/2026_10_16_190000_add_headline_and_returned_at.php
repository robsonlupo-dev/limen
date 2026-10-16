<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frase de destaque (headline) no perfil + carimbo de "voltou" (returned_at).
 * Roadmap Fatal Model, Onda A, itens 1 e 2 do card (frase de destaque + selo
 * "Voltou").
 *
 * - `performer_profiles.headline`: tagline curta PÚBLICA (teto 40 na validação,
 *   coluna 60 por folga), exibida no card e no perfil. Classe do apelido/bio:
 *   validada por SafeProfileText (barra contato).
 * - `users.returned_at`: carimbo setado pelo TrackPerformerActivity quando a
 *   performer volta a ficar ativa depois de > 30 dias inativa. $hidden como o
 *   last_active_at; o público só vê o BOOLEANO derivado (ReturnedBadge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->string('headline', 60)->nullable()->after('looking_for');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('headline');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('returned_at');
        });
    }
};
