<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cobrança pré-paga por minuto da chamada 1:1 (Sprint 15, PR #140).
     * `minutes_billed` = quantos minutos já foram cobrados (débito no ledger).
     * É a FONTE ÚNICA de idempotência da cobrança: o heartbeat/token-refresh
     * calcula `required = floor((now-started_at)/60)+1` e só cobra enquanto
     * `minutes_billed < required`, tudo sob `lockForUpdate` da linha da sessão —
     * dois heartbeats no mesmo minuto viram no-op. accept crava minutes_billed=1
     * (primeiro minuto pré-pago).
     */
    public function up(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->unsignedInteger('minutes_billed')->default(0)->after('price_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropColumn('minutes_billed');
        });
    }
};
