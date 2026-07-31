<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * "Disponível para conversa" (Sprint 11) — o Caminho 3 do Interesse Expandido:
 * a performer sinaliza que quer ser abordada agora.
 *
 * A feature tem três metades que os testes travam separadamente:
 *
 *  - o TOGGLE (PATCH /performer/disponibilidade): liga/desliga, idempotente, e
 *    escreve `available_for_chat_at` só por forceFill (fora do $fillable);
 *  - a DERIVAÇÃO (PerformerProfile::isAvailableForChat): o estado vence na
 *    LEITURA, na janela de 4h, sem job — como o is_live e o ChatAccess;
 *  - a EXIBIÇÃO (PerformerPublicResource + cards): o público vê só o booleano
 *    `is_available`, NUNCA o carimbo, e o badge não convive com is_live nem com
 *    o estado (R2 — presença em tempo real + localização).
 *
 * O vazamento do timestamp não quebraria teste de funcionalidade nenhum — o
 * badge continuaria "funcionando" —, então tem asserção própria, como a cidade
 * no PerformerLocationTest e o last_active_at no PerformerLastActiveTest.
 *
 * Helpers locais (prefixo av*) para o arquivo ser autossuficiente.
 */

function avPerformer(string $stageName = 'Ana', array $userAttributes = [], array $profileAttributes = []): PerformerProfile
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

function avMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/** Marca a performer disponível AGORA (carimbo direto, como o controller). */
function avMarkAvailable(PerformerProfile $profile, ?Carbon $at = null): PerformerProfile
{
    $profile->forceFill(['available_for_chat_at' => $at ?? now()])->save();

    return $profile;
}

/** As linhas do catálogo AUTENTICADO. */
function avAuthRows(array $query = []): array
{
    return test()->actingAs(avMember())->get(route('catalog', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas do catálogo PÚBLICO. */
function avPublicRows(array $query = []): array
{
    return test()->get(route('performers.public', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas da API v1. */
function avApiRows(array $query = []): array
{
    return test()->getJson(route('performers.index', $query))->assertOk()->json('data');
}

/** Nomes artísticos ordenados, para as asserções de filtro. */
function avNames(array $rows): array
{
    return collect($rows)->pluck('stage_name')->sort()->values()->all();
}

// ─── TOGGLE: liga / desliga ──────────────────────────────────────────────────

it('liga a disponibilidade pelo toggle', function () {
    $profile = avPerformer();

    expect($profile->available_for_chat_at)->toBeNull();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['available' => true])
        ->assertOk()
        ->assertJson(['is_available' => true]);

    expect($profile->refresh()->available_for_chat_at)->not->toBeNull()
        ->and($profile->isAvailableForChat())->toBeTrue();
});

it('desliga a disponibilidade pelo toggle', function () {
    $profile = avMarkAvailable(avPerformer());

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['available' => false])
        ->assertOk()
        ->assertJson(['is_available' => false, 'remaining_label' => null]);

    expect($profile->refresh()->available_for_chat_at)->toBeNull();
});

it('inverte o estado quando o toggle vem sem o campo', function () {
    // Sem `available` no corpo o servidor inverte — é o que torna duplo clique /
    // retry inofensivo, mesmo padrão do ToggleDiscreteModeRequest.
    $profile = avPerformer();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), [])
        ->assertOk()
        ->assertJson(['is_available' => true]);

    expect($profile->refresh()->isAvailableForChat())->toBeTrue();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), [])
        ->assertOk()
        ->assertJson(['is_available' => false]);

    expect($profile->refresh()->available_for_chat_at)->toBeNull();
});

// ─── TOGGLE: quem pode ───────────────────────────────────────────────────────

it('recusa o toggle para o membro', function () {
    $member = avMember();

    // role:performer aborta 403 — o toggle é da vitrine, e só a performer é
    // vitrine. É a direção segura (performer sinaliza), nunca o membro mexendo
    // no estado dela.
    test()->actingAs($member)
        ->patchJson(route('performer.availability.toggle'), ['available' => true])
        ->assertForbidden();
});

it('recusa o toggle para o visitante deslogado', function () {
    // Rota WEB: o `auth` redireciona o visitante para o login (302), não 401 —
    // a porta JSON (401) é a da API. O que importa é que não passa.
    test()->patchJson(route('performer.availability.toggle'), ['available' => true])
        ->assertRedirect(route('login'));
});

