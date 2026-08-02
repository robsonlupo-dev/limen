<?php

use App\Models\PerformerLocation;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Localização opt-in da performer (Sprint 9).
 *
 * A feature são DUAS colunas com destinos opostos, e é essa assimetria que os
 * testes existem para travar:
 *
 *  - `state` é público (catálogo, card, perfil, API, filtro);
 *  - `city` é interno e **não sai em lugar nenhum** a não ser na tela em que a
 *    própria performer a edita.
 *
 * O bloco de privacidade abaixo é o coração do arquivo. `city` vazar não daria
 * erro em teste nenhum de funcionalidade — a feature continuaria "funcionando"
 * — e por isso precisa de asserção própria em cada porta.
 *
 * Nunca há coordenadas envolvidas: não existe lat/lng no schema, e a tela não
 * usa a API de geolocalização do navegador. Os dois campos são digitados.
 *
 * Helpers locais (prefixo loc*) para o arquivo ser autossuficiente.
 */

/** Cidade distintiva o bastante para ser procurada por substring na resposta. */
const LOC_CITY = 'Barueri';

function locPerformer(string $stageName, array $attributes = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        // publicCatalog() exige verificada + slug; sem isso o perfil não entra
        // em listagem nenhuma e o teste mediria o recorte, não a localização.
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $attributes));
}

/**
 * Performer com uma LISTA de localizações (Sprint 13), gravada pela dona única
 * (PerformerLocationService) — assim o cache `state`/`city` da primária fica em
 * sincronia como em produção, e os testes de leitura exercitam a tabela, não só
 * a coluna do Sprint 9A. Cada linha é `{state, city?, is_primary?}`.
 *
 * @param  array<int, array{state: string, city?: ?string, is_primary?: bool}>  $locations
 */
function locPerformerWith(string $stageName, array $locations): PerformerProfile
{
    $profile = locPerformer($stageName);

    app(PerformerLocationService::class)->setLocations($profile, $locations);

    return $profile->refresh();
}

function locMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/** As linhas do catálogo AUTENTICADO. */
function locAuthRows(array $query = []): array
{
    $response = test()->actingAs(locMember())->get(route('catalog', $query))->assertOk();

    return $response->viewData('page')['props']['performers']['data'];
}

/** As linhas do catálogo PÚBLICO (sem auth). */
function locPublicRows(array $query = []): array
{
    $response = test()->get(route('performers.public', $query))->assertOk();

    return $response->viewData('page')['props']['performers']['data'];
}

/** As linhas da API v1 — a terceira porta que serve o mesmo resource. */
function locApiRows(array $query = []): array
{
    return test()->getJson(route('performers.index', $query))->assertOk()->json('data');
}

/** Nomes artísticos ordenados, para as asserções de filtro. */
function locNames(array $rows): array
{
    return collect($rows)->pluck('stage_name')->sort()->values()->all();
}

/** Roda a mesma expectativa de filtro nas três portas de listagem. */
function locExpectAll(array $query, array $expected): void
{
    expect(locNames(locAuthRows($query)))->toBe($expected)
        ->and(locNames(locPublicRows($query)))->toBe($expected)
        ->and(locNames(locApiRows($query)))->toBe($expected);
}

// ─── Exibição do estado ─────────────────────────────────────────────────────

it('mostra o estado da performer que preencheu a localizacao', function () {
    locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);

    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0]['state'])->toBe('SP');
    }
});

it('mostra o estado no perfil publico e no perfil do catalogo', function () {
    $profile = locPerformer('Ana', ['state' => 'RJ', 'city' => LOC_CITY]);

    test()->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('performer.state', 'RJ'));

    test()->actingAs(locMember())->get(route('catalog.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('performer.state', 'RJ'));
});

it('entrega is_live junto do estado nas duas telas de perfil', function () {
    $profile = locPerformer('Ana', ['state' => 'RJ', 'is_live' => true]);

    // O estado some da tela enquanto ela transmite, e essa regra é um `v-if`
    // sobre AS DUAS props (`performer.state && !performer.is_live`). Não há
    // runner de JS no repo para exercitar o template, mas o pé server-side dá
    // para travar: se `is_live` sumisse do resource, `!performer.is_live` viraria
    // sempre-verdadeiro e o estado voltaria a aparecer durante a live — em
    // silêncio, sem quebrar teste nenhum. Por isso a prop é asserida aqui.
    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(locMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.state', 'RJ')
            ->where('performer.is_live', true)
        );
    }
});

