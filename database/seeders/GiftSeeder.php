<?php

namespace Database\Seeders;

use App\Models\Gift;
use Illuminate\Database\Seeder;

/**
 * Catálogo FIXO de presentes da Limen (M.13.6). Reference data PURA — sem contas
 * de teste nem credenciais —, então NÃO usa RefusesUnsafeEnvironment: roda em
 * qualquer ambiente, inclusive produção (`php artisan db:seed --class=GiftSeeder`).
 * Idempotente (updateOrCreate por slug); desativa slugs fora do catálogo em vez
 * de apagar (a FK de gift_sends guarda o histórico), espelhando seedTokenPackages.
 *
 * Todos os preços são múltiplos de 4 tokens (invariante M.13.6, validada em
 * TokenCreditPolicy::isValidGiftPrice).
 */
class GiftSeeder extends Seeder
{
    public function run(): void
    {
        $gifts = [
            ['slug' => 'rosa',      'name' => 'Rosa',      'price_tokens' => 4],
            ['slug' => 'chocolate', 'name' => 'Chocolate', 'price_tokens' => 12],
            ['slug' => 'champagne', 'name' => 'Champagne', 'price_tokens' => 40],
            ['slug' => 'joia',      'name' => 'Joia',      'price_tokens' => 100],
            ['slug' => 'coroa',     'name' => 'Coroa',     'price_tokens' => 200],
            ['slug' => 'diamante',  'name' => 'Diamante',  'price_tokens' => 400],
        ];

        foreach ($gifts as $gift) {
            Gift::updateOrCreate(['slug' => $gift['slug']], $gift + ['active' => true]);
        }

        // Desativa (NUNCA apaga — a FK de gift_sends guarda o histórico) qualquer
        // presente fora do catálogo canônico.
        Gift::whereNotIn('slug', array_column($gifts, 'slug'))->update(['active' => false]);
    }
}
