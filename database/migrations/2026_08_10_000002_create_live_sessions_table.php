<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live pública (Sprint 15): uma sessão de transmissão da performer. `room_name`
     * é o nome LiveKit imprevisível (live-{performer}-{hex}), UNIQUE. `status` vence
     * na leitura como o resto do projeto; o command de GC é follow-up.
     *
     * SEM member_id: a live é 1:N, e a identity de cada espectador é OPACA por
     * sessão (mapa server-side é PR de serving) — nunca o id cru (achado 🔴 da
     * revisão). viewer_count é agregado, atualizado periodicamente; não é lista de
     * quem assistiu (mesma disciplina de "não existe lista de quem viu" dos Stories).
     */
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('room_name')->unique();
            $table->enum('status', ['live', 'ended'])->default('live');
            $table->unsignedInteger('viewer_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['performer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_sessions');
    }
};
