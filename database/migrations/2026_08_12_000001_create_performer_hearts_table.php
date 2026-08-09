<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CORAÇÃO — o "interesse grátis" do catálogo de membros (home da performer).
 *
 * Tabela DEDICADA, e não uma variante em `performer_interests`, de propósito: o
 * coração é o OPOSTO do Interesse Controlado. Lá o membro PAGA para revelar quem
 * sinalizou (anônimo até pagar), com cota diária e cooldown; aqui é GRÁTIS,
 * ILIMITADO e a identidade da performer é sempre visível ao membro. Misturar os
 * dois na mesma tabela enroscaria a máquina de `suppressed`/opt-out/desbloqueio-
 * pago do Interesse — e é justo ali que uma regressão de privacidade nasceria.
 *
 * Direção: performer → membro. `performer_profile_id` é quem CURTIU;
 * `member_id` é quem recebeu. UNIQUE(par) torna o "curtir" idempotente: recurtir
 * é no-op, não uma segunda linha.
 *
 * FKs `restrictOnDelete` como em `performer_interests`: os dois lados são
 * soft-delete/anonimização (item 11 do CLAUDE.md), então o cascade nunca dispara
 * e o Hard Delete varre as linhas explicitamente nos DOIS sentidos
 * (DeletionService::purgePerformerHearts / purgePerformerHeartsByPerformer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_hearts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Idempotência do "curtir": um par, uma linha.
            $table->unique(['performer_profile_id', 'member_id']);
            // Lista "Performers interessadas em você" do membro, por recência.
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_hearts');
    }
};
