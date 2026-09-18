<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in do PERFIL VISÍVEL do membro (feat/member-gallery-and-profile, Opção B).
 *
 * Default OFF (privacidade primeiro): sem isto ligado o membro segue sendo só
 * FanAlias/apelido no catálogo, como hoje — a galeria e a página de perfil só
 * ficam acessíveis à performer com o interruptor ON. É um gate SEPARADO de
 * `visible_to_performers` (que decide se o membro APARECE no catálogo): aparecer
 * no catálogo e expor a galeria/perfil são consentimentos distintos.
 *
 * Fora do $fillable no model (forceFill por endpoint próprio, como Modo Discreto
 * e lifestyle_tier): nunca entra por payload genérico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('profile_visible')->default(false)->after('nickname_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_visible');
        });
    }
};
