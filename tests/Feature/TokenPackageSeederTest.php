<?php

use App\Models\TokenPackage;
use Database\Seeders\TokenPackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * TokenPackageSeeder — a correção dos pacotes M.13.2 em staging/produção. O banco
 * real tinha os pacotes da fundação (bronze/prata/…), economicamente inválidos
 * sob M.13.11. O seeder alinha ao catálogo canônico sem apagar linhas referidas
 * por `payments`.
 */

function seedPackages(): void
{
    (new TokenPackageSeeder())->run();
}

it('creates exactly the four M.13.2 packages, active', function () {
    seedPackages();

    $active = TokenPackage::where('active', true)->orderBy('sort_order')->get();

    expect($active->pluck('slug')->all())->toBe(['starter', 'popular', 'premium', 'vip']);
    expect($active->pluck('tokens')->all())->toBe([50, 105, 220, 580]);
    expect($active->pluck('price_cents')->all())->toBe([4990, 9990, 19990, 49990]);
});

it('is idempotent — running twice keeps a single row per slug with correct values', function () {
    seedPackages();
    seedPackages();

    expect(TokenPackage::where('slug', 'starter')->count())->toBe(1);
    expect(TokenPackage::count())->toBe(4);
});

it('corrects a pre-existing package whose values drifted', function () {
    // Uma linha "starter" com preço/tokens errados (o que um ambiente
    // meio-migrado poderia ter) é CORRIGIDA, não duplicada.
    TokenPackage::create([
        'slug' => 'starter', 'name' => 'Starter', 'tokens' => 999,
        'bonus' => 0, 'price_cents' => 100, 'active' => true, 'sort_order' => 99,
    ]);

    seedPackages();

    $starter = TokenPackage::where('slug', 'starter')->sole();
    expect($starter->tokens)->toBe(50);
    expect($starter->price_cents)->toBe(4990);
});

it('deactivates legacy foundation packages without deleting them', function () {
    // Estado exato do banco `limen` (staging/prod) antes do fix.
    $legacy = collect([
        ['slug' => 'bronze', 'name' => 'Bronze', 'tokens' => 100, 'bonus' => 0, 'price_cents' => 990, 'active' => true, 'sort_order' => 1],
        ['slug' => 'diamante', 'name' => 'Diamante', 'tokens' => 2500, 'bonus' => 600, 'price_cents' => 24990, 'active' => true, 'sort_order' => 5],
    ])->map(fn ($p) => TokenPackage::create($p));

    seedPackages();

    // As linhas continuam existindo (FK de payments), só que inativas.
    foreach ($legacy as $pkg) {
        $fresh = TokenPackage::find($pkg->id);
        expect($fresh)->not->toBeNull();
        expect($fresh->active)->toBeFalse();
    }

    // E nenhuma delas é vendável (catálogo filtra por active).
    expect(TokenPackage::where('active', true)->pluck('slug')->all())
        ->toBe(['starter', 'popular', 'premium', 'vip']);
});
