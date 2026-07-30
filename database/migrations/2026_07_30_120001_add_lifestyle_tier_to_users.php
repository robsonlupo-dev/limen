<?php

use App\Support\LifestyleTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Estilo de Vida" do membro (Sprint 10): escala ordenada, opcional,
 * auto-declarada — e a primeira auto-declaração do membro que VOLTA para a
 * performer. Ver App\Support\LifestyleTier para a regra inteira e para a
 * ressalva de correlação cross-perfil.
 *
 * Enum e não string livre: o conjunto é fechado, é escala (a ordem importa) e a
 * tela renderiza rótulo por slug. Uma string livre deixaria a coluna aceitar
 * qualquer coisa que passasse pela validação de hoje — e o valor inválido só
 * apareceria na tela da performer, que é o pior lugar para descobrir.
 *
 * NULLABLE, com default null. `null` é "não declarou", e é o estado em que toda
 * conta existente nasce: a migration NÃO faz backfill, de propósito. Preencher
 * uma faixa para quem nunca respondeu inventaria declaração — e é declaração
 * que aparece para terceiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // O conjunto vem do LifestyleTier, nunca de uma lista literal aqui:
            // uma cópia divergiria da validação no primeiro slug novo, e o enum
            // do banco só reclamaria em produção, no INSERT.
            $table->enum('lifestyle_tier', LifestyleTier::storableValues())
                ->nullable()
                ->default(null)
                ->after('seeking');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifestyle_tier');
        });
    }
};
