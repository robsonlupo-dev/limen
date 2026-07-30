<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\ProfileVisitService;
use App\Services\TipService;
use App\Services\TokenService;
use App\Support\FanAlias;
use App\Support\LifestyleTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * "Estilo de Vida" do membro (Sprint 10).
 *
 * É a PRIMEIRA auto-declaração do membro que volta para a performer — o resto
 * daquela tela (`interests`, `seeking`) nunca sai do servidor. Por isso o que
 * estes testes travam não é o CRUD: é a fronteira.
 *
 *  - onde a faixa APARECE: seguidores e gorjetas, ao lado do FanAlias;
 *  - onde ela NÃO aparece, e nunca pode passar a aparecer: o catálogo (público
 *    e autenticado), qualquer serialização genérica do User, e filtro nenhum;
 *  - o que "não declarou" produz: ausência, nunca placeholder — "não informou"
 *    ao lado do apelido diria à performer que aquele membro viu o formulário e
 *    recusou, que é a mesma informação que o k-anonimato do painel de
 *    visitantes existe para não dar (item 14 do CLAUDE.md);
 *  - que o campo não entra por mass assignment (fora do $fillable, como o
 *    `discrete_mode`) e que o Hard Delete o leva junto.
 *
 * Helpers locais com prefixo lt*.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function ltMember(?string $tier = null): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);

    if ($tier !== null) {
        ltSetTier($member, $tier);
    }

    return $member->fresh();
}

/**
 * Grava a faixa direto na coluna.
 *
 * `forceFill` e não `update`: a coluna está FORA do $fillable de propósito, e
 * um helper de teste que usasse update() passaria silenciosamente sem gravar
 * nada — deixando todo teste de exibição verde por não ter faixa nenhuma para
 * exibir. O caminho de verdade da aplicação é o endpoint dedicado, exercitado
 * nos testes de escrita mais abaixo.
 */
function ltSetTier(User $member, string $tier): void
{
    $member->forceFill(['lifestyle_tier' => $tier])->save();
}

function ltPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

/**
 * Segue o perfil. Os seguidores contam para o Piso de Anonimato — abaixo dele
 * a lista sai vazia e um teste de exibição passaria sem exibir nada.
 */
function ltFollow(PerformerProfile $profile, User $member): void
{
    Follow::create([
        'user_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => false,
    ]);
}

/** Performer com o piso já destravado por N seguidores elegíveis. */
function ltPerformerWithFollowers(int $count = 5): array
{
    $profile = ltPerformer();
    $members = collect(range(1, $count))->map(function () use ($profile) {
        $member = ltMember();
        ltFollow($profile, $member);

        return $member;
    })->all();

    return [$profile, $members];
}

/** Linha de seguidor da tela, pelo handle do membro. */
function ltFollowerRow(array $props, PerformerProfile $profile, User $member): ?array
{
    return collect($props['followers']['data'])
        ->firstWhere('member_handle', FanAlias::handle($profile->id, $member->id));
}

// ─── Escrita: o formulário do membro ────────────────────────────────────────

it('renders the lifestyle section with the current choice and the options', function () {
    $member = ltMember('premium');

    $this->actingAs($member)
        ->get(route('consumer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Profile/Edit')
            ->where('profile.lifestyle_tier', 'premium')
            // As opções vêm do servidor: rótulo e descrição são lidos também
            // pelo painel da performer, e duas listas divergiriam.
            ->where('lifestyleOptions.0.value', LifestyleTier::NOT_DISCLOSED)
            ->has('lifestyleOptions', 7)
        );
});

it('defaults the form to prefer_not_to_say when nothing was declared', function () {
    $member = ltMember();

    expect($member->lifestyle_tier)->toBeNull();

    $this->actingAs($member)
        ->get(route('consumer.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.lifestyle_tier', LifestyleTier::NOT_DISCLOSED)
        );
});

it('persists the tier the member picks', function () {
    $member = ltMember();

    $this->actingAs($member)
        ->patch(route('consumer.profile.lifestyle-tier'), ['lifestyle_tier' => 'luxo'])
        ->assertRedirect();

    expect($member->fresh()->lifestyle_tier)->toBe('luxo');
});

