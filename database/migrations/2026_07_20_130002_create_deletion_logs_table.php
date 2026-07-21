<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prova de atendimento do direito de eliminação: o que foi apagado, quando
     * e a pedido de quem. É o registro que a plataforma mostra a uma
     * fiscalização da ANPD — por isso sobrevive ao próprio usuário.
     *
     * `data_summary` guarda CONTAGENS por tabela, nunca conteúdo. Um resumo com
     * PII transformaria o log de conformidade no último lugar onde os dados
     * apagados continuam existindo — exatamente o oposto do objetivo.
     */
    public function up(): void
    {
        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: o user é soft-deletado, nunca removido da linha.
            // Se um dia alguém tentar um DELETE físico, isto barra e preserva a
            // trilha em vez de apagá-la em cascata.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('executed_at')->nullable();
            $table->enum('reason', ['user_request', 'admin', 'inactivity']);
            $table->json('data_summary')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
    }
};
