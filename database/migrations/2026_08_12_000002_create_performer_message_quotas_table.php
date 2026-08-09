<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador diário de mensagens GRÁTIS da performer (motor de engajamento do
 * catálogo de membros).
 *
 * A performer tem uma franquia diária de mensagens personalizadas
 * (config/member_engagement.php → free_messages_per_day, hoje 15). Uma linha por
 * (performer, dia): `sent_count` sobe a cada envio; um dia novo é uma linha nova,
 * então o reset é implícito (sem job). UNIQUE(par) + incremento sob lock da linha
 * do perfil (CallReservationService/InterestService fazem igual) fecham a corrida
 * de dois envios simultâneos furarem a franquia.
 *
 * É contador do lado da PERFORMER e sobre a própria atividade — nada de membro
 * aqui. Sai no Hard Delete da performer (DeletionService::purgePerformerMessageQuotas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_message_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->date('quota_date');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();

            $table->unique(['performer_profile_id', 'quota_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_message_quotas');
    }
};
