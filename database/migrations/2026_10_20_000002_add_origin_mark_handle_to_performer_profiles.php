<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de origem (Onda B §4): escolha da performer entre "Limen" e "Limen · @slug"
 * na marca d'água de branding das fotos. Default `true` = "Limen · @slug"
 * (divulgação, padrão do Fatal Model); a performer troca no Editar perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->boolean('origin_mark_handle')->default(true)->after('headline');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('origin_mark_handle');
        });
    }
};
