<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move a localização única do Sprint 9A para `performer_locations`.
 *
 * Toda performer que já tinha `state` preenchido ganha UMA linha primária
 * (is_primary=true, position=0) espelhando o par estado+cidade. As colunas
 * `state`/`city` de `performer_profiles` ficam intactas — viram o CACHE da
 * primária (ver a migration da tabela), então não há dado a perder e as telas
 * do Sprint 9A que leem a coluna seguem funcionando sem backfill.
 *
 * `city` sem `state` não vira linha: a localização pública é a UF, e cidade
 * solta não tem UF para exibir nem para filtrar — era estado inconsistente que
 * a tela do Sprint 9A já não produzia (o form manda os dois juntos ou nenhum).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('performer_profiles')
            ->whereNotNull('state')
            ->orderBy('id')
            ->select('id', 'state', 'city')
            ->chunkById(500, function ($profiles) {
                $now = now();
                $rows = [];

                foreach ($profiles as $profile) {
                    $rows[] = [
                        'performer_profile_id' => $profile->id,
                        'state' => $profile->state,
                        'city' => $profile->city,
                        'is_primary' => true,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    // insertOrIgnore: idempotente contra o índice único caso a
                    // migration rerode num banco parcialmente migrado.
                    DB::table('performer_locations')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // As colunas de origem nunca foram tocadas; basta esvaziar a tabela nova.
        DB::table('performer_locations')->truncate();
    }
};
