<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Participação de um membro num group show (Sprint 15, PR #141). Cada membro
     * tem a PRÓPRIA cobrança: `price_per_minute` (snapshot do preço-grupo no join;
     * troca para o preço 1:1 se ele for o sobrevivente de um upgrade) e
     * `minutes_billed` (fonte única de idempotência da cobrança, como no 1:1).
     *
     * `member_id` é CHAVE INTERNA (FanAlias é apresentação — nenhum resource/broadcast
     * serializa member_id cru). `unique(call_session_id, member_id)` = um join por
     * membro/grupo. FK cascade para call_sessions: quando a sessão do perfil sai no
     * Hard Delete, os participantes saem junto; o lado do MEMBRO (participações em
     * grupos de performers VIVAS) é varrido explicitamente por member_id.
     */
    public function up(): void
    {
        Schema::create('call_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('price_per_minute');
            $table->unsignedInteger('minutes_billed')->default(0);
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamp('joined_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['call_session_id', 'member_id']);
            $table->index(['member_id', 'status']);
            $table->index(['call_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_session_participants');
    }
};
