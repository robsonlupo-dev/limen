<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Filtros avançados do catálogo (Sprint 9).
 *
 * A tese destes testes é a paridade: as MESMAS facetas valem nas três portas
 * (catálogo autenticado, catálogo público e `GET /api/v1/performers`), porque
 * as três passam por PerformerCatalogService::applyFilters(). Antes disso cada
 * porta tinha a sua cópia e o público só filtrava mundo — a divergência que
 * esta feature fecha.
 *
 * Helpers locais com prefixo cf*.
 */
function cfPerformer(string $stageName, array $attributes = [], array $tags = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    // `tier` fica FORA do $fillable de propósito (é curadoria concedida por
    // admin, nunca payload — ver PerformerProfile). O create() o descartaria em
    // silêncio e o teste do filtro mediria o vazio, então aqui ele entra por
    // forceFill, que é o mesmo caminho do endpoint administrativo.
    $tier = $attributes['tier'] ?? null;
    unset($attributes['tier']);

    $profile = $user->performerProfile()->create(array_merge([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        // publicCatalog() exige verificada + slug; sem isso o perfil não entra
        // em listagem nenhuma e o teste mediria o recorte, não o filtro.
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $attributes));

    if ($tier !== null) {
        $profile->forceFill(['tier' => $tier])->save();
    }

    if ($tags !== []) {
        $profile->syncTags($tags);
    }

    return $profile;
}

function cfMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/**
 * Os nomes artísticos devolvidos pelo catálogo AUTENTICADO, para um dado
 * conjunto de filtros na query string.
 *
 * @return array<int, string>
 */
function cfAuthNames(array $query = []): array
{
    $response = test()->actingAs(cfMember())->get(route('catalog', $query))->assertOk();

    return collect($response->viewData('page')['props']['performers']['data'])
        ->pluck('stage_name')
        ->sort()
        ->values()
        ->all();
}

/**
 * O mesmo, pelo catálogo PÚBLICO (sem auth). Toda asserção de faceta roda nas
 * duas portas — é o que impede o filtro de nascer valendo só depois do login.
 *
 * @return array<int, string>
 */
function cfPublicNames(array $query = []): array
{
    $response = test()->get(route('performers.public', $query))->assertOk();

    return collect($response->viewData('page')['props']['performers']['data'])
        ->pluck('stage_name')
        ->sort()
        ->values()
        ->all();
}

/**
 * E pela API v1, a terceira porta.
 *
 * @return array<int, string>
 */
function cfApiNames(array $query = []): array
{
    $response = test()->getJson(route('performers.index', $query))->assertOk();

    return collect($response->json('data'))->pluck('stage_name')->sort()->values()->all();
}

/** Roda a mesma expectativa nas três portas. */
function cfExpectAll(array $query, array $expected): void
{
    expect(cfAuthNames($query))->toBe($expected)
        ->and(cfPublicNames($query))->toBe($expected)
        ->and(cfApiNames($query))->toBe($expected);
}

// ─── Tags: AND, não OR ───────────────────────────────────────────────────────

it('filters by a single tag', function () {
    cfPerformer('Ana', [], ['fitness']);
    cfPerformer('Bia', [], ['gourmet']);

    cfExpectAll(['tags' => ['fitness']], ['Ana']);
});

it('requires every selected tag, not any of them', function () {
    cfPerformer('Ana', [], ['fitness', 'viajante']);
    cfPerformer('Bia', [], ['fitness']);
    cfPerformer('Carol', [], ['viajante']);

    // A regra do PO: quem tem TODAS. O `whereHas` sem a contagem daria OR e
    // devolveria as três — o filtro pareceria não funcionar justamente quando
    // o membro estreita a busca. A contagem é exata porque a junção tem índice
    // único (performer_profile_id, tag_slug).
    cfExpectAll(['tags' => ['fitness', 'viajante']], ['Ana']);
});

it('returns nobody when no performer has the whole tag set', function () {
    cfPerformer('Ana', [], ['fitness']);
    cfPerformer('Bia', [], ['viajante']);

    cfExpectAll(['tags' => ['fitness', 'viajante', 'luxo']], []);
});

it('ignores an empty tag selection instead of returning nobody', function () {
    cfPerformer('Ana', [], ['fitness']);
    cfPerformer('Bia');

    // Faceta ausente = "não filtra". Um array vazio tratado como filtro
    // devolveria zero resultados na primeira vez que a tela desmarcasse tudo.
    cfExpectAll(['tags' => []], ['Ana', 'Bia']);
});

// ─── Hábitos ────────────────────────────────────────────────────────────────

it('filters by drinks', function () {
    cfPerformer('Ana', ['drinks' => 'nao_bebe']);
    cfPerformer('Bia', ['drinks' => 'bebe_socialmente']);
    cfPerformer('Carol');

    cfExpectAll(['drinks' => 'nao_bebe'], ['Ana']);
});

it('filters by smokes', function () {
    cfPerformer('Ana', ['smokes' => 'nao_fuma']);
    cfPerformer('Bia', ['smokes' => 'fuma']);

    cfExpectAll(['smokes' => 'fuma'], ['Bia']);
});

// ─── Idiomas: OR, ao contrário das tags ─────────────────────────────────────

it('filters by a single language', function () {
    cfPerformer('Ana', ['languages' => ['portugues', 'ingles']]);
    cfPerformer('Bia', ['languages' => ['portugues']]);

    cfExpectAll(['languages' => ['ingles']], ['Ana']);
});

it('widens the result with each extra language, unlike tags', function () {
    cfPerformer('Ana', ['languages' => ['ingles']]);
    cfPerformer('Bia', ['languages' => ['espanhol']]);
    cfPerformer('Carol', ['languages' => ['japones']]);

    // Idioma é CANAL de conversa: quem marca inglês e espanhol fala os dois e
    // quer quem consiga conversar em qualquer um. Em AND, marcar o segundo
    // idioma diminuiria o resultado — o oposto do que a pessoa quis dizer.
    cfExpectAll(['languages' => ['ingles']], ['Ana']);
    cfExpectAll(['languages' => ['ingles', 'espanhol']], ['Ana', 'Bia']);
});

// ─── Altura ─────────────────────────────────────────────────────────────────

it('filters by a height range', function () {
    cfPerformer('Ana', ['height_cm' => 160]);
    cfPerformer('Bia', ['height_cm' => 175]);
    cfPerformer('Carol', ['height_cm' => 188]);

    cfExpectAll(['height_min' => 170, 'height_max' => 180], ['Bia']);
});

it('does not drop performers without a declared height when the slider is untouched', function () {
    cfPerformer('Ana', ['height_cm' => 170]);
    cfPerformer('SemAltura');

    // `height_cm` é nullable e a maioria dos perfis não o preencheu. O slider
    // manda os dois extremos a cada request; se a faixa CHEIA filtrasse, abrir
    // a gaveta de filtros faria o catálogo encolher sem nenhuma faceta marcada
    // e sem explicação na tela. Só filtra quando a faixa foi estreitada.
    cfExpectAll(['height_min' => 140, 'height_max' => 190], ['Ana', 'SemAltura']);

    // E quando é estreitada, quem não declarou fica de fora — é o preço do
    // filtro, dito na copy da tela.
    cfExpectAll(['height_min' => 165, 'height_max' => 190], ['Ana']);
});

it('filters by only one end of the range', function () {
    cfPerformer('Baixa', ['height_cm' => 150]);
    cfPerformer('Alta', ['height_cm' => 185]);

    cfExpectAll(['height_min' => 180], ['Alta']);
    cfExpectAll(['height_max' => 160], ['Baixa']);
});

// ─── Tier e fotos ───────────────────────────────────────────────────────────

it('filters by tier', function () {
    cfPerformer('Ana', ['tier' => 'maison']);
    cfPerformer('Bia', ['tier' => 'select']);
    cfPerformer('Carol');

    cfExpectAll(['tier' => 'maison'], ['Ana']);
});

it('filters by having a profile photo', function () {
    cfPerformer('ComFoto', ['avatar_path' => 'avatars/ana.jpg']);
    cfPerformer('SemFoto');

    cfExpectAll(['has_photo' => 1], ['ComFoto']);

    // Sem a faceta, as duas aparecem — "com foto" estreita, nunca é o padrão.
    cfExpectAll([], ['ComFoto', 'SemFoto']);
});

// ─── Busca textual ──────────────────────────────────────────────────────────

it('searches by stage name and by bio', function () {
    cfPerformer('Marina', ['bio' => 'Adoro café da manhã demorado.']);
    cfPerformer('Joana', ['bio' => 'Viajo o ano inteiro.']);

    cfExpectAll(['search' => 'Mari'], ['Marina']);
    cfExpectAll(['search' => 'Viajo'], ['Joana']);
});

it('keeps the text search inside the AND of the other facets', function () {
    cfPerformer('Marina', ['drinks' => 'nao_bebe']);
    cfPerformer('Mariana', ['drinks' => 'bebe_socialmente']);

    // Sem a closure agrupando o orWhere, a busca escaparia do AND e devolveria
    // as duas — a faceta de bebida seria silenciosamente ignorada.
    cfExpectAll(['search' => 'Mari', 'drinks' => 'nao_bebe'], ['Marina']);
});

// ─── Combinação ─────────────────────────────────────────────────────────────

it('combines every facet at once', function () {
    cfPerformer('Alvo', [
        'drinks' => 'nao_bebe',
        'smokes' => 'nao_fuma',
        'height_cm' => 170,
        'languages' => ['portugues', 'ingles'],
        'tier' => 'select',
        'avatar_path' => 'avatars/alvo.jpg',
        'bio' => 'Gosto de yoga pela manhã.',
    ], ['fitness', 'yoga']);

    // Cada uma destas falha em exatamente uma faceta.
    cfPerformer('QuaseA', ['drinks' => 'bebe_socialmente', 'smokes' => 'nao_fuma', 'height_cm' => 170, 'languages' => ['portugues'], 'tier' => 'select', 'avatar_path' => 'a.jpg'], ['fitness', 'yoga']);
    cfPerformer('QuaseB', ['drinks' => 'nao_bebe', 'smokes' => 'nao_fuma', 'height_cm' => 185, 'languages' => ['portugues'], 'tier' => 'maison', 'avatar_path' => 'b.jpg'], ['fitness', 'yoga']);
    cfPerformer('QuaseC', ['drinks' => 'nao_bebe', 'smokes' => 'nao_fuma', 'height_cm' => 170, 'languages' => ['portugues'], 'tier' => 'select'], ['fitness']);

    cfExpectAll([
        'tags' => ['fitness', 'yoga'],
        'drinks' => 'nao_bebe',
        'smokes' => 'nao_fuma',
        'languages' => ['portugues'],
        'tier' => 'select',
        'has_photo' => 1,
        'height_min' => 165,
        'height_max' => 175,
        'search' => 'yoga',
    ], ['Alvo']);
});

// ─── Paridade entre as três portas ──────────────────────────────────────────

it('applies the same facets on all three surfaces', function () {
    cfPerformer('Ana', ['drinks' => 'nao_bebe', 'height_cm' => 165], ['fitness']);
    cfPerformer('Bia', ['drinks' => 'bebe_socialmente', 'height_cm' => 180], ['gourmet']);

    // O ponto da feature: antes, o catálogo público só filtrava mundo, e um
    // link compartilhado de /performers?tags[]=fitness devolvia o catálogo
    // inteiro para quem não estava logado.
    $query = ['tags' => ['fitness'], 'drinks' => 'nao_bebe', 'height_min' => 160, 'height_max' => 170];

    expect(cfAuthNames($query))->toBe(['Ana'])
        ->and(cfPublicNames($query))->toBe(['Ana'])
        ->and(cfApiNames($query))->toBe(['Ana']);
});

it('keeps the public catalog world filter working alongside the new facets', function () {
    cfPerformer('AnaMulheres', ['category' => 'mulheres', 'worlds' => ['mulheres']], ['fitness']);
    cfPerformer('CarlosHomens', ['category' => 'homens', 'worlds' => ['homens']], ['fitness']);

    expect(cfPublicNames(['mundo' => 'homens', 'tags' => ['fitness']]))->toBe(['CarlosHomens']);
    expect(cfPublicNames(['tags' => ['fitness']]))->toBe(['AnaMulheres', 'CarlosHomens']);
});

// ─── Validação ──────────────────────────────────────────────────────────────

it('rejects a value outside the allowed set instead of ignoring it', function () {
    cfPerformer('Ana');

    // 302 com erro de sessão na porta web. Silenciar o valor inválido faria a
    // tela mostrar o catálogo inteiro como se o filtro tivesse rodado.
    test()->actingAs(cfMember())
        ->get(route('catalog', ['drinks' => 'bebe_muito']))
        ->assertSessionHasErrors('drinks');

    test()->get(route('performers.public', ['tier' => 'diamante']))
        ->assertSessionHasErrors('tier');

    test()->get(route('performers.public', ['tags' => ['nao_existe']]))
        ->assertSessionHasErrors('tags.0');

    test()->get(route('performers.public', ['height_min' => 10]))
        ->assertSessionHasErrors('height_min');

    // Na porta da API o mesmo vira 422 JSON.
    test()->getJson(route('performers.index', ['smokes' => 'charuto']))
        ->assertStatus(422);
});

it('caps the number of tags a single request may filter by', function () {
    cfPerformer('Ana');

    // Sem teto, `tags[]` com centenas de entradas monta um whereIn gigante num
    // endpoint sem auth. O teto é o mesmo MAX_TAGS que uma performer pode ter:
    // pedir mais do que isso não pode casar com ninguém.
    $tooMany = array_slice(PerformerProfile::allTags(), 0, PerformerProfile::MAX_TAGS + 1);

    test()->get(route('performers.public', ['tags' => $tooMany]))
        ->assertSessionHasErrors('tags');
});

// ─── Recorte de segurança: nenhuma faceta o afrouxa ─────────────────────────

it('never surfaces an unverified or inactive performer through a filter', function () {
    $hidden = cfPerformer('NaoVerificada', ['is_verified' => false], ['fitness']);
    $suspended = cfPerformer('Suspensa', [], ['fitness']);
    $suspended->user->forceFill(['status' => 'suspended'])->save();

    cfPerformer('Visivel', [], ['fitness']);

    // O recorte é do publicCatalog() e nenhuma faceta consegue afrouxá-lo —
    // nem combinando várias, nem pela porta pública.
    cfExpectAll(['tags' => ['fitness']], ['Visivel']);

    expect($hidden->fresh()->is_verified)->toBeFalse();
});
