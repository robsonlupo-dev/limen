<?php

use App\Models\Favorite;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\FavoriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Favoritos do membro (Sprint 10) — bookmark PRIVADO.
 *
 * O CRUD é a parte fácil. O que estes testes travam é a assimetria com o
 * follow, que é a razão de a feature existir:
 *
 *  - a performer NUNCA sabe que foi favoritada — nenhum prop, nenhum contador,
 *    nenhuma relação inversa, nenhuma linha em `audit_logs`;
 *  - o toggle é idempotente sob duplo-submit: o UNIQUE nunca vira 500 nem
 *    linha duplicada;
 *  - o Hard Delete leva os favoritos NOS DOIS SENTIDOS (item 11 do CLAUDE.md —
 *    as FKs `cascadeOnDelete` nunca disparam, porque nenhum dos dois lados
 *    sofre DELETE físico);
 *  - o gate é auth + role:consumer + member.verified, testado com id EXISTENTE
 *    (SubstituteBindings roda antes do middleware de rota).
 *
 * Helpers locais com prefixo fav*.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function favMember(string $status = 'active'): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => $status,
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);
}

function favPerformerProfile(array $overrides = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $overrides));
}

/** Cria a linha direto, sem passar pelo endpoint (FKs fora do $fillable). */
function favSave(User $member, PerformerProfile $profile): Favorite
{
    $favorite = new Favorite;
    $favorite->user_id = $member->id;
    $favorite->performer_profile_id = $profile->id;
    $favorite->save();

    return $favorite;
}

function favCount(User $member): int
{
    return DB::table('favorites')->where('user_id', $member->id)->count();
}

// ─── Toggle ─────────────────────────────────────────────────────────────────

it('salva um perfil no primeiro clique do coração', function () {
    $member = favMember();
    $profile = favPerformerProfile();

    $this->actingAs($member)
        ->post(route('favorites.toggle', $profile->slug))
        ->assertRedirect();

    expect(favCount($member))->toBe(1);
    expect(DB::table('favorites')->where('user_id', $member->id)->value('performer_profile_id'))
        ->toBe($profile->id);
});

it('remove o perfil no segundo clique — o mesmo POST alterna', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    favSave($member, $profile);

    $this->actingAs($member)
        ->post(route('favorites.toggle', $profile->slug))
        ->assertRedirect();

    expect(favCount($member))->toBe(0);
});

it('nunca cria linha duplicada: o UNIQUE cobre o par (membro, perfil)', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    favSave($member, $profile);

    expect(fn () => favSave($member, $profile))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    expect(favCount($member))->toBe(1);
});

it('o toggle é idempotente nos dois ramos: remove quando existe, cria quando não', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    $service = app(FavoriteService::class);

    // Este teste cobre os DOIS ramos normais do toggle — mas NÃO o catch do
    // UNIQUE: com a linha já presente, o `lockForUpdate->first()` a encontra e o
    // fluxo entra pelo DELETE, sem nunca chegar ao INSERT. O caminho do catch
    // (first() devolve null E o INSERT colide) tem teste próprio logo abaixo.
    favSave($member, $profile);

    expect($service->toggle($member, $profile))->toBeFalse(); // existe → remove
    expect(favCount($member))->toBe(0);

    expect($service->toggle($member, $profile))->toBeTrue(); // não existe → cria
    expect(favCount($member))->toBe(1);
});

it('exercita o catch do UNIQUE de verdade: a corrida perde o INSERT mas devolve favoritado', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    $service = app(FavoriteService::class);

    // A corrida REAL, reproduzida de forma determinística: esta requisição leu
    // "não existe" (nada gravado ainda, o lockForUpdate->first() devolve null) e
    // SÓ ENTÃO a requisição concorrente gravou a linha. O gancho `creating` é
    // exatamente o intervalo entre o first() e o INSERT deste caminho — injetamos
    // ali a linha concorrente, na MESMA transação, para que o INSERT do serviço
    // colida no índice UNIQUE e caia no catch. É a única forma de fazer o first()
    // ver null E o save() explodir num único processo de teste.
    $injetou = false;
    Favorite::creating(function () use (&$injetou, $member, $profile) {
        if ($injetou) {
            return;
        }
        $injetou = true;

        DB::table('favorites')->insert([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'created_at' => now(),
        ]);
    });

    try {
        // Não explode: o UniqueConstraintViolationException é apanhado dentro do
        // serviço e o estado pedido (favoritado) é o retorno — nunca um 500.
        expect($service->toggle($member, $profile))->toBeTrue();
    } finally {
        // O listener é estático e sobreviveria ao teste; remove para não vazar
        // para os seguintes.
        Favorite::flushEventListeners();
    }

    // A linha concorrente é a única: o catch NÃO criou uma segunda nem duplicou.
    expect(favCount($member))->toBe(1);
});

