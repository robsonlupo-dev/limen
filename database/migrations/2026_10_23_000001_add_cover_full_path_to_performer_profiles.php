<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa "inteira" (UAT rodada 5): além do recorte 3:1/2:1 que vira o banner do
 * topo (`cover_path`), guardamos uma cópia NÃO-recortada e só reduzida da foto
 * de capa, para o modal de ampliar mostrar a imagem toda (a faixa do topo mostra
 * pouco de uma foto retrato). Serving pela mesma rota assinada (`performer.media`,
 * type `cover-full`). Nullable: capas antigas e perfis sem capa seguem sem ela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->string('cover_full_path')->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('cover_full_path');
        });
    }
};