it('recusa um valor nao-booleano com 422 json', function () {
    // Rota WEB consumida por fetch: o erro sai 422 JSON (FailsValidationAsJson),
    // não redirect com erros de sessão.
    $profile = avPerformer();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['available' => 'talvez'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('available');
});

// ─── DERIVAÇÃO: a janela de 4h vence na leitura ──────────────────────────────

it('deriva a disponibilidade na janela de 4 horas', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');

    $profile = avPerformer();

    // Nunca sinalizou.
    expect($profile->isAvailableForChat())->toBeFalse();

    // Agora, e a 3h59 atrás: dentro da janela.
    avMarkAvailable($profile, now());
    expect($profile->refresh()->isAvailableForChat())->toBeTrue();

    avMarkAvailable($profile, now()->subHours(3)->subMinutes(59));
    expect($profile->refresh()->isAvailableForChat())->toBeTrue();

    // 4h01 atrás: fora da janela, mesmo sem job ter rodado — a expiração é da
    // LEITURA.
    avMarkAvailable($profile, now()->subHours(4)->subMinutes(1));
    expect($profile->refresh()->isAvailableForChat())->toBeFalse();

    Carbon::setTestNow();
});

it('expira automaticamente depois de 4 horas no card', function () {
    // O carimbo tem 5h: sem job nenhum, o resource já mostra is_available false.
    $profile = avMarkAvailable(avPerformer(), now()->subHours(5));

    foreach ([avAuthRows(), avPublicRows(), avApiRows()] as $rows) {
        expect($rows[0]['is_available'])->toBeFalse();
    }

    // A coluna continua carimbada — a expiração não a apaga, só deixa de contar.
    expect($profile->refresh()->available_for_chat_at)->not->toBeNull();
});

// ─── EXIBIÇÃO: o badge no card ───────────────────────────────────────────────

it('entrega is_available true no card das tres portas quando ativo', function () {
    avMarkAvailable(avPerformer());

    foreach ([avAuthRows(), avPublicRows(), avApiRows()] as $rows) {
        expect($rows[0]['is_available'])->toBeTrue();
    }
});

it('entrega is_available false para quem nao sinalizou', function () {
    avPerformer();

    // A chave existe e vem false — é o que faz o `v-if` do card não desenhar
    // nada. Opt-in: não sinalizar é estado normal, não pendência a anunciar.
    foreach ([avAuthRows(), avPublicRows(), avApiRows()] as $rows) {
        expect($rows[0])->toHaveKey('is_available')
            ->and($rows[0]['is_available'])->toBeFalse();
    }
});

// ─── EXIBIÇÃO: o badge não convive com is_live (R2 / redundância) ─────────────

it('entrega is_available e is_live juntos para a tela ocultar o badge', function () {
    // Live + disponível: o resource manda os dois; a tela mostra só a bolinha
    // (`v-if="is_available && !is_live"`). Não há runner de JS no repo, mas o pé
    // server-side trava a regressão: se `is_live` sumisse do resource, o badge
    // voltaria a aparecer durante a live, em silêncio. Mesma disciplina do
    // PerformerLastActiveTest com a faixa de atividade.
    $profile = avMarkAvailable(avPerformer('Ana', profileAttributes: ['is_live' => true]));

    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(avMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.is_available', true)
            ->where('performer.is_live', true)
        );
    }
});

// ─── EXIBIÇÃO: o badge não convive com a geolocalização (R2) ─────────────────

it('entrega is_available e o estado juntos para a tela separar os dois', function () {
    // Disponível + com estado declarado: o resource manda os dois, e a tela
    // esconde o ESTADO quando is_available (`v-if="state && !is_live &&
    // !is_available"`). Presença ("quer conversar agora") + localização ("está em
    // SP") é a correlação em tempo real do R2 — a mesma que o `!is_live` fecha.
    // Se `is_available` sumisse do resource, o estado voltaria a aparecer ao lado
    // do badge, em silêncio. Por isso as duas props são asseridas juntas.
    $profile = avMarkAvailable(avPerformer('Ana', profileAttributes: ['state' => 'SP']));

    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(avMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.is_available', true)
            ->where('performer.state', 'SP')
        );
    }

    // E nos cards das três portas os dois sinais chegam para o mesmo `v-if`.
    foreach ([avAuthRows(), avPublicRows(), avApiRows()] as $rows) {
        expect($rows[0]['is_available'])->toBeTrue()
            ->and($rows[0]['state'])->toBe('SP');
    }
});

// ─── FILTRO: "Disponíveis agora" ─────────────────────────────────────────────

it('filtra o catalogo por disponiveis agora nas tres portas', function () {
    avMarkAvailable(avPerformer('Ana'));
    avPerformer('Bia'); // nunca sinalizou
    avMarkAvailable(avPerformer('Cris'), now()->subHours(5)); // sinalizou, mas venceu

    // Só quem está na janela de 4h casa. A venceu-há-5h NÃO entra — a faceta
    // pergunta "disponível AGORA", que é o mesmo corte da leitura.
    expect(avNames(avAuthRows(['available' => 1])))->toBe(['Ana'])
        ->and(avNames(avPublicRows(['available' => 1])))->toBe(['Ana'])
        ->and(avNames(avApiRows(['available' => 1])))->toBe(['Ana']);
});

