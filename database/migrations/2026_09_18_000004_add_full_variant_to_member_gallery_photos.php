<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda variante de foto da galeria do membro (feat/member-profile-v2, Parte 4).
 *
 * O membro passa a ENQUADRAR a foto no upload (ImageCropper 3:4). Guardamos DUAS
 * variantes pós-sanitização:
 *  - a ENQUADRADA fica em `path`/`token` (o layout que já existia) — é o que
 *    preenche o card do catálogo e a miniatura do perfil;
 *  - a COMPLETA (sem corte) fica aqui, em `full_path`/`full_token` — é o que o
 *    lightbox do perfil serve em tela cheia (object-contain).
 *
 * Ambas passam pelo MESMO pipeline (strip EXIF/GPS + CsamScan) e são servidas por
 * TOKEN OPACO próprio (a mesma rota assinada resolve os dois tokens). Linhas
 * antigas (pré-v2) não têm variante completa → o lightbox cai na `path` (a
 * enquadrada), sem quebrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_gallery_photos', function (Blueprint $table) {
            // Caminho da variante COMPLETA no disco privado `local`. '' = sem
            // variante (linha antiga, ou foto recusada com bytes purgados).
            $table->string('full_path')->default('')->after('path');

            // Token OPACO próprio da variante completa (48 chars). Chaveia a URL
            // assinada do lightbox; nunca o id/user_id. UNIQUE, nullable para as
            // linhas antigas que não têm a variante.
            $table->string('full_token', 64)->nullable()->unique()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('member_gallery_photos', function (Blueprint $table) {
            $table->dropUnique(['full_token']);
            $table->dropColumn(['full_path', 'full_token']);
        });
    }
};