it('favoritar duas performers diferentes é permitido — o UNIQUE é por par', function () {
    $member = favMember();
    $a = favPerformerProfile();
    $b = favPerformerProfile();

    $this->actingAs($member)->post(route('favorites.toggle', $a->slug));
    $this->actingAs($member)->post(route('favorites.toggle', $b->slug));

    expect(favCount($member))->toBe(2);
});

it('404 para um slug fora do catálogo público — não vira oráculo de existência', function () {
    $member = favMember();
    $oculta = favPerformerProfile(['is_verified' => false]);

    $this->actingAs($member)
        ->post(route('favorites.toggle', $oculta->slug))
        ->assertNotFound();

    // Mesma resposta de um slug que não existe: os dois casos são
    // indistinguíveis para quem sonda.
    $this->actingAs($member)
        ->post(route('favorites.toggle', 'nao-existe-mesmo'))
        ->assertNotFound();

    expect(favCount($member))->toBe(0);
});

it('o gate de "perfil no ar" mora no serviço: recusa perfil suspenso mesmo chamado direto', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    $service = app(FavoriteService::class);

    // Chamada DIRETA ao serviço, sem passar pelo findBySlug do controller. Antes,
    // o gate vivia só no controller e este caminho gravava um favorito apontando
    // para um perfil morto. `forceFill`: status está fora do $fillable do User.
    $profile->user->forceFill(['status' => 'suspended'])->save();

    expect(fn () => $service->toggle($member, $profile))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(favCount($member))->toBe(0);
});

it('o gate do serviço recusa perfil soft-deletado', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    $service = app(FavoriteService::class);

    $profile->delete(); // soft delete

    expect(fn () => $service->toggle($member, $profile))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(favCount($member))->toBe(0);
});

// ─── Lista ──────────────────────────────────────────────────────────────────

it('lista os favoritos paginados, mais recentes primeiro', function () {
    $member = favMember();
    $profiles = collect(range(1, 3))->map(fn () => favPerformerProfile())->all();

    foreach ($profiles as $i => $profile) {
        $favorite = favSave($member, $profile);
        // Carimbos distintos: sem isso os três nascem no mesmo segundo e a
        // ordenação por data não teria o que ordenar.
        DB::table('favorites')->where('id', $favorite->id)
            ->update(['created_at' => now()->subMinutes(10 - $i)]);
    }

    $this->actingAs($member)
        ->get(route('favorites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Favorites/Index')
            ->has('performers.data', 3)
            // O último salvo abre a lista.
            ->where('performers.data.0.slug', $profiles[2]->slug)
            ->where('performers.data.2.slug', $profiles[0]->slug)
            ->where('performers.data.0.is_favorited', true)
        );
});

it('a lista vem vazia para quem nunca salvou nada', function () {
    $member = favMember();

    $this->actingAs($member)
        ->get(route('favorites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Favorites/Index')
            ->has('performers.data', 0)
        );
});

