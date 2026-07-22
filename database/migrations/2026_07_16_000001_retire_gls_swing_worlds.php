<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reduz os mundos de 6 para 4 (decisão travada 16/07/2026): gls e swing são
 * aposentados. Dados existentes são remapeados — gls → homens, swing → casais.
 *
 * Segurança em produção (há performers reais no staging):
 * - os dados são migrados ANTES de encolher o enum. 'homens' e 'casais' já são
 *   valores válidos no enum antigo, então esta ordem nunca deixa uma linha com
 *   um valor fora do enum vigente — mesmo que o ALTER falhe no meio.
 * - o enum só é encolhido depois que nenhuma linha referencia mais gls/swing,
 *   evitando truncamento silencioso.
 */
return new class extends Migration
{
    public function up(): void
    {
        // performer_profiles.category é o único ENUM de mundo, mas há colunas
        // string que ACEITARAM gls/swing numa janela anterior e podem reter dado
        // real: users.preferred_world e waitlist_entries.world. Todas remapeadas.
        // (waitlist_entries.world_preferences JSON só existe desde os 4 mundos —
        // nunca aceitou gls/swing — então não precisa remap.)
        DB::table('performer_profiles')->where('category', 'gls')->update(['category' => 'homens']);
        DB::table('performer_profiles')->where('category', 'swing')->update(['category' => 'casais']);

        DB::table('users')->where('preferred_world', 'gls')->update(['preferred_world' => 'homens']);
        DB::table('users')->where('preferred_world', 'swing')->update(['preferred_world' => 'casais']);

        DB::table('waitlist_entries')->where('world', 'gls')->update(['world' => 'homens']);
        DB::table('waitlist_entries')->where('world', 'swing')->update(['world' => 'casais']);

        // Agora que nada usa gls/swing, encolhe o enum para os 4 mundos oficiais.
        DB::statement(
            'ALTER TABLE performer_profiles MODIFY category '
            ."ENUM('mulheres','homens','casais','trans') NOT NULL DEFAULT 'mulheres'"
        );
    }

    public function down(): void
    {
        // Reabre o enum para os 6 valores. Os dados já remapeados NÃO são
        // revertidos: não há como distinguir quem era gls/swing depois do merge.
        DB::statement(
            'ALTER TABLE performer_profiles MODIFY category '
            ."ENUM('mulheres','homens','casais','trans','gls','swing') NOT NULL DEFAULT 'mulheres'"
        );
    }
};
