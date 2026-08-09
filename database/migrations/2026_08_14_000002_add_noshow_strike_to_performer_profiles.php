<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agendamento de chamada (feat/scheduled-call-v1) na performer:
     *
     *  - `noshow_strike_count`: a performer confirma uma reserva e não entra → o
     *    membro é reembolsado E a performer leva 1 strike. **3 strikes → review de
     *    admin** (não banimento automático — decisão humana, reusa a fila
     *    `needs_review` do payout/Sprint 13).
     *  - `call_slot_minutes`: a duração-PADRÃO do slot que a performer oferece para
     *    agendamento (spec: "performer define duração, default 15min"). NULL = usa o
     *    default de config/scheduled_call.php. É a duração do BLOCO agendado — o teto
     *    absoluto de um único atendimento segue sendo `call_max_duration_minutes`.
     *
     * Ambos FORA do $fillable: a escrita passa pelo service (CallReservationService/
     * CallService), via forceFill (disciplina de discrete_mode/2FA/boost).
     */
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->unsignedInteger('noshow_strike_count')->default(0)->after('call_max_duration_minutes');
            $table->unsignedSmallInteger('call_slot_minutes')->nullable()->after('noshow_strike_count');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn(['noshow_strike_count', 'call_slot_minutes']);
        });
    }
};
