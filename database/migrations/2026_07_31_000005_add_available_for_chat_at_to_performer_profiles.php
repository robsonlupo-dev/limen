<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Disponível para conversa" (Sprint 11) — o Caminho 3 do Interesse Expandido.
 *
 * Em vez de a performer buscar membros (Interesse Controlado), ela SINALIZA que
 * quer ser abordada agora. O sinal é um único carimbo: `available_for_chat_at`.
 *
 * A disponibilidade é DERIVADA, nunca um booleano guardado: a performer está
 * disponível quando o carimbo é não-nulo E recente (janela de 4h,
 * PerformerProfile::AVAILABILITY_WINDOW_HOURS). Mesma disciplina do `is_live`
 * lido na LEITURA e do ChatAccess — não há job que apaga o estado, a expiração
 * vale a cada request (ver PerformerProfile::isAvailableForChat). Guardar um
 * `is_available` booleano exigiria um job para apagá-lo às 4h e, com o job
 * parado, deixaria a performer "disponível" para sempre.
 *
 * NULLABLE, default null: `null` é "não sinalizou", e é o estado em que todo
 * perfil existente nasce. Sem backfill, de propósito — sinalizar é um ato.
 *
 * PRIVACIDADE: o carimbo é `$hidden` no model e NÃO é `$fillable`, como
 * `discrete_mode` e o segredo do 2FA — escrita só pelo endpoint dedicado, por
 * forceFill. O timestamp exato NUNCA sai em resource nenhum: o público vê só o
 * booleano `is_available`. Um relógio devolveria o instante em que ela sinalizou
 * — presença ao minuto, exatamente o que o produto protege (ver ActivitySlot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->timestamp('available_for_chat_at')
                ->nullable()
                ->default(null)
                ->after('is_live');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('available_for_chat_at');
        });
    }
};
