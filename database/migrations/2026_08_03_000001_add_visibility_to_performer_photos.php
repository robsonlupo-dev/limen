<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilidade por foto da galeria (Sprint 13).
 *
 * Até aqui toda foto da galeria era PÚBLICA (Sprint 10, vitrine do perfil).
 * Agora a performer pode marcar uma foto como PRIVADA: ela aparece borrada no
 * perfil público e só quem tem `photo_grant` aprovado (ou a própria dona) vê os
 * bytes. Inspirado no "Permissões para fotos e vídeos" do Seeking.
 *
 * `is_private` fica FORA do `$fillable` do model (muda só pelo endpoint dedicado
 * de visibilidade, por atribuição direta no PhotoAccessService — mesma disciplina
 * anti mass-assignment de `discrete_mode`/2FA). Default false: toda foto que já
 * existe continua pública, sem backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_photos', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('performer_photos', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
