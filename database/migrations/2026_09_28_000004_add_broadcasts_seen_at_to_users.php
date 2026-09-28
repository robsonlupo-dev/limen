<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca-d'água de "canais vistos" do membro (roadmap social, Onda 2).
 *
 * Um único watermark por membro — mesma disciplina do `hearts_seen_at`: o badge de
 * "Canais" na nav conta os broadcasts mais novos que este instante (de performers
 * que ele segue e estão de pé). Abrir a aba "Canais" carimba `now()` e zera o
 * badge. `null` = nunca abriu, tudo conta.
 *
 * Watermark único (não uma linha por par membro↔performer): o badge é uma
 * CONTAGEM agregada "há novidade nos seus canais", não um estado por canal — barato
 * e suficiente para a v1, como o coração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('broadcasts_seen_at')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('broadcasts_seen_at');
        });
    }
};