it('nao mostra nada para a performer sem localizacao', function () {
    locPerformer('Ana');

    // A chave existe e vem null — é o que faz o `v-if` da tela esconder a linha
    // inteira. Opt-in: não preencher é estado normal, não pendência a anunciar.
    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0]['state'])->toBeNull();
    }

    $profile = PerformerProfile::sole();

    test()->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('performer.state', null));
});

// ─── PRIVACIDADE: a cidade não sai ──────────────────────────────────────────

it('nunca expoe a cidade em nenhuma das portas publicas', function () {
    $profile = locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);

    // 1. Listagens: a chave não existe no resource, nas três portas.
    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0])->not->toHaveKey('city');
    }

    // 2. Perfis: idem, e a checagem é por SUBSTRING na resposta inteira — a
    // chave ausente prova que o resource não a manda, mas não impediria uma
    // prop solta acrescentada ao lado (é assim que `tips_count` e `rate_*`
    // chegam ao perfil). O corpo inteiro fecha os dois casos de uma vez.
    $responses = [
        test()->get(route('performers.public.show', $profile->slug))->assertOk(),
        test()->actingAs(locMember())->get(route('catalog.show', $profile->slug))->assertOk(),
        test()->getJson(route('performers.show', $profile->slug))->assertOk(),
        test()->actingAs(locMember())->get(route('catalog'))->assertOk(),
        test()->get(route('performers.public'))->assertOk(),
        test()->getJson(route('performers.index'))->assertOk(),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain(LOC_CITY);
    }
});

it('entrega a cidade apenas na tela em que a propria performer a edita', function () {
    $profile = locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);

    // A exceção legítima: é o dado dela, na tela dela, para ela editar. Sem
    // isto o campo abriria vazio e salvar limparia a cidade sem querer.
    test()->actingAs($profile->user)
        ->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.city', LOC_CITY)
            ->where('profile.state', 'SP')
        );
});

it('nao entrega a cidade de uma performer para OUTRA performer', function () {
    $target = locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);
    $other = locPerformer('Bia');

    // A tela de edição devolve o perfil de quem está logado, nunca o do slug —
    // mas a asserção fica registrada: a exceção acima é "a própria", não
    // "qualquer performer".
    test()->actingAs($other->user)
        ->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('profile.city', null));

    test()->actingAs($other->user)
        ->get(route('catalog.show', $target->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('performer.city'));
});

// ─── Filtro por estado ──────────────────────────────────────────────────────

it('filtra o catalogo por estado nas tres portas', function () {
    locPerformer('Ana', ['state' => 'SP']);
    locPerformer('Bia', ['state' => 'RJ']);
    locPerformer('Cris', ['state' => 'SP']);

    locExpectAll(['state' => 'SP'], ['Ana', 'Cris']);
    locExpectAll(['state' => 'RJ'], ['Bia']);
});

it('deixa passar todo mundo quando nenhum estado e escolhido', function () {
    locPerformer('Ana', ['state' => 'SP']);
    locPerformer('Bia'); // sem localização

    // A faceta é opcional: sem ela, quem não preencheu continua no catálogo.
    locExpectAll([], ['Ana', 'Bia']);
});

it('nao devolve a performer sem estado quando um estado e escolhido', function () {
    locPerformer('Ana', ['state' => 'SP']);
    locPerformer('Bia');

    // Não é "sumir": pedir SP é pedir quem se declarou em SP. Quem não declarou
    // não casa — e é o preço de o campo ser opt-in.
    locExpectAll(['state' => 'SP'], ['Ana']);
});

