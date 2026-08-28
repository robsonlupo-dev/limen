<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAUSA da live pública (feat/private-call-from-live). Ao aceitar uma chamada
 * privada pedida DURANTE a live, a performer PAUSA a transmissão em vez de encerrar:
 * quem assiste continua conectado (sem tela preta, sem drop) e vê "Em chamada
 * privada — volta já"; ao fim da chamada, a live RETOMA.
 *
 * `paused_at` é uma SUB-estado, NÃO um novo valor do enum `status`: a live segue
 * `status='live'` (então `scopeLive`/`activeFor`/`viewerCount` continuam achando a
 * sessão e os viewers NÃO caem em 410). Pausada = `paused_at` não-nulo. Fora do
 * `$fillable`; escrita só por `forceFill` no LiveSessionService (disciplina de
 * `is_live`/`discrete_mode`). A ausência de coluna no enum evita mexer no
 * `activeFor`/reconciliação existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