it('a lista pagina em 20 e o total reflete todos os salvos', function () {
    $member = favMember();

    for ($i = 0; $i < 22; $i++) {
        favSave($member, favPerformerProfile());
    }

    $this->actingAs($member)
        ->get(route('favorites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('performers.data', 20)
            ->where('performers.meta.total', 22)
        );

    $this->actingAs($member)
        ->get(route('favorites.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('performers.data', 2));
});

it('perfil que saiu do ar some da lista sem apagar o favorito', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    favSave($member, $profile);

    // `forceFill`: `status` está fora do $fillable do User de propósito (a troca
    // é ato de autoridade). Um `update()` aqui passaria em silêncio sem gravar
    // nada, e o teste ficaria verde sem nunca ter tirado a performer do ar.
    $profile->user->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($member)
        ->get(route('favorites.index'))
        ->assertInertia(fn (Assert $page) => $page->has('performers.data', 0));

    // A escolha do membro continua guardada: o estado é do lado dela, e ele
    // não pediu para desfazer nada.
    expect(favCount($member))->toBe(1);
});

it('só mostra os favoritos do próprio membro', function () {
    $eu = favMember();
    $outro = favMember();
    favSave($outro, favPerformerProfile());

    $this->actingAs($eu)
        ->get(route('favorites.index'))
        ->assertInertia(fn (Assert $page) => $page->has('performers.data', 0));
});

// ─── O estado chega ao catálogo ─────────────────────────────────────────────

it('o catálogo marca o coração preenchido só nos perfis salvos', function () {
    $member = favMember();
    $salvo = favPerformerProfile();
    $naoSalvo = favPerformerProfile();
    favSave($member, $salvo);

    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(function (Assert $page) use ($salvo, $naoSalvo) {
            $data = collect($page->toArray()['props']['performers']['data']);

            expect($data->firstWhere('slug', $salvo->slug)['is_favorited'])->toBeTrue();
            expect($data->firstWhere('slug', $naoSalvo->slug)['is_favorited'])->toBeFalse();
        });
});

it('a página do perfil traz o estado do favorito para o membro', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    favSave($member, $profile);

    $this->actingAs($member)
        ->get(route('catalog.show', $profile->slug))
        ->assertInertia(fn (Assert $page) => $page->where('performer.is_favorited', true));

    $this->actingAs($member)
        ->get(route('performers.public.show', $profile->slug))
        ->assertInertia(fn (Assert $page) => $page->where('favorite.saved', true));
});

it('a página pública não oferece o coração a visitante nem a performer', function () {
    $profile = favPerformerProfile();

    $this->get(route('performers.public.show', $profile->slug))
        ->assertInertia(fn (Assert $page) => $page->where('favorite', null));

    $this->actingAs(favPerformerProfile()->user)
        ->get(route('performers.public.show', $profile->slug))
        ->assertInertia(fn (Assert $page) => $page->where('favorite', null));
});

// ─── A performer NÃO sabe ───────────────────────────────────────────────────

it('não expõe relação inversa em PerformerProfile', function () {
    $profile = favPerformerProfile();

    // A relação é a porta pela qual um withCount entraria num resource dela.
    expect(method_exists($profile, 'favorites'))->toBeFalse();
});

it('não existe coluna de contador de favoritos em performer_profiles', function () {
    $columns = Schema::getColumnListing('performer_profiles');

    expect($columns)->not->toContain('favorites_count');
});

it('nenhuma tela da performer vaza o favorito', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    favSave($member, $profile);

    // O membro também SEGUE, para que ele apareça de fato nas listas dela e o
    // teste não passe por lista vazia. O piso pede 5 seguidores elegíveis.
    Follow::create([
        'user_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => false,
    ]);

    for ($i = 0; $i < 4; $i++) {
        Follow::create([
            'user_id' => favMember()->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => false,
        ]);
    }

    foreach ([route('performer.dashboard'), route('performer.followers')] as $url) {
        $response = $this->actingAs($profile->user)->get($url);
        $response->assertSuccessful();

        $props = $response->viewData('page')['props'] ?? [];

        // `ziggy` fora da varredura: a allowlist de rotas é global e idêntica
        // para todo mundo (ela contém `favorites.*` porque o MEMBRO precisa
        // gerar a URL). Nome de rota não é dado de ninguém; o que este teste
        // procura é estado.
        unset($props['ziggy']);

        // Nem a palavra: nenhum `is_favorited`, `favorites`, `favorited_by` nem
        // contador pode ter pegado carona num prop dela.
        expect(json_encode($props))->not->toContain('favorit');
    }
});

it('não grava nada em audit_logs — o mapa de interesses não sobrevive ao Hard Delete', function () {
    $member = favMember();
    $profile = favPerformerProfile();

    $antes = DB::table('audit_logs')->count();

    $this->actingAs($member)->post(route('favorites.toggle', $profile->slug));
    $this->actingAs($member)->post(route('favorites.toggle', $profile->slug));

    // `audit_logs` é a única tabela que o DeletionService preserva intacta, com
    // o IP em claro ao lado. Uma linha por favorito seria a cópia permanente do
    // que esta feature apaga — mesmo corte do slug do Estilo de Vida.
    expect(DB::table('audit_logs')->count())->toBe($antes);
});

// ─── Gates ──────────────────────────────────────────────────────────────────