it('recusa um estado que nao existe', function () {
    locPerformer('Ana', ['state' => 'SP']);

    // Rule::in(STATES) nas três portas. 'XX' não é UF; e o catálogo web
    // redireciona de volta com erro de sessão (rota web — ver bootstrap/app.php),
    // enquanto a API responde 422 JSON.
    test()->actingAs(locMember())->get(route('catalog', ['state' => 'XX']))
        ->assertFound()
        ->assertSessionHasErrors('state');

    test()->getJson(route('performers.index', ['state' => 'XX']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('state');
});

// ─── Escrita pela porta dedicada (Sprint 13) ─────────────────────────────────
//
// A localização saiu do `performer.profile.save`: virou lista e tem porta
// própria (`performer.locations.update` → UpdatePerformerLocationsRequest →
// PerformerLocationService, a dona única da escrita). O save do perfil não toca
// mais em `state`/`city` — que agora são o CACHE da primária, escrito só pelo
// service. Estes testes travam a nova porta e a sincronia do cache.

it('salva estado e cidade pela porta de localizacoes e sincroniza o cache', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'MG', 'city' => LOC_CITY, 'is_primary' => true],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    // A linha nasce na tabela nova...
    expect($profile->locations)->toHaveCount(1)
        ->and($profile->locations[0]->state)->toBe('MG')
        ->and($profile->locations[0]->city)->toBe(LOC_CITY)
        ->and($profile->locations[0]->is_primary)->toBeTrue();

    // ...e o cache `state`/`city` da coluna espelha a primária (compat Sprint 9A).
    expect($profile->state)->toBe('MG')
        ->and($profile->city)->toBe(LOC_CITY);
});

it('o save do perfil nao toca mais na localizacao', function () {
    // Regressão do segundo dono: um POST do perfil sem `state`/`city` NÃO pode
    // zerar a localização gravada pela porta dedicada. Era o buraco que motivou
    // tirar os campos do UpdatePerformerProfileRequest.
    $profile = locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => LOC_CITY, 'is_primary' => true],
    ]);

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    expect($profile->state)->toBe('SP')
        ->and($profile->city)->toBe(LOC_CITY)
        ->and($profile->locations)->toHaveCount(1);
});

it('salva multiplas localizacoes com uma primaria', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
                ['state' => 'RJ', 'city' => 'Niterói', 'is_primary' => false],
                ['state' => 'MG', 'city' => null, 'is_primary' => false],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    // Ordem de envio = posição; primária alimenta o cache.
    expect($profile->locations->pluck('state')->all())->toBe(['SP', 'RJ', 'MG'])
        ->and($profile->state)->toBe('SP')
        ->and($profile->city)->toBe('Barueri');
});

it('limpa a localizacao quando a performer manda a lista vazia', function () {
    $profile = locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => LOC_CITY, 'is_primary' => true],
    ]);

    // É o "Não compartilhar localização": a lista vazia apaga as linhas e zera o
    // cache. `present` no request deixa `[]` chegar de propósito.
    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), ['locations' => []])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    expect($profile->locations)->toHaveCount(0)
        ->and($profile->state)->toBeNull()
        ->and($profile->city)->toBeNull();

    // E some do catálogo junto.
    expect(locAuthRows()[0]['state'])->toBeNull();
});

it('recusa um estado invalido vindo do formulario', function () {
    $profile = locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => null, 'is_primary' => true],
    ]);

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'Sao Paulo', 'is_primary' => true],
            ],
        ])
        ->assertSessionHasErrors('locations.0.state');

    // O valor anterior sobrevive à recusa (o service nem foi chamado).
    expect($profile->fresh()->state)->toBe('SP');
});

it('recusa uma cidade acima do limite de 100 caracteres', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'city' => Str::repeat('a', 101), 'is_primary' => true],
            ],
        ])
        ->assertSessionHasErrors('locations.0.city');
});

it('recusa mais localizacoes que o teto', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'is_primary' => true],
                ['state' => 'RJ', 'is_primary' => false],
                ['state' => 'MG', 'is_primary' => false],
                ['state' => 'BA', 'is_primary' => false],
            ],
        ])
        ->assertSessionHasErrors('locations');

    expect($profile->fresh()->locations)->toHaveCount(0);
});

it('exige exatamente uma primaria', function () {
    $profile = locPerformer('Ana');

    // Nenhuma marcada.
    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'is_primary' => false],
                ['state' => 'RJ', 'is_primary' => false],
            ],
        ])
        ->assertSessionHasErrors('locations');

    // Duas marcadas.
    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'is_primary' => true],
                ['state' => 'RJ', 'is_primary' => true],
            ],
        ])
        ->assertSessionHasErrors('locations');

    expect($profile->fresh()->locations)->toHaveCount(0);
});

