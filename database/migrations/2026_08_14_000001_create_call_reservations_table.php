<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agendamento de chamada (feat/scheduled-call-v1). Evolução da chamada 1:1 do
     * PR #140: em vez de pedir a chamada AGORA e depender de a performer estar
     * online, o MEMBRO agenda performer + data/hora e o sistema TRAVA um depósito
     * (o preço de 1 min, congelado) para garantir o compromisso dos dois lados.
     *
     * Tabela DEDICADA (não é overload de call_sessions — decisão do handoff §técnico
     * 1): o ciclo de vida da reserva (reserva → confirmação → janela → no-show/
     * ativa) é DIFERENTE do da sessão de vídeo. Quando os dois entram, a reserva
     * CRIA/LIGA uma `call_sessions type=private` (call_session_id) e a cobrança dos
     * minutos 2+ é 100% o motor do PR #140 (MinuteBiller, 70/30).
     *
     * ── Contabilidade do depósito (ledger append-only, princípio nº 2) ──────────
     *  - Reserva: débito `spend_call_reservation` do membro (deposit_tokens), preço/
     *    min congelado em price_per_min_locked.
     *  - Entrada OK: o 1º minuto é pago pelo depósito → crédito `call_credit` 70/30
     *    à performer (sem novo débito). deposit_settled fecha a idempotência.
     *  - No-show membro: `call_noshow_credit` 100/0 à performer (ganho sacável).
     *  - Refund (cancel grátis / não-confirmada / no-show performer): `call_reservation_refund`
     *    100% ao membro (nunca respeita teto, fora do payout).
     *
     * `deposit_settled` é o INVARIANTE de idempotência: todo movimento one-time do
     * depósito (refund, no-show credit, minuto-1) só acontece com deposit_settled=0
     * e o marca 1, tudo sob lockForUpdate da linha — reprocessar nunca duplica.
     */
    public function up(): void
    {
        Schema::create('call_reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();

            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('slot_minutes');

            $table->enum('status', [
                'pending',            // criada, aguardando confirmação da performer
                'confirmed',          // performer confirmou; aguardando o horário/entrada
                'completed',          // membro entrou: depósito virou minuto 1, chamada em curso/encerrada
                'cancelled',          // cancelada com refund (membro antes de T-2h, ou não-confirmada)
                'no_show_member',     // performer entrou, membro não → depósito 100% performer
                'no_show_performer',  // performer confirmou e não entrou → refund + strike
            ])->default('pending');

            // Depósito travado (débito no ato) e preço/min CONGELADO na reserva — se
            // a performer reprecificar, a chamada agendada usa o valor da reserva.
            $table->unsignedInteger('deposit_tokens');
            $table->unsignedInteger('price_per_min_locked');

            // Sala LiveKit da chamada agendada: nasce na ENTRADA DA PERFORMER (ela
            // abre a sala verificada). NUNCA em URL/log/resposta (invariante #138) —
            // hidden no model. Nullable até a performer entrar.
            $table->string('room_name')->nullable();

            // Liga na sessão de vídeo do PR #140 quando o membro entra (a cobrança
            // dos minutos 2+ é do CallService sobre esta call_session).
            $table->foreignId('call_session_id')->nullable()->constrained('call_sessions')->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('performer_entered_at')->nullable();
            $table->timestamp('member_entered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Idempotência do depósito e dos avisos one-shot (o job roda a cada min).
            $table->boolean('deposit_settled')->default(false);
            $table->boolean('strike_recorded')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();     // aviso T-5min
            $table->timestamp('slot_warning_sent_at')->nullable(); // aviso T-3min do fim do slot (só se há próximo)

            $table->timestamps();

            // Cron varre por (status, scheduled_at). Teto/lista por membro. Buffer/
            // fila da performer por (performer, scheduled_at).
            $table->index(['status', 'scheduled_at']);
            $table->index(['member_id', 'status']);
            $table->index(['performer_profile_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_reservations');
    }
};
