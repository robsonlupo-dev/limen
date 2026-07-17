<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canal de conversa entre um membro e uma performer. Criado no momento em
     * que o membro DESBLOQUEIA o Interesse da performer (não há endpoint de
     * abertura pelo membro — ver docs/INTEREST_SYSTEM_SPEC.md §2/§4-5). Uma
     * conversa por par (membro, performer).
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->enum('status', ['active', 'archived'])->default('active');
            // Preenchido a cada mensagem entregue; ordena a lista de conversas.
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // Uma conversa por par. Também serve o find-or-create do desbloqueio.
            $table->unique(['member_id', 'performer_profile_id']);
            // Listagem "minhas conversas" da performer, mais recentes primeiro.
            $table->index(['performer_profile_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
