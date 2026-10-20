<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rosto borrado (Onda B §4): botão global "borrar meu rosto em tudo". Quando ligado, as
 * PRÓXIMAS fotos nascem com a opção de borrar o rosto marcada por padrão (ela ainda
 * posiciona o oval). Padrão DESLIGADO — por decisão de produto, a foto vem desbloqueada e
 * a performer sempre escolhe. Não age sobre o acervo já publicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->boolean('blur_face_default')->default(false)->after('origin_mark_handle');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('blur_face_default');
        });
    }
};