it('stores prefer_not_to_say as null — one representation for "not disclosed"', function () {
    $member = ltMember('elite');

    $this->actingAs($member)
        ->patch(route('consumer.profile.lifestyle-tier'), [
            'lifestyle_tier' => LifestyleTier::NOT_DISCLOSED,
        ])
        ->assertRedirect();

    // Precedente do `seeking` no ProfileController: duas representações do
    // mesmo estado fariam todo consumidor futuro tratar as duas, e a esquecida
    // vazaria "Prefiro não dizer" para a performer como se fosse faixa.
    expect(DB::table('users')->where('id', $member->id)->value('lifestyle_tier'))->toBeNull();
});

it('rejects a tier outside the scale', function () {
    $member = ltMember();

    $this->actingAs($member)
        ->patch(route('consumer.profile.lifestyle-tier'), ['lifestyle_tier' => 'bilionario'])
        ->assertSessionHasErrors('lifestyle_tier');

    expect($member->fresh()->lifestyle_tier)->toBeNull();
});

/** Último evento de faixa do membro no audit. */
function ltAuditLog(User $member): ?object
{
    return DB::table('audit_logs')
        ->where('user_id', $member->id)
        ->where('action', 'member_lifestyle_tier_updated')
        ->latest('id')
        ->first();
}

it('records the change in the audit log without the value', function () {
    $member = ltMember();

    $this->actingAs($member)
        ->patch(route('consumer.profile.lifestyle-tier'), ['lifestyle_tier' => 'patrono']);

    $log = ltAuditLog($member);

    // Só o booleano. `audit_logs` é a única tabela que o DeletionService
    // preserva INTACTA (§ 3 do cabeçalho dele), com o IP em claro ao lado —
    // gravar o slug ali faria o scrub do Hard Delete ser cosmético, e uma linha
    // por alteração seria a trajetória patrimonial declarada do membro.
    expect($log)->not->toBeNull()
        ->and(json_decode($log->metadata, true))->toBe(['disclosed' => true]);
});

it('never writes the tier slug into the audit log — not even on opt-out', function () {
    $member = ltMember();

    foreach (['elite', LifestyleTier::NOT_DISCLOSED, 'essencial'] as $tier) {
        $this->actingAs($member)
            ->patch(route('consumer.profile.lifestyle-tier'), ['lifestyle_tier' => $tier]);
    }

    // Toda a trilha do membro, não só o último evento: o furo que isto trava é
    // o histórico acumulado, que sobrevive ao Hard Delete.
    $trail = DB::table('audit_logs')->where('user_id', $member->id)->pluck('metadata')->implode(' ');

    foreach (['elite', 'essencial', 'patrono', 'luxo', 'premium', 'confortavel'] as $slug) {
        expect($trail)->not->toContain($slug);
    }

    expect(json_decode(ltAuditLog($member)->metadata, true))->toBe(['disclosed' => true]);
});

it('the column enum matches the scale', function () {
    // A migration guarda a lista LITERAL de propósito (snapshot, não referência
    // viva ao código). Esta é a trava da divergência: um slug novo em
    // LifestyleTier sem migration de acompanhamento quebra AQUI, e não no
    // INSERT em produção — que é o que aconteceria se a migration lesse a
    // constante, porque `migrate:fresh` da suíte criaria a coluna já correta.
    $type = DB::selectOne(
        'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['users', 'lifestyle_tier']
    )->t;

    preg_match_all("/'([^']+)'/", $type, $matches);

    expect($matches[1])->toBe(LifestyleTier::storableValues());
});

// ─── Mass assignment e serialização ─────────────────────────────────────────

it('does not accept the tier through mass assignment', function () {
    $member = ltMember();

    // Mesma disciplina do `discrete_mode` e do 2FA: a coluna muda a exposição
    // do membro a terceiro, então nunca entra por payload genérico. O único
    // caminho é o endpoint dedicado, que faz forceFill com valor da allowlist.
    $member->update(['lifestyle_tier' => 'elite']);
    $member->fill(['lifestyle_tier' => 'elite'])->save();

    expect($member->fresh()->lifestyle_tier)->toBeNull();
});

it('keeps the tier out of a generic serialization', function () {
    $member = ltMember('luxo');

    expect($member->getHidden())->toContain('lifestyle_tier')
        ->and($member->toArray())->not->toHaveKey('lifestyle_tier')
        ->and($member->toJson())->not->toContain('luxo');
});

// ─── Exibição para a performer ──────────────────────────────────────────────

