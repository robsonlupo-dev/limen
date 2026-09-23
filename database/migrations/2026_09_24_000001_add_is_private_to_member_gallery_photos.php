<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilidade POR FOTO na galeria do membro (feat/member-gallery-per-photo-privacy,
 * Etapa 1). Espelha o `is_private` da galeria da performer: foto ABERTA é vista por
 * qualquer performer que já vê o perfil; foto PRIVADA sai borrada e só abre para
 * quem o membro liberar (o fluxo de pedir→liberar vem na Etapa 2).
 *
 * Default PRIVADA (decisão do PO): num site adulto, foto nova nasce trancada e o
 * membro abre conscientemente — ninguém expõe o rosto por esquecer de trancar.
 *
 * Backfill: as fotos que JÁ existem vêm do modelo antigo tudo-ou-nada, em que toda
 * aprovada era vista quando `profile_visible` estava ON. Marcá-las privadas as
 * sumiria de perfis já publicados; então o backfill as deixa PÚBLICAS, preservando
 * o comportamento atual. Só upload NOVO herda o default privado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_gallery_photos', function (Blueprint $table) {
            $table->boolean('is_private')->default(true)->after('status');
        });

        // Tudo que já existe era "público" no modelo antigo — não retro-esconder.
        DB::table('member_gallery_photos')->update(['is_private' => false]);
    }

    public function down(): void
    {
        Schema::table('member_gallery_photos', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
