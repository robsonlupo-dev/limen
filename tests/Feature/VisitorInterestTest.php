<?php

use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\ProfileVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InterestService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Interesse Controlado a partir do PAINEL DE VISITANTES (Sprint 9).
 *
 * A segunda porta de envio. A primeira (lista de seguidores) é coberta por
 * InterestSystemTest; o que muda aqui é só QUEM pode ser alvo e QUAL cota é
 * debitada — o membro recebe exatamente o mesmo sinal cego, ao mesmo custo de
 * revelação.
 *
 * A invariante que estes testes existem para travar é a do CLAUDE.md: **a tela
 * e o envio têm que concordar.** Tudo o que esconde alguém do painel (os dois
 * pisos, o k-anonimato por faixa, Ghost Mode, Modo Discreto) tem que impedir
 * igualmente que ele seja alvo — senão o par 404/201 do envio vira oráculo para
 * reconstruir a lista que a tela esconde.
 *
 * Helpers locais (prefixo vi*) para o arquivo ser autossuficiente.
 */

/**
 * Congela o relógio num instante do fuso de EXIBIÇÃO.
 *
 * Não é conforto de teste: o painel agrupa por faixa de 6h e uma faixa só
 * aparece com SLOT_MIN_K aliases. Sem congelar, uma suíte que rodasse em cima
 * de uma virada de faixa espalharia os visitantes por duas faixas, nenhuma
 * fecharia o k, e o teste falharia pelo motivo errado.
 */
function viFreeze(string $when = '2026-07-21 15:00'): void
{
    test()->travelTo(Carbon::parse($when, ProfileVisitService::DISPLAY_TIMEZONE));
}

/** Membro que CONTA para os pisos: conta com 7+ dias e e-mail verificado. */
function viMember(?string $circleSlug = null): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);

    if ($circleSlug !== null) {
        Subscription::factory()->circle($circleSlug)->create([
            'user_id' => $member->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);
    }

    return $member->fresh();
}

function viPerformer(): PerformerProfile
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

/** @return array<int,User> */
function viFollowers(PerformerProfile $profile, int $count): array
{
    return collect(range(1, $count))->map(function () use ($profile) {
        $member = viMember();
        Follow::create([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => false,
        ]);

        return $member;
    })->all();
}

function viVisit(PerformerProfile $profile, User $member): void
{
    test()->actingAs($member)->get(route('catalog.show', $profile->slug))->assertOk();
}

/** @return array<int,User> */
function viVisitors(PerformerProfile $profile, int $count): array
{
    return collect(range(1, $count))->map(function () use ($profile) {
        $member = viMember();
        viVisit($profile, $member);

        return $member;
    })->all();
}

function viFloor(): int
{
    return (int) config('interest.anonymity_floor');
}

/**
 * Painel DESTRAVADO: os dois pisos satisfeitos e uma faixa com k+ aliases.
 *
 * São 5 seguidores E 5 visitantes distintos porque os cortes são dois e
 * independentes — o de seguidores libera a tela, o de visitantes limita quem
 * aparece nela. Um só não abre o painel.
 *
 * @return array{0:PerformerProfile,1:array<int,User>}
 */
function viUnlockedPanel(int $visitors = 5): array
{
    viFreeze();
    $profile = viPerformer();
    viFollowers($profile, viFloor());

    return [$profile, viVisitors($profile, $visitors)];
}

/** POST na porta do painel. O alvo vai como handle opaco, nunca como id. */
function viSend(PerformerProfile $profile, User $member)
{
    return test()->actingAs($profile->user)->postJson(
        route('performer.interests.send-visitor'),
        ['member_handle' => FanAlias::handle($profile->id, $member->id)],
    );
}

// ─── Caminho feliz ──────────────────────────────────────────────────────────

it('envia interesse para um visitante do painel e o membro recebe o sinal', function () {
    [$profile, $visitors] = viUnlockedPanel();
    $target = $visitors[0];

    viSend($profile, $target)->assertCreated()->assertJson(['sent' => true]);

    $interest = PerformerInterest::sole();
    expect($interest->member_id)->toBe($target->id)
        ->and($interest->source)->toBe(InterestService::SOURCE_VISITOR)
        ->and($interest->status)->toBe('sent');

    // Para o membro nada muda em relação à outra porta: o sinal é o mesmo cego,
    // e revelar quem é continua custando os 15 tokens.
    $this->actingAs($target)
        ->get(route('interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Interests/Index')
            ->has('interests.data', 1)
            ->where('interests.data.0.status', 'sent')
            ->where('interests.data.0.performer', null)
        );
});

