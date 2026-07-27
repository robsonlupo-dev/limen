<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos adicionais do perfil da performer (Sprint 9). Todos são
 * auto-declarados por ela e exibidos no perfil público — nenhum é coletado
 * pela plataforma nem cruza com o KYC.
 *
 * `languages` fica em json de propósito, ao contrário das tags: é uma faceta
 * rara de filtro e no máximo 7 valores, então a varredura do
 * `whereJsonContains` não paga uma tabela de junção. A decisão está no mesmo
 * lugar da R8 do backlog; se o filtro de idioma virar caminho quente, ele
 * migra para junção como as tags.
 *
 * `ethnicity` NÃO entra aqui: cortada do escopo pelo PO em 27/07/2026 por ser
 * dado pessoal sensível na LGPD (Art. 5º, II). Ver a ressalva registrada em
 * docs/MASTER_HANDOFF_FINAL.md — a ausência é deliberada, não lacuna.
 *
 * Os enums guardam o slug, nunca o rótulo: a tela traduz. Rótulo no banco
 * amarraria uma migration a cada ajuste de copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->json('languages')->nullable()->after('worlds');
            $table->enum('drinks', ['nao_bebe', 'bebe_socialmente', 'bebe_frequentemente'])->nullable()->after('languages');
            $table->enum('smokes', ['nao_fuma', 'fuma_socialmente', 'fuma'])->nullable()->after('drinks');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('smokes');
            $table->text('looking_for')->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn(['languages', 'drinks', 'smokes', 'height_cm', 'looking_for']);
        });
    }
};
