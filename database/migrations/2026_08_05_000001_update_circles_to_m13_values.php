<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alinha os Círculos existentes aos valores da emenda M.13 (Sprint 14 rewiring):
 * franquia mensal (M.13.4) e desconto por tier (M.13.3). Os `price_cents` já
 * batem com M.13.4 (8990/18990/38990/74990/149000) desde a migration de criação,
 * então só `monthly_tokens` e `discount_pct` mudam.
 *
 *   tier              monthly_tokens (75→..)   discount_pct (antigo→M.13.3)
 *   explorador        75  → 105                10 → 10
 *   insider           200 → 230                20 → 10
 *   prestige          500 → 490                30 → 15
 *   black             1200 → 1000              40 → 20
 *   founders_circle   2500 → 2100              50 → 25
 *
 * `monthly_tokens` é lido por SubscriptionService na RENOVAÇÃO — a próxima
 * renovação concede o valor novo (é a semântica desejada: o membro assinou "o
 * Círculo", não uma franquia congelada). `discount_pct` vira espelho de exibição:
 * a AUTORIDADE de cobrança passou a ser a config (M.13.3) lida pela
 * TokenCreditPolicy; um teste de sincronia trava banco == config.
 *
 * UPDATE por slug (não recria linhas — preserva ids e as FKs de subscriptions).
 */
return new class extends Migration
{
    private const M13 = [
        'explorador' => ['monthly_tokens' => 105, 'discount_pct' => 10],
        'insider' => ['monthly_tokens' => 230, 'discount_pct' => 10],
        'prestige' => ['monthly_tokens' => 490, 'discount_pct' => 15],
        'black' => ['monthly_tokens' => 1000, 'discount_pct' => 20],
        'founders_circle' => ['monthly_tokens' => 2100, 'discount_pct' => 25],
    ];

    private const PRE_M13 = [
        'explorador' => ['monthly_tokens' => 75, 'discount_pct' => 10],
        'insider' => ['monthly_tokens' => 200, 'discount_pct' => 20],
        'prestige' => ['monthly_tokens' => 500, 'discount_pct' => 30],
        'black' => ['monthly_tokens' => 1200, 'discount_pct' => 40],
        'founders_circle' => ['monthly_tokens' => 2500, 'discount_pct' => 50],
    ];

    public function up(): void
    {
        foreach (self::M13 as $slug => $values) {
            DB::table('circles')->where('slug', $slug)->update($values);
        }
    }

    public function down(): void
    {
        foreach (self::PRE_M13 as $slug => $values) {
            DB::table('circles')->where('slug', $slug)->update($values);
        }
    }
};
