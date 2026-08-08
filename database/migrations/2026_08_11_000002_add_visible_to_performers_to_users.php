<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilidade do membro no catálogo de membros da performer (Sprint 16).
 *
 * TRI-STATE de propósito (decisão do PO): a coluna é NULLABLE, sem default no
 * banco — `null` = "nunca escolheu". O efetivo é resolvido em
 * User::isVisibleToPerformers():
 *   - valor explícito (true/false) manda;
 *   - `null` → padrão POR TIER: Black/FC ocultos, demais visíveis.
 *
 * BACKFILL CONSERVADOR (variante escolhida pelo PO para proteger a discrição de
 * quem paga por ela): as contas existentes viram escolha EXPLÍCITA `true`
 * (visíveis, podem desligar) — EXCETO quem é assinante Black/FC ativo, que fica
 * `null` de propósito, caindo no padrão-por-tier (oculto). Sem isso, o deploy
 * reexporia no catálogo um Black/FC que nunca pediu — o oposto do perk. Contas
 * NOVAS já nascem `null` (a coluna não tem default), então também caem no
 * padrão-por-tier assim que assinam Black/FC.
 *
 * O critério "Black/FC ativo" espelha exatamente o MemberCatalogService (mesmos
 * slugs, mesmo status/período); é repetido inline aqui porque migration não pode
 * depender de service (precisa ser estável no tempo).
 */
return new class extends Migration
{
    /** Tiers de alta privacidade — ficam FORA do backfill (permanecem `null`). */
    private const HIGH_PRIVACY_TIERS = ['black', 'founders_circle'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('visible_to_performers')->nullable()->after('interests_opt_out');
        });

        $this->backfill();
    }

    /**
     * Contas existentes → escolha explícita `true`, menos os assinantes Black/FC
     * ativos (deixados `null`). Método próprio (DML puro) para ser testável sem
     * re-executar o DDL de `up()`.
     */
    public function backfill(): void
    {
        DB::table('users')
            ->whereNotExists(function (Builder $q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->join('circles', 'circles.id', '=', 'subscriptions.circle_id')
                    ->whereColumn('subscriptions.user_id', 'users.id')
                    ->where('subscriptions.status', 'active')
                    ->where('subscriptions.current_period_end', '>', now())
                    ->whereIn('circles.slug', self::HIGH_PRIVACY_TIERS);
            })
            ->update(['visible_to_performers' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('visible_to_performers');
        });
    }
};