it('nao conta a origem do interesse para o membro', function () {
    [$profile, $visitors] = viUnlockedPanel();
    $target = $visitors[0];

    viSend($profile, $target)->assertCreated();

    // Saber que o interesse nasceu de uma VISITA diria ao membro que suas
    // visitas são visíveis para a performer. A origem serve à cota e à
    // auditoria; não é informação do membro.
    $this->actingAs($target)
        ->get(route('interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('interests.data.0.source'));
});

// ─── Painel travado: o envio tem que concordar com a tela ───────────────────

it('recusa o envio quando o piso de SEGUIDORES mantem o painel travado', function () {
    viFreeze();
    $profile = viPerformer();
    viFollowers($profile, viFloor() - 1); // um a menos: canRevealList() é false
    $visitors = viVisitors($profile, 5);

    viSend($profile, $visitors[0])->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('recusa o envio quando o piso de VISITANTES mantem o painel travado', function () {
    viFreeze();
    $profile = viPerformer();
    viFollowers($profile, viFloor());
    // 4 visitantes: o k da faixa (3) está satisfeito, então o que trava é o
    // piso de visitantes — os dois cortes são mesmo independentes.
    $visitors = viVisitors($profile, viFloor() - 1);

    viSend($profile, $visitors[0])->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('recusa o envio de performer nao verificada', function () {
    [$profile, $visitors] = viUnlockedPanel();

    // Verificada até aqui (senão as visitas nem seriam possíveis pelo catálogo);
    // perde a verificação depois de o painel estar montado.
    $profile->update(['is_verified' => false]);

    viSend($profile->fresh(), $visitors[0])->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('recusa o envio de um visitante que o k-anonimato removeu da faixa', function () {
    viFreeze('2026-07-21 09:00');
    $profile = viPerformer();
    viFollowers($profile, viFloor());
    $manha = viVisitors($profile, 4); // Manhã: faixa completa (4 >= k)

    viFreeze('2026-07-21 15:00');
    $tarde = viVisitors($profile, 1); // Tarde: faixa incompleta, some inteira

    // 5 visitantes elegíveis no total: os dois pisos estão satisfeitos. O que
    // esconde este alvo é só o k — e esconder da tela tem que esconder do envio.
    viSend($profile, $tarde[0])->assertNotFound();

    // Prova de que o painel está mesmo aberto: o alvo da faixa completa passa.
    viSend($profile, $manha[0])->assertCreated();
});

// ─── Cota diária da origem visitantes ───────────────────────────────────────

it('recusa o quarto envio do dia pela origem visitantes', function () {
    [$profile, $visitors] = viUnlockedPanel();
    $limit = (int) config('interest.visitor_daily_limit');

    foreach (range(0, $limit - 1) as $i) {
        viSend($profile, $visitors[$i])->assertCreated();
    }

    viSend($profile, $visitors[$limit])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'daily_limit');

    expect(PerformerInterest::count())->toBe($limit);
});

it('a cota de visitantes e independente da cota de seguidores', function () {
    viFreeze();
    $profile = viPerformer();
    $followers = viFollowers($profile, viFloor());
    $visitors = viVisitors($profile, 5);

    // Esgota a cota do painel (3).
    foreach (range(0, 2) as $i) {
        viSend($profile, $visitors[$i])->assertCreated();
    }
    viSend($profile, $visitors[3])->assertUnprocessable();

    // A porta de seguidores continua aberta: as cotas são SEPARADAS por decisão
    // do PO, e o teto diário total da performer é a soma (5 + 3), não 5.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), [
            'member_handle' => FanAlias::handle($profile->id, $followers[0]->id),
        ])
        ->assertCreated();

    expect(PerformerInterest::where('source', InterestService::SOURCE_VISITOR)->count())->toBe(3)
        ->and(PerformerInterest::where('source', InterestService::SOURCE_FOLLOWER)->count())->toBe(1);
});

// ─── Handle inválido ────────────────────────────────────────────────────────

it('recusa um handle inventado com a MESMA resposta de um alvo escondido', function () {
    [$profile] = viUnlockedPanel();

    $invented = $this->actingAs($profile->user)->postJson(
        route('performer.interests.send-visitor'),
        ['member_handle' => str_repeat('a', 16)],
    );

    // Membro real e ativo, mas que nunca visitou este perfil. Se a resposta
    // diferisse da de um handle inventado, a performer varreria o espaço de
    // handles e aprenderia quem existe na plataforma.
    $hidden = viSend($profile, viMember());

    expect($invented->status())->toBe(404)
        ->and($hidden->status())->toBe(404)
        ->and($invented->getContent())->toBe($hidden->getContent());

    expect(PerformerInterest::count())->toBe(0);
});

it('recusa o envio sem handle', function () {
    [$profile] = viUnlockedPanel();

    // Redirect com erro de sessão, e NÃO 422 JSON: esta é uma rota web, e o
    // `shouldRenderJsonWhen` de bootstrap/app.php só devolve JSON em `api/*`
    // (regra do CLAUDE.md). As recusas que o front consome — cooldown, cota —
    // são JSON porque o controller as monta com `response()->json()` explícito;
    // a ValidationException não passa por ele.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send-visitor'), [])
        ->assertFound()
        ->assertSessionHasErrors('member_handle');

    expect(PerformerInterest::count())->toBe(0);
});

// ─── Perks de privacidade: quem não gerou linha não é alvo ──────────────────

it('nao aceita como alvo o visitante com Ghost Mode', function () {
    [$profile] = viUnlockedPanel();

    $ghost = viMember('black');
    viVisit($profile, $ghost);

    // A ausência de linha É o produto que o perk vende — não existe visita
    // gravada "como oculta" para o envio filtrar depois.
    expect(ProfileVisit::where('visitor_id', $ghost->id)->count())->toBe(0);

    viSend($profile, $ghost)->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('nao aceita como alvo o visitante em Modo Discreto', function () {
    [$profile] = viUnlockedPanel();

    // Sem tier: a regra 3 do CLAUDE.md diz que perder o tier NÃO desliga o Modo
    // Discreto, então discrete_mode sozinho já tem que bastar aqui.
    $discrete = viMember();
    $discrete->discrete_mode = true;
    $discrete->save();

    viVisit($profile, $discrete->fresh());

    expect(ProfileVisit::where('visitor_id', $discrete->id)->count())->toBe(0);

    viSend($profile, $discrete)->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

// ─── Duplicidade entre as duas portas ───────────────────────────────────────

it('aplica o cooldown a quem ja recebeu interesse pela lista de seguidores', function () {
    viFreeze();
    $profile = viPerformer();
    $followers = viFollowers($profile, viFloor());
    $both = $followers[0];

    // O mesmo membro é seguidor E visitante — é o caso em que as duas portas
    // se encontram. Ele fecha o piso de visitantes junto com os outros 4.
    viVisitors($profile, 4);
    viVisit($profile, $both);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), [
            'member_handle' => FanAlias::handle($profile->id, $both->id),
        ])
        ->assertCreated();

    // O cooldown é POR PAR (performer, membro) e comum às duas origens. Se cada
    // porta tivesse o seu, a performer dobraria as cutucadas só trocando de tela
    // — que é exatamente o que o cooldown existe para impedir.
    viSend($profile, $both)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'cooldown');

    expect(PerformerInterest::count())->toBe(1);
});

it('aplica o cooldown a quem ja recebeu interesse pelo painel de visitantes', function () {
    viFreeze();
    $profile = viPerformer();
    $followers = viFollowers($profile, viFloor());
    $both = $followers[0];

    viVisitors($profile, 4);
    viVisit($profile, $both);

    viSend($profile, $both)->assertCreated();

    // Direção inversa da anterior: a porta nova também tem que TRANCAR a antiga.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), [
            'member_handle' => FanAlias::handle($profile->id, $both->id),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'cooldown');

    expect(PerformerInterest::count())->toBe(1);
});

// ─── Props do painel ────────────────────────────────────────────────────────

it('entrega o handle do visitante no painel, e nunca o id', function () {
    [$profile, $visitors] = viUnlockedPanel();

    $response = $this->actingAs($profile->user)->get(route('performer.dashboard'));
    $panel = $response->viewData('page')['props']['visitors'];

    // A garantia é a FORMA: nenhuma chave além destas três. O `fan` de 4 dígitos
    // é exibição e colide de propósito; quem identifica é o `member_handle`.
    expect(array_keys($panel[0]))->toBe(['fan', 'member_handle', 'visited_slot'])
        ->and(collect($panel)->pluck('member_handle'))
        ->toContain(FanAlias::handle($profile->id, $visitors[0]->id));
});
