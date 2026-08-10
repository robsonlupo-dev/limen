<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerLocationService;
use App\Support\BrazilianCities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Filtro de cidade CONSENTIDO (item 4 da fila).
 *
 * A invariante que estes testes travam: a cidade da performer NUNCA aparece no
 * catálogo — e o filtro de cidade só alcança quem OPTOU por ser encontrável
 * (`findable_by_city`). Performer que não optou se comporta exatamente como
 * hoje: cidade 100% interna, invisível ao filtro. A base do IBGE é self-hosted
 * (nenhum asset externo — ver ExternalAssetPolicyTest).
 *
 * Helpers locais (prefixo cityf*) para o arquivo ser autossuficiente.
 */

function cityfPerformer(string $stageName): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    // refresh() carrega o default do banco (findable_by_city = false) na
    // instância — sem ele o atributo fica null em memória.
    return $user->performerProfile()->create([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ])->refresh();
}

/**
 * Performer com localização + o opt-in de findability. `findable` default false
 * espelha produção (a cidade é interna até a performer consentir).
 *
 * @param  array<int, array{state: string, city?: ?string, is_primary?: bool}>  $locations
 */
function cityfPerformerWith(string $stageName, array $locations, bool $findable = false): PerformerProfile
{
    $profile = cityfPerformer($stageName);
    app(PerformerLocationService::class)->setLocations($profile, $locations);

    if ($findable) {
        $profile->forceFill(['findable_by_city' => true])->save();
    }

    return $profile->refresh();
}

function cityfMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/** Linhas do catálogo AUTENTICADO. */
function cityfAuthRows(array $query = []): array
{
    return test()->actingAs(cityfMember())->get(route('catalog', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** Linhas do catálogo PÚBLICO. */
function cityfPublicRows(array $query = []): array
{
    return test()->get(route('performers.public', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** Linhas da API v1. */
function cityfApiRows(array $query = []): array
{
    return test()->getJson(route('performers.index', $query))->assertOk()->json('data');
}

function cityfNames(array $rows): array
{
    return collect($rows)->pluck('stage_name')->sort()->values()->all();
}

/** Mesma expectativa de filtro nas três portas de listagem. */
function cityfExpectAll(array $query, array $expected): void
{
    expect(cityfNames(cityfAuthRows($query)))->toBe($expected)
        ->and(cityfNames(cityfPublicRows($query)))->toBe($expected)
        ->and(cityfNames(cityfApiRows($query)))->toBe($expected);
}

// ─── Base do IBGE (self-hosted) ──────────────────────────────────────────────

it('carrega a base do IBGE self-hosted com os ~5.570 municípios', function () {
    $path = public_path('data/ibge-municipios.json');

    expect(file_exists($path))->toBeTrue();

    $rows = json_decode((string) file_get_contents($path), true);

    expect($rows)->toBeArray()
        ->and(count($rows))->toBeGreaterThan(5500)
        ->and(count($rows))->toBeLessThan(5600);

    // Cada linha é [nome, uf] — nada de URL externa, é dado puro.
    expect($rows[0])->toBeArray()->toHaveCount(2);
    expect(str_contains((string) file_get_contents($path), 'http'))->toBeFalse();

    // A porta do backend lê o mesmo arquivo.
    expect(count(BrazilianCities::all()))->toBe(count($rows));
});

it('normaliza acento e caixa igual nos dois lados (achar por prefixo, ignorar acento)', function () {
    // "sao paulo" acha "São Paulo" — a normalização casa.
    expect(BrazilianCities::normalize('São Paulo'))->toBe('sao paulo')
        ->and(BrazilianCities::normalize('SÃO  PAULO'))->toBe('sao paulo')
        ->and(BrazilianCities::exists('sao paulo', 'SP'))->toBeTrue()
        ->and(BrazilianCities::exists('São Paulo'))->toBeTrue()
        ->and(BrazilianCities::exists('Cidade Que Não Existe', 'SP'))->toBeFalse();
});

it('desambigua homônimos pela UF na base', function () {
    // "Bonito" existe em várias UFs; com a UF errada não deve casar.
    expect(BrazilianCities::exists('Bonito', 'MS'))->toBeTrue()
        ->and(BrazilianCities::exists('Bonito', 'PE'))->toBeTrue()
        ->and(BrazilianCities::exists('Bonito', 'AC'))->toBeFalse();
});

// ─── Filtro: só alcança quem consentiu ───────────────────────────────────────

it('encontra a performer que optou, filtrando por cidade (acento-insensível)', function () {
    cityfPerformerWith('Optou', [['state' => 'SP', 'city' => 'São Paulo', 'is_primary' => true]], findable: true);
    cityfPerformer('Sem Localizacao');

    // Filtro com acento e sem acento devolve a mesma performer.
    cityfExpectAll(['city' => 'São Paulo'], ['Optou']);
    cityfExpectAll(['city' => 'sao paulo'], ['Optou']);
});

it('NÃO encontra por cidade a performer que não optou (cidade segue interna)', function () {
    // Mesma cidade, mas sem o opt-in: fica fora do resultado do filtro de cidade.
    cityfPerformerWith('NaoOptou', [['state' => 'SP', 'city' => 'São Paulo', 'is_primary' => true]], findable: false);

    cityfExpectAll(['city' => 'São Paulo'], []);

    // ...mas continua aparecendo no catálogo sem filtro e no filtro por UF (o
    // opt-in é só sobre CIDADE; a UF é pública como sempre).
    expect(cityfNames(cityfAuthRows()))->toBe(['NaoOptou'])
        ->and(cityfNames(cityfAuthRows(['state' => 'SP'])))->toBe(['NaoOptou']);
});

it('desambigua homônimos entre performers pela UF', function () {
    cityfPerformerWith('BonitoMS', [['state' => 'MS', 'city' => 'Bonito', 'is_primary' => true]], findable: true);
    cityfPerformerWith('BonitoPE', [['state' => 'PE', 'city' => 'Bonito', 'is_primary' => true]], findable: true);

    // Cidade + UF isola uma; cidade sem UF alcança as duas (mesmo nome).
    cityfExpectAll(['city' => 'Bonito', 'state' => 'MS'], ['BonitoMS']);
    cityfExpectAll(['city' => 'Bonito'], ['BonitoMS', 'BonitoPE']);
});

it('trocar a UF invalida o par: cidade de SP com UF RJ não casa', function () {
    cityfPerformerWith('SpOptou', [['state' => 'SP', 'city' => 'São Paulo', 'is_primary' => true]], findable: true);

    cityfExpectAll(['city' => 'São Paulo', 'state' => 'RJ'], []);
});

it('mantém o filtro por UF funcionando sem cidade', function () {
    cityfPerformerWith('EmSP', [['state' => 'SP', 'is_primary' => true]], findable: true);
    cityfPerformerWith('EmRJ', [['state' => 'RJ', 'is_primary' => true]], findable: true);

    cityfExpectAll(['state' => 'SP'], ['EmSP']);
});

// ─── A cidade NUNCA vaza, nem para a performer que optou ──────────────────────

it('nunca expõe a cidade no card do catálogo, mesmo com o opt-in ligado', function () {
    cityfPerformerWith('Optou', [['state' => 'SP', 'city' => 'São Paulo', 'is_primary' => true]], findable: true);

    foreach ([cityfAuthRows(['city' => 'São Paulo']), cityfPublicRows(['city' => 'São Paulo']), cityfApiRows(['city' => 'São Paulo'])] as $rows) {
        expect($rows)->toHaveCount(1);
        $row = $rows[0];
        // Nenhuma chave de cidade, e o valor "São Paulo" não aparece em campo algum.
        expect($row)->not->toHaveKey('city')
            ->and($row)->not->toHaveKey('findable_by_city');
        expect(json_encode($row))->not->toContain('São Paulo')->not->toContain('Paulo');
    }
});

// ─── Toggle do opt-in ────────────────────────────────────────────────────────

it('liga e desliga o opt-in pelo toggle dedicado', function () {
    $profile = cityfPerformer('Ana');

    expect($profile->findable_by_city)->toBeFalse();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.findable-by-city.toggle'), ['findable' => true])
        ->assertOk()
        ->assertJson(['findable_by_city' => true]);

    expect($profile->refresh()->findable_by_city)->toBeTrue();

    // Sem o campo: inverte o estado atual.
    test()->actingAs($profile->user)
        ->patchJson(route('performer.findable-by-city.toggle'))
        ->assertOk()
        ->assertJson(['findable_by_city' => false]);

    expect($profile->refresh()->findable_by_city)->toBeFalse();
});

it('recusa o toggle para o membro', function () {
    test()->actingAs(cityfMember())
        ->patchJson(route('performer.findable-by-city.toggle'), ['findable' => true])
        ->assertForbidden();
});

it('recusa um valor não-booleano com 422', function () {
    $profile = cityfPerformer('Ana');

    test()->actingAs($profile->user)
        ->patchJson(route('performer.findable-by-city.toggle'), ['findable' => 'talvez'])
        ->assertStatus(422);
});

it('findable_by_city fica fora do $fillable (anti mass assignment)', function () {
    $profile = cityfPerformer('Ana');

    // fill() não pode ligar o opt-in — só o endpoint dedicado (forceFill) escreve.
    $profile->fill(['findable_by_city' => true]);

    expect($profile->findable_by_city)->toBeFalse();
});

// ─── Hard Delete leva a localização junto (cidade não fica órfã) ──────────────

it('o city_normalized é gravado e some no hard delete da performer', function () {
    $profile = cityfPerformerWith('Optou', [['state' => 'SP', 'city' => 'São Paulo', 'is_primary' => true]], findable: true);

    expect($profile->locations()->first()->city_normalized)->toBe('sao paulo');

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    expect(cityfNames(cityfAuthRows(['city' => 'São Paulo'])))->toBe([]);
});
