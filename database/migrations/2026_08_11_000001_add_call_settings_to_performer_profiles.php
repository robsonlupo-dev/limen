<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuração de chamada privada 1:1 da performer (Sprint 15, PR #140).
     * `call_price_per_minute` em TOKENS inteiros (piso 5, passo 5 — M.13.7);
     * NULL = a performer NÃO aceita chamadas (o request dá 422). O valor é
     * congelado em `call_sessions.price_per_minute` no request, então mudar o
     * preço não afeta chamadas em curso. `call_max_duration_minutes` nullable =
     * sem limite.
     *
     * Ambos ficam FORA do $fillable de PerformerProfile (a escrita passa só pelo
     * CallService após validação de piso/passo, via forceFill — disciplina de
     * discrete_mode/2FA/boost).
     */
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->unsignedInteger('call_price_per_minute')->nullable()->after('rate_camera');
            $table->unsignedInteger('call_max_duration_minutes')->nullable()->after('call_price_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn(['call_price_per_minute', 'call_max_duration_minutes']);
        });
    }
};
