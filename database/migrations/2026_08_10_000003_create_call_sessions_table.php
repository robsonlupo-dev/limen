<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chamada privada 1:1 e group show (Sprint 15): a sessão de vídeo entre uma
     * performer e um membro. `member_id` é CHAVE INTERNA (FK users), como no ledger
     * — a disciplina do FanAlias é sobre APRESENTAÇÃO: nenhum resource da performer
     * pode serializar member_id cru; ela vê o FanAlias handle (a identity da chamada
     * já é o handle). `price_per_minute` em TOKENS inteiros, piso 5 (M.13.7).
     *
     * `status` pending→active→ended|declined vence na leitura. `room_name` (LiveKit)
     * imprevisível e UNIQUE. A cobrança por minuto é o PR de serving.
     */
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('room_name')->unique();
            $table->enum('type', ['private', 'group']);
            $table->enum('status', ['pending', 'active', 'ended', 'declined'])->default('pending');
            $table->unsignedInteger('price_per_minute');
            $table->unsignedInteger('max_duration_minutes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['performer_profile_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
};