it('shows the tier next to the FanAlias in the followers list', function () {
    [$profile, $members] = ltPerformerWithFollowers();
    ltSetTier($members[0], 'premium');

    $response = $this->actingAs($profile->user)->get(route('performer.followers'));
    $props = $response->assertOk()->viewData('page')['props'];

    $row = ltFollowerRow($props, $profile, $members[0]);

    expect($row['lifestyle'])->toBe('Premium')
        // O apelido continua sendo o do par (perfil, membro) — a faixa é
        // acréscimo à linha, nunca substituto do pseudônimo.
        ->and($row['label'])->toBe(FanAlias::label($profile->id, $members[0]->id, 'Membro #'));
});

it('shows nothing for a member who did not declare', function () {
    [$profile, $members] = ltPerformerWithFollowers();
    ltSetTier($members[0], 'elite');
    // members[1] nunca declarou.

    $response = $this->actingAs($profile->user)->get(route('performer.followers'));
    $props = $response->assertOk()->viewData('page')['props'];

    // `null`, e não "Prefiro não dizer" nem "Não informou": um placeholder
    // devolveria à performer o fato de que aquele membro viu o formulário e
    // recusou — informação sobre a pessoa, que é o que o campo opcional existe
    // para não entregar.
    expect(ltFollowerRow($props, $profile, $members[1])['lifestyle'])->toBeNull();
});

it('shows nothing for a member who explicitly picked prefer_not_to_say', function () {
    [$profile, $members] = ltPerformerWithFollowers();
    ltSetTier($members[0], 'patrono');

    $this->actingAs($members[0])->patch(route('consumer.profile.lifestyle-tier'), [
        'lifestyle_tier' => LifestyleTier::NOT_DISCLOSED,
    ]);

    $response = $this->actingAs($profile->user)->get(route('performer.followers'));
    $props = $response->assertOk()->viewData('page')['props'];

    expect(ltFollowerRow($props, $profile, $members[0])['lifestyle'])->toBeNull()
        // E o rótulo não vaza por outro caminho na mesma resposta.
        ->and($response->getContent())->not->toContain('Patrono');
});

it('shows the tier in the tips panel', function () {
    $profile = ltPerformer();
    $member = ltMember('confortavel');

    app(TokenService::class)->credit($member, 200, 'purchase');
    app(TipService::class)->send($member, $profile, 100, (string) Str::uuid());

    $this->actingAs($profile->user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tips.0.fan', FanAlias::label($profile->id, $member->id))
            ->where('tips.0.lifestyle', 'Confortável')
        );
});

it('never shows the tier in the visitors panel', function () {
    // O painel exige os DOIS pisos (seguidores e visitantes distintos
    // elegíveis), então são 5 seguidores E 5 visitantes.
    [$profile, $followers] = ltPerformerWithFollowers();

    foreach ($followers as $i => $member) {
        if ($i === 0) {
            ltSetTier($member, 'elite');
        }
        $this->actingAs($member)->get(route('catalog.show', $profile->slug))->assertOk();
    }

    $panel = app(ProfileVisitService::class)->panelFor($profile);

    // Decidido pelo PO depois da revisão de segurança de 30/07. O SLOT_MIN_K
    // deste painel dá k-anonimato de PERTENCIMENTO, não l-diversidade de
    // atributo: como o padrão do campo é não declarar, os aliases que a
    // performer planta (ataque A2) ficam sem faixa a custo zero, e aí toda
    // linha ROTULADA é por construção um visitante real — o conjunto de
    // anonimato dentro da faixa cai de 3 para 1. É também a tela com o vínculo
    // mais fraco: o membro só ABRIU um perfil.
    expect($panel['visible'])->toBeTrue()
        ->and($panel['visitors'])->not->toBeEmpty();

    foreach ($panel['visitors'] as $row) {
        expect($row)->not->toHaveKey('lifestyle');
    }

    // E nem pela tela: a prop do Inertia é montada pelo mesmo service.
    $props = $this->actingAs($profile->user)
        ->get(route('performer.dashboard'))->viewData('page')['props'];

    expect(json_encode($props['visitors']))->not->toContain('Elite');
});