it('deixa passar todo mundo quando o filtro nao esta marcado', function () {
    avMarkAvailable(avPerformer('Ana'));
    avPerformer('Bia');

    // A faceta é opt-in: sem ela, quem não sinalizou continua no catálogo.
    expect(avNames(avAuthRows()))->toBe(['Ana', 'Bia'])
        ->and(avNames(avPublicRows()))->toBe(['Ana', 'Bia'])
        ->and(avNames(avApiRows()))->toBe(['Ana', 'Bia']);
});

it('recusa um valor de filtro nao-booleano', function () {
    avPerformer('Ana');

    test()->getJson(route('performers.index', ['available' => 'xpto']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('available');
});

// ─── PRIVACIDADE: o carimbo nunca sai ────────────────────────────────────────

it('nunca expoe available_for_chat_at como timestamp em nenhuma porta', function () {
    // Relógio congelado num instante distintivo, e o carimbo 90 min atrás para
    // NÃO colidir com o created_at das fixtures (que nascem no mesmo `now()`
    // congelado): assim o substring acusa vazamento de verdade, não o H:i:s do
    // created_at. 90 min está dentro da janela, então is_available é true.
    Carbon::setTestNow('2026-07-31 12:00:00');

    $profile = avMarkAvailable(avPerformer('Ana', profileAttributes: ['state' => 'SP']), now()->subMinutes(90));
    $needle = '10:30:00';

    // 1. Listagens: a chave crua não existe no resource, só o booleano derivado.
    foreach ([avAuthRows(), avPublicRows(), avApiRows()] as $rows) {
        expect($rows[0])->not->toHaveKey('available_for_chat_at')
            ->and($rows[0])->toHaveKey('is_available')
            ->and($rows[0]['is_available'])->toBeTrue();
    }

    // 2. Corpo inteiro das seis portas: nem a chave, nem o instante formatado.
    $responses = [
        test()->get(route('performers.public.show', $profile->slug))->assertOk(),
        test()->actingAs(avMember())->get(route('catalog.show', $profile->slug))->assertOk(),
        test()->getJson(route('performers.show', $profile->slug))->assertOk(),
        test()->actingAs(avMember())->get(route('catalog'))->assertOk(),
        test()->get(route('performers.public'))->assertOk(),
        test()->getJson(route('performers.index'))->assertOk(),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('available_for_chat_at')
            ->and($response->getContent())->not->toContain($needle);
    }

    Carbon::setTestNow();
});

it('mantem available_for_chat_at fora do fillable', function () {
    // Mass assignment não pode carimbar disponibilidade — a escrita é só por
    // forceFill no endpoint dedicado, mesma regra de discrete_mode e do 2FA.
    $profile = avPerformer();

    $profile->fill(['available_for_chat_at' => now()]);

    expect($profile->available_for_chat_at)->toBeNull();
});

// ─── DASHBOARD: o toggle e a faixa de tempo restante ─────────────────────────

it('entrega o estado de disponibilidade e a faixa ao painel da performer', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');

    $profile = avMarkAvailable(avPerformer(), now());

    test()->actingAs($profile->user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('isAvailable', true)
            // Faixa, nunca relógio: recém-ligado é "por quase 4 horas".
            ->where('availabilityRemaining', 'por quase 4 horas')
        );

    Carbon::setTestNow();
});

it('deriva a faixa de tempo restante e null quando indisponivel', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');

    $profile = avPerformer();

    expect($profile->availabilityRemainingLabel())->toBeNull();

    avMarkAvailable($profile, now());
    expect($profile->refresh()->availabilityRemainingLabel())->toBe('por quase 4 horas');

    avMarkAvailable($profile, now()->subHours(2)->subMinutes(30));
    expect($profile->refresh()->availabilityRemainingLabel())->toBe('por mais de 1 hora');

    avMarkAvailable($profile, now()->subHours(3)->subMinutes(30));
    expect($profile->refresh()->availabilityRemainingLabel())->toBe('por menos de 1 hora');

    Carbon::setTestNow();
});

// ─── Hard Delete ─────────────────────────────────────────────────────────────

it('limpa available_for_chat_at no hard delete', function () {
    $profile = avMarkAvailable(avPerformer());

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    // `withTrashed`: o perfil vira soft-delete no fim do expurgo, e sem isto a
    // query não acharia a linha e o teste passaria sem olhar nada.
    $scrubbed = PerformerProfile::withTrashed()->find($profile->id);

    expect($scrubbed->available_for_chat_at)->toBeNull();
});
