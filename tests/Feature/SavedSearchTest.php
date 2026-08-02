<?php

use App\Models\PerformerProfile;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Buscas salvas do membro (Sprint 12).
 *
 * O CRUD é a parte fácil. O que estes testes travam:
 *  - é do MEMBRO e só dele — a performer não alcança rota nenhuma (a direção
 *    segura, decisão do PO na R3 do Sprint 9);
 *  - o teto de 10 é DURO, imposto sob lock no service;
 *  - a busca de outro membro responde 404 (indistinguível de inexistente);
 *  - o Hard Delete leva as buscas junto (item 11 do CLAUDE.md — a FK
 *    `cascadeOnDelete` não dispara porque `users` é soft-delete/anonimização);
 *  - só o allowlist de facetas atravessa para o JSON — chave desconhecida é
 *    descartada antes de gravar.
 *
 * Helpers locais com prefixo ss*.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function ssMember(string $status = 'active'): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => $status,
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);
}

function ssPerformer(): User
{
    return User::factory()->create(['role' => 'performer', 'status' => 'active']);
}

/** Cria a linha direto (FKs/conteúdo fora do $fillable). */
function ssSeed(User $member, int $n): void
{
    foreach (range(1, $n) as $i) {
        $s = new SavedSearch;
        $s->user_id = $member->id;
        $s->name = "Busca {$i}";
        $s->filters = ['is_live' => true];
        $s->save();
    }
}

// ─── Salvar ──────────────────────────────────────────────────────────────────

it('salva uma busca com filtros', function () {
    $member = ssMember();
    $tag = PerformerProfile::allTags()[0];

    $response = $this->actingAs($member)
        ->postJson(route('saved-searches.store'), [
            'name' => 'Fitness SP',
            'filters' => [
                'is_live' => true,
                'state' => PerformerProfile::STATES[0],
                'tags' => [$tag],
                'height_min' => 160,
                'height_max' => 180,
            ],
        ])
        ->assertCreated();

    expect($response->json('saved_search.name'))->toBe('Fitness SP')
        ->and($response->json('saved_search.id'))->toBeInt();

    $saved = SavedSearch::sole();
    expect($saved->user_id)->toBe($member->id)
        ->and($saved->name)->toBe('Fitness SP')
        // Os filtros voltam como array já desserializado, com os tipos
        // preservados. Canonicalizing porque a coluna JSON do MySQL não preserva
        // a ORDEM das chaves — o que importa é o conteúdo, não a sequência.
        ->and($saved->filters)->toEqualCanonicalizing([
            'is_live' => true,
            'state' => PerformerProfile::STATES[0],
            'tags' => [$tag],
            'height_min' => 160,
            'height_max' => 180,
        ]);
});

it('descarta chaves de filtro que o catálogo não conhece', function () {
    $member = ssMember();

    $this->actingAs($member)
        ->postJson(route('saved-searches.store'), [
            'name' => 'Só o que vale',
            'filters' => [
                'is_live' => true,
                // Não faz parte do allowlist do catálogo — deve sumir no gravar.
                'cidade_secreta' => 'São Paulo',
                'sql' => 'DROP TABLE',
            ],
        ])
        ->assertCreated();

    // Só a faceta conhecida sobreviveu: o JSON nunca vira blob arbitrário.
    expect(SavedSearch::sole()->filters)->toBe(['is_live' => true]);
});

it('recusa nome vazio e filtros vazios', function () {
    $member = ssMember();

    $this->actingAs($member)
        ->postJson(route('saved-searches.store'), ['name' => '', 'filters' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'filters']);

    expect(SavedSearch::count())->toBe(0);
});