it('recusa localizacao duplicada (mesmo estado e cidade)', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->put(route('performer.locations.update'), [
            'locations' => [
                ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
                ['state' => 'SP', 'city' => 'barueri', 'is_primary' => false],
            ],
        ])
        ->assertSessionHasErrors('locations');

    expect($profile->fresh()->locations)->toHaveCount(0);
});

// ─── Múltiplas localizações: leitura pública (Sprint 13) ─────────────────────

it('expoe todas as UFs em states, na ordem de exibicao', function () {
    locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
        ['state' => 'RJ', 'city' => 'Niterói', 'is_primary' => false],
    ]);

    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0]['states'])->toBe(['SP', 'RJ']);
        // A primária segue no `state` singular (cache/compat).
        expect($rows[0]['state'])->toBe('SP');
    }
});

it('cai em [state] pelo fallback quando so ha a coluna do Sprint 9A', function () {
    // Linha criada direto (sem migrar para a tabela): `states` deriva do cache.
    locPerformer('Ana', ['state' => 'SP']);

    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0]['states'])->toBe(['SP']);
    }
});

it('devolve states vazio para quem nao tem localizacao', function () {
    locPerformer('Ana');

    foreach ([locAuthRows(), locPublicRows(), locApiRows()] as $rows) {
        expect($rows[0]['states'])->toBe([]);
    }
});

it('nunca expoe a cidade de NENHUMA das localizacoes', function () {
    // Cada linha com uma cidade distinta e procurável — o vazamento de uma
    // secundária não daria erro em teste de funcionalidade nenhum.
    $profile = locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
        ['state' => 'RJ', 'city' => 'Petrópolis', 'is_primary' => false],
    ]);

    $responses = [
        test()->get(route('performers.public.show', $profile->slug))->assertOk(),
        test()->actingAs(locMember())->get(route('catalog.show', $profile->slug))->assertOk(),
        test()->getJson(route('performers.show', $profile->slug))->assertOk(),
        test()->actingAs(locMember())->get(route('catalog'))->assertOk(),
        test()->get(route('performers.public'))->assertOk(),
        test()->getJson(route('performers.index'))->assertOk(),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())
            ->not->toContain('Barueri')
            ->and($response->getContent())->not->toContain('Petrópolis');
    }
});

// ─── Múltiplas localizações: filtro por estado (Sprint 13) ───────────────────

it('filtra por QUALQUER localizacao, nao so a primaria', function () {
    // Ana atende em SP (primária) e RJ (secundária).
    locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
        ['state' => 'RJ', 'city' => null, 'is_primary' => false],
    ]);
    // Bia só em RJ (coluna do Sprint 9A, fallback).
    locPerformer('Bia', ['state' => 'RJ']);

    // Filtrar por RJ traz as duas — Ana pela secundária, Bia pelo cache.
    locExpectAll(['state' => 'RJ'], ['Ana', 'Bia']);
    // Filtrar por SP traz só Ana (a primária dela).
    locExpectAll(['state' => 'SP'], ['Ana']);
});

// ─── Hard Delete ────────────────────────────────────────────────────────────

it('limpa cidade e estado no hard delete', function () {
    $profile = locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    // `withTrashed`: o perfil vira soft-delete no fim do expurgo, e sem isto a
    // query não acharia a linha e o teste passaria sem olhar nada.
    $scrubbed = PerformerProfile::withTrashed()->find($profile->id);

    expect($scrubbed->state)->toBeNull()
        ->and($scrubbed->city)->toBeNull();
});

it('apaga as linhas de localizacao no hard delete', function () {
    $profile = locPerformerWith('Ana', [
        ['state' => 'SP', 'city' => 'Barueri', 'is_primary' => true],
        ['state' => 'RJ', 'city' => 'Niterói', 'is_primary' => false],
    ]);

    expect(PerformerLocation::where('performer_profile_id', $profile->id)->count())->toBe(2);

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    // DELETE real: a FK cascadeOnDelete não dispara (o perfil é soft-delete), então
    // sem purgePerformerLocations() a linha — `city` interno incluído — sobreviveria.
    expect(PerformerLocation::where('performer_profile_id', $profile->id)->count())->toBe(0);
});
