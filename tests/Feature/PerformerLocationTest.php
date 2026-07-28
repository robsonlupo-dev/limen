<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
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

// ─── Escrita pela tela de edição ────────────────────────────────────────────

it('salva estado e cidade pela tela de edicao', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'state' => 'MG',
            'city' => LOC_CITY,
        ])
        ->assertRedirect();

    $profile->refresh();

    expect($profile->state)->toBe('MG')
        ->and($profile->city)->toBe(LOC_CITY);
});

it('salva o perfil sem localizacao nenhuma', function () {
    $profile = locPerformer('Ana');

    // Os dois campos são opcionais: salvar sem eles não pode virar erro de
    // validação, senão o opt-in vira obrigação.
    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    expect($profile->state)->toBeNull()->and($profile->city)->toBeNull();
});

it('limpa os dois campos quando a performer recusa compartilhar', function () {
    $profile = locPerformer('Ana', ['state' => 'SP', 'city' => LOC_CITY]);

    // É o que o link "Não compartilhar localização" manda: o par vazio. O
    // ConvertEmptyStringsToNull do grupo web transforma a string vazia da
    // cidade em null antes da validação.
    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'state' => null,
            'city' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    expect($profile->state)->toBeNull()->and($profile->city)->toBeNull();

    // E some do catálogo junto.
    expect(locAuthRows()[0]['state'])->toBeNull();
});

it('recusa um estado invalido vindo do formulario', function () {
    $profile = locPerformer('Ana', ['state' => 'SP']);

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'state' => 'Sao Paulo'])
        ->assertSessionHasErrors('state');

    // O valor anterior sobrevive à recusa.
    expect($profile->fresh()->state)->toBe('SP');
});

it('recusa uma cidade acima do limite de 100 caracteres', function () {
    $profile = locPerformer('Ana');

    test()->actingAs($profile->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'city' => Str::repeat('a', 101),
        ])
        ->assertSessionHasErrors('city');
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
