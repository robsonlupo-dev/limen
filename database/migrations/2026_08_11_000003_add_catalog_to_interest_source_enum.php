<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Terceira origem de Interesse Controlado: o CATÁLOGO DE MEMBROS (Sprint 16).
 *
 * Até aqui `source` era ('follower','visitor'); o catálogo de membros vira a
 * terceira porta pela qual a performer sinaliza interesse. A distinção importa
 * pela mesma razão do Sprint 9: a cota diária é contada POR ORIGEM (M.13 não
 * mexe nisto), e cada origem resolve o handle contra um conjunto diferente.
 *
 * A origem NUNCA chega ao membro — a caixa dele mostra "uma performer demonstrou
 * interesse" e nada mais (o mesmo cego de sempre). MySQL-only (MODIFY ENUM),
 * como as demais migrations de enum do projeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE performer_interests MODIFY source ENUM('follower','visitor','catalog') NOT NULL DEFAULT 'follower'");
    }

    public function down(): void
    {
        // Converte linhas 'catalog' antes de estreitar o enum, senão o MODIFY
        // falharia com dados fora do novo domínio. 'follower' é o valor histórico
        // neutro (mesmo default da coluna).
        DB::statement("UPDATE performer_interests SET source = 'follower' WHERE source = 'catalog'");
        DB::statement("ALTER TABLE performer_interests MODIFY source ENUM('follower','visitor') NOT NULL DEFAULT 'follower'");
    }
};
