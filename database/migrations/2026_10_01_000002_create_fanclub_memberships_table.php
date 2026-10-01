<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fã-Clube da performer (Onda 4, `docs/FORK_ASSINATURA.md` §1/§4/§9). A ASSINATURA
 * recorrente por-performer, paga em token. É uma relação NOVA e separada do Círculo
 * global (Premissa 0 intacta — não há `performer_id` no Círculo).
 *
 * Ciclo: `active` (renova sozinho no dia do ciclo, debitando o saldo de token) →
 * `cancel_requested` mantém acesso até o fim do ciclo pago, aí vira `cancelled` →
 * saldo insuficiente na renovação entra em `grace_until` (avisa), e se a carência
 * vence sem recarga vira `paused` (perde acesso). **Nunca** cobra BRL — trilho só token.
 *
 * Acesso ao set de fã-clube (serving) = `status = 'active'`. O cron é a máquina de
 * estado (como o `custom-orders:process`): flipa para paused/cancelled no vencimento.
 *
 * UNIQUE(member_id, performer_profile_id): uma relação por par; reassinar REATIVA a
 * mesma linha (não cria outra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fanclub_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');

            // Preço CONGELADO do ciclo corrente (a renovação recongela do settings) e
            // qual faixa se aplicou a este membro (público|vip) — auditoria/extrato.
            $table->unsignedInteger('price_tokens');
            $table->enum('price_tier', ['public', 'vip'])->default('public');

            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');

            // Avisa-e-pausa: prazo de carência quando faltou saldo na renovação. Null
            // fora de carência. Vencido sem recarga → o cron pausa.
            $table->timestamp('grace_until')->nullable();

            // Desvincular: pedido de cancelamento que mantém acesso até o fim do ciclo
            // pago; o cron encerra (status=cancelled) na virada.
            $table->boolean('cancel_requested')->default(false);

            // Último débito da renovação/assinatura (auditoria; ledger é append-only).
            $table->unsignedBigInteger('last_charge_ledger_id')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'performer_profile_id']);
            $table->index(['status', 'current_period_end']);
            $table->index(['performer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fanclub_memberships');
    }
};