it('recusa faceta com valor inválido, como o catálogo', function () {
    $member = ssMember();

    // A validação dos filtros reusa PerformerCatalogService::filterRules() — uma
    // tag inexistente é recusada aqui exatamente como seria no catálogo.
    $this->actingAs($member)
        ->postJson(route('saved-searches.store'), [
            'name' => 'Inválida',
            'filters' => ['tags' => ['tag-que-nao-existe']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filters.tags.0']);
});

// ─── Listar ──────────────────────────────────────────────────────────────────

it('lista as buscas salvas do membro, mais recente primeiro', function () {
    $member = ssMember();
    ssSeed($member, 3);

    // Ruído de outro membro: nunca aparece.
    ssSeed(ssMember(), 2);

    $list = $this->actingAs($member)
        ->getJson(route('saved-searches.index'))
        ->assertOk()
        ->json('saved_searches');

    expect($list)->toHaveCount(3)
        // latest('id'): a última criada vem primeiro.
        ->and($list[0]['name'])->toBe('Busca 3')
        ->and(array_keys($list[0]))->toBe(['id', 'name', 'filters']);
});

it('nunca devolve o id do membro na listagem', function () {
    $member = ssMember();
    ssSeed($member, 1);

    $content = $this->actingAs($member)
        ->getJson(route('saved-searches.index'))
        ->assertOk()
        ->getContent();

    // user_id é chave interna — o $hidden do model e o mapa do service o tiram.
    expect($content)->not->toContain('user_id');
});

// ─── Deletar ─────────────────────────────────────────────────────────────────

it('deleta a própria busca salva', function () {
    $member = ssMember();
    ssSeed($member, 1);
    $search = SavedSearch::sole();

    $this->actingAs($member)
        ->deleteJson(route('saved-searches.destroy', $search->id))
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    expect(SavedSearch::count())->toBe(0);
});

it('responde 404 ao tentar deletar a busca de outro membro', function () {
    $dono = ssMember();
    ssSeed($dono, 1);
    $search = SavedSearch::sole();

    // Id EXISTENTE (o binding resolve): o que barra é a checagem de dono, e a
    // resposta é 404 — indistinguível de inexistente, para não virar oráculo.
    $this->actingAs(ssMember())
        ->deleteJson(route('saved-searches.destroy', $search->id))
        ->assertNotFound();

    // E a busca do dono continua intacta.
    expect(SavedSearch::whereKey($search->id)->exists())->toBeTrue();
});

// ─── Cap de 10 ────────────────────────────────────────────────────────────────

it('recusa a 11ª busca salva', function () {
    $member = ssMember();
    ssSeed($member, SavedSearch::MAX_SAVED);

    $this->actingAs($member)
        ->postJson(route('saved-searches.store'), [
            'name' => 'A gota',
            'filters' => ['is_live' => true],
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'limit');

    expect(SavedSearch::where('user_id', $member->id)->count())->toBe(SavedSearch::MAX_SAVED)
        ->and(SavedSearch::MAX_SAVED)->toBe(10);
});

// ─── Gates: só o membro ────────────────────────────────────────────────────────

it('barra a performer em todas as rotas de busca salva', function () {
    $member = ssMember();
    ssSeed($member, 1);
    $search = SavedSearch::sole();

    $performer = ssPerformer();

    // Id EXISTENTE no DELETE: o SubstituteBindings roda antes do `role:consumer`,
    // então um id inexistente daria 404 e o teste passaria sem exercitar o gate.
    $this->actingAs($performer)->getJson(route('saved-searches.index'))->assertForbidden();
    $this->actingAs($performer)->postJson(route('saved-searches.store'), [])->assertForbidden();
    $this->actingAs($performer)->deleteJson(route('saved-searches.destroy', $search->id))->assertForbidden();

    // E nada foi tocado.
    expect(SavedSearch::count())->toBe(1);
});

it('exige a verificação do membro nas rotas de busca salva', function () {
    // `member.verified` cobre o grupo inteiro da área do membro.
    $pendente = User::factory()->create(['role' => 'consumer', 'status' => 'pending_kyc']);

    $this->actingAs($pendente)
        ->get(route('saved-searches.index'))
        ->assertRedirect(route('consumer.kyc.index'));
});

// ─── Hard Delete ──────────────────────────────────────────────────────────────

it('apaga as buscas salvas no Hard Delete do membro', function () {
    $member = ssMember();
    ssSeed($member, 4);

    // Outro membro para provar que a varredura é por user_id, não um TRUNCATE.
    $outro = ssMember();
    ssSeed($outro, 2);

    $log = app(DeletionService::class)->executeDeletion($member);

    expect($log->data_summary['saved_searches'] ?? null)->toBe(4)
        ->and(SavedSearch::where('user_id', $member->id)->count())->toBe(0)
        // A FK cascadeOnDelete NÃO dispara (users é anonimização/soft-delete):
        // quem apaga é o purgeSavedSearches. As do outro membro ficam de pé.
        ->and(SavedSearch::where('user_id', $outro->id)->count())->toBe(2);
});
