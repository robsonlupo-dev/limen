<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Support\ActivitySlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * "Última atividade" da performer em FAIXA (Sprint 10).
 *
 * A feature tem duas metades que os testes existem para travar separadamente:
 *
 *  - a ESCRITA (TrackPerformerActivity): carimba `last_active_at` a cada request
 *    da performer, com throttle de 5 min, e NÃO carimba sob Ghost Mode / Status
 *    Invisível;
 *  - a EXIBIÇÃO (ActivitySlot + PerformerPublicResource): o público vê a faixa,
 *    NUNCA o timestamp — que é `$hidden` e não sai em porta nenhuma.
 *
 * O vazamento do timestamp não quebraria nenhum teste de funcionalidade — a
 * faixa continuaria "funcionando" —, então tem asserção própria, como a cidade
 * no PerformerLocationTest.
 *
 * Helpers locais (prefixo la*) para o arquivo ser autossuficiente.
 */

function laPerformer(string $stageName = 'Ana', array $userAttributes = [], array $profileAttributes = []): PerformerProfile
{
    $user = User::factory()->create(array_merge([
        'role' => 'performer',
        'status' => 'active',
    ], $userAttributes));

    return $user->performerProfile()->create(array_merge([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        // publicCatalog() exige verificada + slug, senão o perfil não entra em
        // listagem nenhuma e as portas de exibição não teriam o que servir.
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileAttributes));
}

function laMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/** As linhas do catálogo AUTENTICADO. */
function laAuthRows(): array
{
    return test()->actingAs(laMember())->get(route('catalog'))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas do catálogo PÚBLICO. */
function laPublicRows(): array
{
    return test()->get(route('performers.public'))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas da API v1. */
function laApiRows(): array
{
    return test()->getJson(route('performers.index'))->assertOk()->json('data');
}

// ─── ESCRITA: o middleware carimba ──────────────────────────────────────────
// Os writes exercitam pela porta PÚBLICA (`performers.public`), não pela
// `catalog`: o TrackPerformerActivity roda no grupo `web` INTEIRO (carimba em
// qualquer request da performer), e desde o fix do UAT o `/catalogo` é
// `role:consumer` — performer/admin levam 403 lá. A porta pública prova o mesmo
// middleware sem depender de quem pode ver a listagem.

it('carimba last_active_at no request autenticado da performer', function () {
    $profile = laPerformer();

    expect($profile->user->last_active_at)->toBeNull();

    test()->actingAs($profile->user)->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->last_active_at)->not->toBeNull();
});

it('nao carimba para membro nem para admin', function () {
    // "Última atividade" é superfície de vitrine, e só a performer é vitrine.
    $member = laMember();
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    foreach ([$member, $admin] as $user) {
        test()->actingAs($user)->get(route('performers.public'))->assertOk();
        expect($user->refresh()->last_active_at)->toBeNull();
    }
});

it('nao carimba para visitante deslogado', function () {
    // Sem user não há o que carimbar; a porta pública existe sem sessão.
    laPerformer();

    test()->get(route('performers.public'))->assertOk();

    expect(User::whereNotNull('last_active_at')->count())->toBe(0);
});

// ─── ESCRITA: throttle ──────────────────────────────────────────────────────

it('nao regrava dentro da janela de throttle de 5 min', function () {
    $profile = laPerformer();

    Carbon::setTestNow('2026-07-31 12:00:00');
    test()->actingAs($profile->user)->get(route('performers.public'))->assertOk();
    $first = $profile->user->refresh()->last_active_at;
    expect($first)->not->toBeNull();

    // Segundo request 1s depois: dentro dos 5 min, não escreve. O carimbo
    // continua sendo o do PRIMEIRO — é o teste "2 requests em 1s = 1 update".
    Carbon::setTestNow('2026-07-31 12:00:01');
    test()->actingAs($profile->user)->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->last_active_at->equalTo($first))->toBeTrue();

    Carbon::setTestNow();
});

it('regrava depois que a janela de throttle passa', function () {
    $profile = laPerformer();

    Carbon::setTestNow('2026-07-31 12:00:00');
    test()->actingAs($profile->user)->get(route('performers.public'))->assertOk();
    $first = $profile->user->refresh()->last_active_at;

    // 6 min depois: fora da janela, escreve de novo.
    Carbon::setTestNow('2026-07-31 12:06:00');
    test()->actingAs($profile->user)->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->last_active_at->greaterThan($first))->toBeTrue();

    Carbon::setTestNow();
});

// ─── ESCRITA: perks suprimem o carimbo ──────────────────────────────────────

it('nao carimba quando ghost mode esta ligado', function () {
    // ghost_mode/invisible_status não são fillable — set explícito, como o
    // PrivacyPerkService faz. A supressão vive na ESCRITA: o timestamp não pode
    // sequer existir no banco.
    $profile = laPerformer();
    $profile->user->forceFill(['ghost_mode' => true])->save();

    test()->actingAs($profile->user->fresh())->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->last_active_at)->toBeNull();
});

it('nao carimba quando o status invisivel esta ligado', function () {
    $profile = laPerformer();
    $profile->user->forceFill(['invisible_status' => true])->save();

    test()->actingAs($profile->user->fresh())->get(route('performers.public'))->assertOk();

    expect($profile->user->refresh()->last_active_at)->toBeNull();
});

