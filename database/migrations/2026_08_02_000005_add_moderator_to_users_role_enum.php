<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Refactor de roles (Sprint 13): separa `moderator` de `admin`. Até aqui só
 * existiam `consumer`/`performer`/`admin`, e moderar denúncia exigia ser admin
 * — logo ver KYC, payout e dados financeiros. O novo valor abre a porta para a
 * fila `/moderacao/*`, que enxerga a denúncia SEM os poderes de admin.
 *
 * Este passo só abre o valor no enum; quem grava `role` é autoridade do servidor
 * (forceFill — a coluna fica fora do $fillable, como `status` e `discrete_mode`).
 * A criação de um moderador passa pelo comando `limen:create-moderator`.
 *
 * Espelha o cuidado da migration irmã do `spend_boost`: SQLite não tem ENUM
 * (a coluna vira TEXT e aceita qualquer valor), então só o MySQL precisa alterar
 * o tipo. O valor entra ANTES de `admin` para manter os privilegiados juntos no
 * fim da lista — é cosmético (o enum não é ordenado semanticamente), mas deixa a
 * leitura do schema honesta sobre o desenho.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('consumer','performer','moderator','admin') NOT NULL DEFAULT 'consumer'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // O MySQL recusa remover um valor de enum ainda em uso — rebaixa os
            // moderadores a consumer antes de encolher o tipo. Não há caminho de
            // volta "correto" (a conta perde o privilégio), mas o down não pode
            // falhar por linha órfã.
            DB::table('users')
                ->where('role', 'moderator')
                ->update(['role' => 'consumer']);

            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('consumer','performer','admin') NOT NULL DEFAULT 'consumer'");
        }
    }
};
