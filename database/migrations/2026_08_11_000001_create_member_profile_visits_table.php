<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visitas bidirecionais — o SENTIDO INVERSO de `profile_visits` (A.0.4).
     *
     * `profile_visits` guarda membro → performer (o membro abre o perfil da
     * performer; ela vê "visitantes recentes" sob FanAlias). Esta tabela guarda
     * o sentido novo: PERFORMER → MEMBRO (a performer abre o perfil de um membro
     * no catálogo dela; o membro vê "quem visitou seu perfil").
     *
     * É tabela SEPARADA, não uma coluna de direção em `profile_visits`, de
     * propósito: aquela tabela carrega o piso de anonimato, o k-anonimato por
     * faixa e a mitigação de sybil — invariantes travadas cujo shape
     * (`visitor_id` + `performer_profile_id`) é o do membro→performer. Misturar o
     * sentido inverso ali arriscaria regredir aquele produto de privacidade. O
     * shape aqui é o inverso: quem VISITA é a performer (`performer_profile_id`),
     * quem é VISITADO é o membro (`member_id`).
     *
     * ASSIMETRIA de privacidade (o ponto da feature): performer é PÚBLICA, então
     * o membro vê a identidade real dela (nome/slug/avatar) — sem FanAlias, sem
     * piso, sem k. Não há colisão com Ghost Mode/Modo Discreto porque quem é
     * exposto é a performer, e ela não tem esse perk. Nada de membro vaza para a
     * performer aqui — este é o inbound do MEMBRO. Ver ProfileVisitService.
     *
     * Sem soft delete: dado comportamental, sem valor fiscal nem trilha legal.
     * Retenção de 7 dias (`visits:purge`, o mesmo command); no encerramento de
     * conta as linhas saem de fato, nos dois sentidos (DeletionService).
     */
    public function up(): void
    {
        Schema::create('member_profile_visits', function (Blueprint $table) {
            $table->id();
            // Quem VISITOU: a performer. Cascata para quando o perfil for de fato
            // apagado (não dispara no soft-delete — DeletionService varre à mão).
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            // Quem foi VISITADO: o membro.
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->timestamps();

            // A tela do membro ("quem visitou seu perfil"): janela por member_id
            // ordenada no tempo.
            $table->index(['member_id', 'visited_at']);
            // A deduplicação de 30min: o par exato (performer, membro) na janela.
            $table->index(['performer_profile_id', 'member_id', 'visited_at'], 'member_profile_visits_pair_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profile_visits');
    }
};
