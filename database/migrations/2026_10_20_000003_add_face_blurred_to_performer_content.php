<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rosto borrado (Onda B §4): metadado de que a peça teve o rosto coberto no upload. O
 * blur é QUEIMADO nos bytes — esta coluna é só para selo/exibição; a REGIÃO não é
 * guardada (privacidade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->boolean('face_blurred')->default(false)->after('shows_face');
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropColumn('face_blurred');
        });
    }
};