it('suppresses the tier for a member in Modo Discreto', function () {
    $profile = ltPerformer();
    $member = ltMember('patrono');

    // Modo Discreto tira o membro da lista de SEGUIDORES, mas a de gorjetas não
    // passa por piso nenhum — então ele reaparece ali. Sem esta supressão,
    // reapareceria carregando o atributo GLOBAL que correlaciona entre perfis,
    // que é exatamente o que o perk existe para negar. A regra vive na dona
    // única (LifestyleTier::labelsFor), não nos controllers: são duas telas
    // hoje, e a terceira nasceria vazando.
    $member->forceFill(['discrete_mode' => true])->save();

    app(TokenService::class)->credit($member, 200, 'purchase');
    app(TipService::class)->send($member, $profile, 100, (string) Str::uuid());

    $this->actingAs($profile->user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // O apelido continua — a gorjeta é ação deliberada dele e essa
            // decisão é antiga. O que sai é só a faixa.
            ->where('tips.0.fan', FanAlias::label($profile->id, $member->id))
            ->where('tips.0.lifestyle', null)
        );
});

// ─── Onde a faixa NUNCA aparece ─────────────────────────────────────────────

/**
 * As props do Inertia, sem a tabela de rotas do Ziggy.
 *
 * O `@routes` do layout imprime os NOMES das rotas no HTML, e um deles é
 * `consumer.profile.lifestyle-tier` — procurar "lifestyle" no corpo cru daria
 * falso positivo eterno. O que interessa é o payload da página: é dele que a
 * tela lê, e é por ele que um dado vaza.
 */
function ltPageProps(TestResponse $response): string
{
    $props = $response->assertOk()->viewData('page')['props'];
    unset($props['ziggy']);

    return json_encode($props, JSON_UNESCAPED_UNICODE);
}

it('never leaks the tier into the public catalog', function () {
    $profile = ltPerformer();
    $member = ltMember('patrono');
    ltFollow($profile, $member);

    foreach ([route('performers.public'), route('performers.public.show', $profile->slug)] as $url) {
        $response = $this->get($url);

        expect(ltPageProps($response))->not->toContain('lifestyle')
            // O rótulo renderizado, não só o slug: a superfície pública não tem
            // nem o piso de anonimato para segurar a correlação entre perfis.
            ->and($response->getContent())->not->toContain('Patrono');
    }
});

it('never leaks the tier into the authenticated catalog', function () {
    $profile = ltPerformer();
    $member = ltMember('patrono');
    ltFollow($profile, $member);

    // O membro vendo o catálogo: a faixa é dele, mas o catálogo é a superfície
    // errada — é ali que a performer também navega.
    foreach ([route('catalog'), route('catalog.show', $profile->slug)] as $url) {
        $response = $this->actingAs($member)->get($url);

        expect(ltPageProps($response))->not->toContain('lifestyle')
            ->and($response->getContent())->not->toContain('Patrono');
    }
});

it('is not a catalog filter', function () {
    $profile = ltPerformer();
    $member = ltMember('patrono');
    $other = ltMember('essencial');

    // Filtrar por faixa transformaria o campo em CONSULTA ("me mostre os
    // Patronos"), e consulta que devolve conjunto pequeno identifica muito mais
    // do que um rótulo ao lado de um apelido. O parâmetro tem que ser inerte.
    $unfiltered = $this->actingAs($member)->get(route('catalog'))
        ->assertOk()->viewData('page')['props'];

    $attempted = $this->actingAs($member)->get(route('catalog', ['lifestyle_tier' => 'patrono']))
        ->assertOk()->viewData('page')['props'];

    expect(collect($attempted['performers']['data'])->pluck('slug')->all())
        ->toBe(collect($unfiltered['performers']['data'])->pluck('slug')->all())
        ->and($attempted['filters'])->not->toHaveKey('lifestyle_tier')
        ->and($other->fresh()->lifestyle_tier)->toBe('essencial');
});

// ─── Hard Delete ────────────────────────────────────────────────────────────

it('clears the tier on hard delete', function () {
    $member = ltMember();

    $this->actingAs($member)
        ->patch(route('consumer.profile.lifestyle-tier'), ['lifestyle_tier' => 'luxo']);

    app(DeletionService::class)->executeDeletion($member->fresh());

    // Auto-declaração patrimonial sem lastro fiscal nem legal — e a única
    // daquela tela que terceiro já viu. Sai junto com `seeking` e os perks.
    expect(DB::table('users')->where('id', $member->id)->value('lifestyle_tier'))->toBeNull()
        // E o scrub não pode ser cosmético: a faixa passou pelo endpoint real,
        // então se o audit gravasse o slug ele sobreviveria aqui — `audit_logs`
        // é preservado intacto, com o IP do titular ao lado.
        ->and(DB::table('audit_logs')->where('user_id', $member->id)->pluck('metadata')->implode(' '))
        ->not->toContain('luxo');
});