it('exige autenticação', function () {
    $profile = favPerformerProfile();

    $this->post(route('favorites.toggle', $profile->slug))->assertRedirect(route('login'));
    $this->get(route('favorites.index'))->assertRedirect(route('login'));
});

it('recusa performer e admin — role:consumer', function () {
    // Slug EXISTENTE de propósito: SubstituteBindings roda antes do middleware
    // de rota, então um slug inventado daria 404 e o teste passaria sem nunca
    // exercitar o gate (CLAUDE.md, § Convenções).
    $profile = favPerformerProfile();
    $performer = favPerformerProfile()->user;
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    foreach ([$performer, $admin] as $user) {
        $this->actingAs($user)
            ->post(route('favorites.toggle', $profile->slug))
            ->assertForbidden();

        $this->actingAs($user)->get(route('favorites.index'))->assertForbidden();
    }

    expect(DB::table('favorites')->count())->toBe(0);
});

it('segura o membro sem KYC Nível 2 — member.verified', function () {
    $profile = favPerformerProfile();
    $pendente = favMember('pending_kyc');

    $this->actingAs($pendente)
        ->post(route('favorites.toggle', $profile->slug))
        ->assertRedirect(route('consumer.kyc.index'));

    $this->actingAs($pendente)
        ->get(route('favorites.index'))
        ->assertRedirect(route('consumer.kyc.index'));

    expect(favCount($pendente))->toBe(0);
});

it('aplica o teto de 30 toggles por minuto', function () {
    $member = favMember();
    $profile = favPerformerProfile();

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($member)
            ->post(route('favorites.toggle', $profile->slug))
            ->assertRedirect();
    }

    $this->actingAs($member)
        ->post(route('favorites.toggle', $profile->slug))
        ->assertStatus(429);
});

// ─── Hard Delete ────────────────────────────────────────────────────────────

it('o Hard Delete do membro leva os favoritos dele', function () {
    $member = favMember();
    $outro = favMember();
    $profile = favPerformerProfile();

    favSave($member, $profile);
    favSave($outro, $profile);

    app(DeletionService::class)->executeDeletion($member);

    expect(favCount($member))->toBe(0);
    // O favorito de terceiro no mesmo perfil não é afetado.
    expect(favCount($outro))->toBe(1);
});

it('o Hard Delete da performer leva os favoritos APONTADOS para o perfil dela', function () {
    $member = favMember();
    $profile = favPerformerProfile();
    $outroPerfil = favPerformerProfile();

    favSave($member, $profile);
    favSave($member, $outroPerfil);

    app(DeletionService::class)->executeDeletion($profile->user);

    // A FK cascadeOnDelete NÃO dispara (os dois lados são soft-delete /
    // anonimização), então sem a varredura explícita a linha ficaria órfã para
    // sempre — favorito não tem retenção que a varra depois.
    expect(DB::table('favorites')->where('performer_profile_id', $profile->id)->count())->toBe(0);
    expect(DB::table('favorites')->where('performer_profile_id', $outroPerfil->id)->count())->toBe(1);
});

it('conta os favoritos apagados no resumo do Hard Delete', function () {
    $member = favMember();
    favSave($member, favPerformerProfile());
    favSave($member, favPerformerProfile());

    $log = app(DeletionService::class)->executeDeletion($member);

    expect($log->data_summary['favorites'])->toBe(2);
});

// ─── Mass assignment ────────────────────────────────────────────────────────

it('mantém as FKs fora do $fillable', function () {
    $member = favMember();
    $profile = favPerformerProfile();

    // `$fillable` vazio + `$guarded = ['*']` (o padrão) deixam a model
    // TOTALMENTE guardada: o mass assignment não silencia o campo, ele lança.
    // É garantia mais forte do que "atribui null", e é o que se quer aqui — a
    // linha só nasce pelo caminho explícito do FavoriteService, nunca de um
    // array vindo de request.
    expect(fn () => (new Favorite)->fill([
        'user_id' => $member->id,
        'performer_profile_id' => $profile->id,
    ]))->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);

    expect(DB::table('favorites')->count())->toBe(0);
});

it('não escreve updated_at — a linha nasce e morre, nunca é editada', function () {
    expect(Schema::hasColumn('favorites', 'updated_at'))->toBeFalse();
    expect(Favorite::UPDATED_AT)->toBeNull();
});