// ─── EXIBIÇÃO: a faixa (ActivitySlot) ───────────────────────────────────────

it('deriva a faixa correta para cada intervalo', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');

    expect(ActivitySlot::for(null))->toBeNull()
        ->and(ActivitySlot::for(now()))->toBe(ActivitySlot::TODAY)
        ->and(ActivitySlot::for(now()->subHours(2)))->toBe(ActivitySlot::TODAY)
        ->and(ActivitySlot::for(now()->subHours(23)))->toBe(ActivitySlot::TODAY)
        ->and(ActivitySlot::for(now()->subHours(25)))->toBe(ActivitySlot::THIS_WEEK)
        ->and(ActivitySlot::for(now()->subDays(6)))->toBe(ActivitySlot::THIS_WEEK)
        ->and(ActivitySlot::for(now()->subDays(8)))->toBe(ActivitySlot::THIS_MONTH)
        ->and(ActivitySlot::for(now()->subDays(29)))->toBe(ActivitySlot::THIS_MONTH)
        ->and(ActivitySlot::for(now()->subDays(31)))->toBeNull();

    Carbon::setTestNow();
});

it('entrega activity_label como faixa nas tres portas de listagem', function () {
    laPerformer('Ana', profileAttributes: [])->user->forceFill(['last_active_at' => now()->subHours(2)])->save();

    foreach ([laAuthRows(), laPublicRows(), laApiRows()] as $rows) {
        expect($rows[0]['activity_label'])->toBe(ActivitySlot::TODAY);
    }
});

it('entrega activity_label null para performer sem atividade', function () {
    laPerformer();

    // A chave existe e vem null — é o que faz o `v-if` da tela esconder a linha.
    foreach ([laAuthRows(), laPublicRows(), laApiRows()] as $rows) {
        expect($rows[0])->toHaveKey('activity_label')
            ->and($rows[0]['activity_label'])->toBeNull();
    }
});

it('mostra a faixa nas duas telas de perfil, ao lado do estado', function () {
    $profile = laPerformer('Ana', profileAttributes: ['state' => 'RJ']);
    $profile->user->forceFill(['last_active_at' => now()->subHours(2)])->save();

    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(laMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.activity_label', ActivitySlot::TODAY)
            ->where('performer.state', 'RJ')
        );
    }
});

// ─── EXIBIÇÃO: is_live tem só a bolinha, sem o texto ────────────────────────

it('entrega is_live e a faixa juntos para a tela ocultar o texto', function () {
    // Live + ativa hoje: o resource manda os dois; a tela mostra só a bolinha
    // (`v-if="activity_label && !is_live"`). Não há runner de JS no repo, mas o
    // pé server-side trava a regressão: se `is_live` sumisse do resource, o texto
    // voltaria a aparecer durante a live, em silêncio. Mesma disciplina do
    // PerformerLocationTest com o estado.
    $profile = laPerformer('Ana', profileAttributes: ['is_live' => true]);
    $profile->user->forceFill(['last_active_at' => now()->subHours(2)])->save();

    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(laMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.is_live', true)
            ->where('performer.activity_label', ActivitySlot::TODAY)
        );
    }
});

// ─── PRIVACIDADE: o timestamp nunca sai ─────────────────────────────────────

it('nunca expoe last_active_at como timestamp em nenhuma porta', function () {
    // Instante distintivo e FIXO no passado, escolhido para NÃO colidir com o
    // created_at das fixtures (que nascem no `now()` real): se congelássemos o
    // relógio aqui, o mesmo H:i:s carimbaria também as fixtures e o substring
    // acusaria um vazamento que não existe.
    $profile = laPerformer('Ana', profileAttributes: ['state' => 'SP']);
    $profile->user->forceFill(['last_active_at' => Carbon::parse('2025-03-14 09:26:53')])->save();

    // 1. Listagens: a chave crua não existe no resource, nas três portas — só a
    //    faixa derivada (aqui null, pois o carimbo é antigo; a presença da faixa
    //    é coberta em outro teste).
    foreach ([laAuthRows(), laPublicRows(), laApiRows()] as $rows) {
        expect($rows[0])->not->toHaveKey('last_active_at')
            ->and($rows[0])->toHaveKey('activity_label');
    }

    // 2. Corpo inteiro das seis portas: nem a chave, nem o instante formatado. O
    //    substring fecha também o caso de uma prop solta acrescentada ao lado.
    $needle = '09:26:53';
    $responses = [
        test()->get(route('performers.public.show', $profile->slug))->assertOk(),
        test()->actingAs(laMember())->get(route('catalog.show', $profile->slug))->assertOk(),
        test()->getJson(route('performers.show', $profile->slug))->assertOk(),
        test()->actingAs(laMember())->get(route('catalog'))->assertOk(),
        test()->get(route('performers.public'))->assertOk(),
        test()->getJson(route('performers.index'))->assertOk(),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('last_active_at')
            ->and($response->getContent())->not->toContain($needle);
    }
});

// ─── Hard Delete ────────────────────────────────────────────────────────────

it('limpa last_active_at no hard delete', function () {
    $profile = laPerformer();
    $profile->user->forceFill(['last_active_at' => now()])->save();

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    $scrubbed = User::withTrashed()->find($profile->user->id);

    expect($scrubbed->last_active_at)->toBeNull();
});
