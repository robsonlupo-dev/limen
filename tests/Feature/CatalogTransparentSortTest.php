<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ordenação transparente do catálogo (roadmap Fatal Model, Onda A). Prefixo `cs`.
 *
 * Todo performer de teste nasce SEM boost e SEM live, então as cláusulas
 * primárias do scopePublicCatalog (boost, is_live) empatam e quem decide a
 * ordem é só o applySort + o desempate por id.
 */
function csPerformer(array $attrs = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    if (array_key_exists('last_active_at', $attrs)) {
        $user->forceFill(['last_active_at' => $attrs['last_active_at']])->save();
    }

    static $n = 0;
    $n++;
    $name = 'Sort'.$n;

    $profile = $user->performerProfile()->create([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    $force = [];
    foreach (['followers_count', 'rating_avg', 'created_at'] as $k) {
        if (array_key_exists($k, $attrs)) {
            $force[$k] = $attrs[$k];
        }
    }
    if ($force !== []) {
        $profile->forceFill($force)->save();
    }

    return $profile->refresh();
}

/** @return list<int> ids na ordem em que o catálogo público devolveu */
function csOrder(?string $sort): array
{
    return collect(app(PerformerCatalogService::class)->publicSearch(null, ['sort' => $sort])->items())
        ->pluck('id')->all();
}

it('padrão (sem sort) ordena pelas mais ativas', function () {
    $recente = csPerformer(['last_active_at' => now()->subHour()]);
    $antiga = csPerformer(['last_active_at' => now()->subDays(5)]);
    $nunca = csPerformer([]); // last_active_at null → afunda

    expect(csOrder(null))->toBe([$recente->id, $antiga->id, $nunca->id]);
});

it('"seguidas" ordena por seguidores, não por atividade', function () {
    $poucos = csPerformer(['followers_count' => 2, 'last_active_at' => now()->subMinute()]);
    $muitos = csPerformer(['followers_count' => 900, 'last_active_at' => now()->subDays(20)]);

    expect(csOrder('seguidas'))->toBe([$muitos->id, $poucos->id]);
});

it('"novas" ordena pela data de criação', function () {
    $velha = csPerformer(['created_at' => now()->subDays(40)]);
    $nova = csPerformer(['created_at' => now()->subDay()]);

    expect(csOrder('novas'))->toBe([$nova->id, $velha->id]);
});

it('NUNCA ordena por avaliação: o legado rating_avg cai no padrão (atividade)', function () {
    // Quem tem nota alta mas está inativa deve FICAR ATRÁS de quem tem nota
    // baixa e está ativa — prova que a estrela não entra na ordenação.
    $notaAltaInativa = csPerformer(['rating_avg' => 5.0, 'last_active_at' => now()->subDays(10)]);
    $notaBaixaAtiva = csPerformer(['rating_avg' => 1.0, 'last_active_at' => now()->subMinutes(2)]);

    expect(csOrder('rating_avg'))->toBe([$notaBaixaAtiva->id, $notaAltaInativa->id]);
});

it('mapeia os valores legados para os canônicos', function () {
    $poucos = csPerformer(['followers_count' => 1, 'last_active_at' => now()->subMinute()]);
    $muitos = csPerformer(['followers_count' => 500, 'last_active_at' => now()->subDays(9)]);

    // followers_count (legado) == seguidas
    expect(csOrder('followers_count'))->toBe([$muitos->id, $poucos->id]);

    // newest (legado) == novas
    $velha = csPerformer(['created_at' => now()->subDays(30)]);
    $nova = csPerformer(['created_at' => now()->subHours(3)]);
    $ids = csOrder('newest');
    expect(array_search($nova->id, $ids, true))->toBeLessThan(array_search($velha->id, $ids, true));
});

it('a vitrine pública aceita tanto o canônico quanto o legado sem 422', function () {
    csPerformer(['last_active_at' => now()->subHour()]);

    test()->get(route('performers.public', ['sort' => 'ativas']))->assertOk();
    test()->get(route('performers.public', ['sort' => 'seguidas']))->assertOk();
    test()->get(route('performers.public', ['sort' => 'rating_avg']))->assertOk(); // legado tolerado
});

it('rejeita um sort desconhecido', function () {
    test()->get(route('performers.public', ['sort' => 'estrelinhas']))
        ->assertSessionHasErrors('sort');
});
