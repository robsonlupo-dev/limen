<?php

namespace Database\Seeders;

use App\Models\TokenPackage;
use Illuminate\Database\Seeder;

/**
 * Catálogo canônico de pacotes de tokens — emenda M.13.2 (Sprint 14).
 *
 * Extraído do DatabaseSeeder para poder rodar ISOLADO em staging/produção
 * (`php artisan db:seed --class=TokenPackageSeeder`) sem arrastar junto o seed de
 * usuários/gifts do DatabaseSeeder, que não pode tocar um banco real (princípio
 * nº 6: dados reais só em produção). O DatabaseSeeder delega aqui, então dev e o
 * ambiente real leem a MESMA fonte da verdade.
 *
 * Idempotente: `updateOrCreate` por slug (corrige preço/tokens de uma linha que
 * já exista) e DESATIVA — nunca apaga — qualquer pacote fora da lista M.13.2. Não
 * apagar é obrigatório: `payments.token_package_id` referencia estas linhas
 * (histórico fiscal), e o catálogo de compra + o PaymentController já filtram por
 * `active`, então desativar tira o pacote antigo da venda sem quebrar a FK.
 */
class TokenPackageSeeder extends Seeder
{
    /**
     * Pacotes achatados M.13.2. `tokens` já é o valor cheio (sem `bonus` no modelo
     * novo — a âncora é R$1,00/token no Starter). Preço em centavos. Piso de
     * margem: nenhuma combinação cai abaixo de R$0,625/token (M.13.11).
     */
    public const PACKAGES = [
        ['slug' => 'starter', 'name' => 'Starter', 'tokens' => 50,  'bonus' => 0, 'price_cents' => 4990,  'sort_order' => 1],
        ['slug' => 'popular', 'name' => 'Popular', 'tokens' => 105, 'bonus' => 0, 'price_cents' => 9990,  'sort_order' => 2],
        ['slug' => 'premium', 'name' => 'Premium', 'tokens' => 220, 'bonus' => 0, 'price_cents' => 19990, 'sort_order' => 3],
        ['slug' => 'vip',     'name' => 'VIP',     'tokens' => 580, 'bonus' => 0, 'price_cents' => 49990, 'sort_order' => 4],
    ];

    public function run(): void
    {
        foreach (self::PACKAGES as $pkg) {
            TokenPackage::updateOrCreate(['slug' => $pkg['slug']], $pkg + ['active' => true]);
        }

        // Desativa (NUNCA apaga — a FK de `payments` guarda o histórico) qualquer
        // pacote pré-M.13 que exista neste ambiente, para o catálogo de compra só
        // oferecer os pacotes M.13.2.
        TokenPackage::whereNotIn('slug', array_column(self::PACKAGES, 'slug'))
            ->update(['active' => false]);
    }
}
