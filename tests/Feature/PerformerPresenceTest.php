<?php

use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ChatService;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Presença da performer INVERTIDA (fix/panel-polish-v1, decisão do PO).
 *
 * O "Disponível para conversa" manual do Sprint 11 (opt-IN, carimbo que vencia
 * em 4h) virou presença AUTOMÁTICA: a performer fica ONLINE enquanto tem sessão
 * ativa. A presença DERIVA da atividade real (`users.last_active_at`, mantido
 * pelo middleware TrackPerformerActivity), não de um botão — some sozinha quando
 * a sessão encerra / fica inativa, sem job (vence na leitura, como o is_live).
 *
 * O toggle do painel virou um OPT-OUT: `appear_offline`. Ligado, a performer
 * fica invisível no catálogo (nunca online, faixa de atividade suprimida) mas
 * CONTINUA recebendo mensagens — receber mensagem NUNCA dependeu deste estado.
 *
 * Três metades, travadas separadamente:
 *  - o TOGGLE (PATCH /performer/disponibilidade): liga/desliga a VISIBILIDADE,
 *    idempotente, e escreve `appear_offline` só por forceFill (fora do $fillable);
 *  - a DERIVAÇÃO (PerformerProfile::isOnline): online = sessão recente E não
 *    opt-out, vence na leitura, sem job;
 *  - a EXIBIÇÃO (PerformerPublicResource + filtro): o público vê só o booleano
 *    `is_available` (= isOnline), NUNCA um carimbo; e o opt-out apaga TODO sinal
 *    de presença (online E faixa de atividade).
 *
 * Helpers locais (prefixo pres*) para o arquivo ser autossuficiente.
 */

function presPerformer(string $stageName = 'Ana', array $userAttributes = [], array $profileAttributes = []): PerformerProfile
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

function presMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

/**
 * Marca a performer ONLINE (atividade real, como o middleware
 * TrackPerformerActivity faz enquanto ela navega). Sem argumento, agora.
 */
function presOnline(PerformerProfile $profile, ?Carbon $at = null): PerformerProfile
{
    $profile->user->forceFill(['last_active_at' => $at ?? now()])->save();

    return $profile;
}

/** As linhas do catálogo AUTENTICADO. */
function presAuthRows(array $query = []): array
{
    return test()->actingAs(presMember())->get(route('catalog', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas do catálogo PÚBLICO. */
function presPublicRows(array $query = []): array
{
    return test()->get(route('performers.public', $query))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

/** As linhas da API v1. */
function presApiRows(array $query = []): array
{
    return test()->getJson(route('performers.index', $query))->assertOk()->json('data');
}

/** Nomes artísticos ordenados, para as asserções de filtro. */
function presNames(array $rows): array
{
    return collect($rows)->pluck('stage_name')->sort()->values()->all();
}

// ─── TOGGLE: visível ↔ invisível ─────────────────────────────────────────────

it('fica invisivel pelo toggle', function () {
    $profile = presPerformer();

    // Nasce visível (opt-out desligado — default do banco, lido após refresh).
    expect($profile->fresh()->appear_offline)->toBeFalse();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['visible' => false])
        ->assertOk()
        ->assertJson(['visible' => false]);

    expect($profile->refresh()->appear_offline)->toBeTrue();
});

it('volta a aparecer pelo toggle', function () {
    $profile = presPerformer();
    $profile->forceFill(['appear_offline' => true])->save();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['visible' => true])
        ->assertOk()
        ->assertJson(['visible' => true]);

    expect($profile->refresh()->appear_offline)->toBeFalse();
});

it('inverte a visibilidade quando o toggle vem sem o campo', function () {
    // Sem `visible` no corpo o servidor inverte — é o que torna duplo clique /
    // retry inofensivo, mesmo padrão do ToggleDiscreteModeRequest.
    $profile = presPerformer();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), [])
        ->assertOk()
        ->assertJson(['visible' => false]);

    expect($profile->refresh()->appear_offline)->toBeTrue();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), [])
        ->assertOk()
        ->assertJson(['visible' => true]);

    expect($profile->refresh()->appear_offline)->toBeFalse();
});

// ─── TOGGLE: quem pode ───────────────────────────────────────────────────────

it('recusa o toggle para o membro', function () {
    // role:performer aborta 403 — o toggle é da vitrine, e só a performer é
    // vitrine. É a direção segura, nunca o membro mexendo no estado dela.
    test()->actingAs(presMember())
        ->patchJson(route('performer.availability.toggle'), ['visible' => false])
        ->assertForbidden();
});

it('recusa o toggle para o visitante deslogado', function () {
    // Rota WEB: o `auth` redireciona o visitante para o login (302), não 401.
    test()->patchJson(route('performer.availability.toggle'), ['visible' => false])
        ->assertRedirect(route('login'));
});

it('recusa um valor nao-booleano com 422 json', function () {
    // Rota WEB consumida por fetch: o erro sai 422 JSON (FailsValidationAsJson),
    // não redirect com erros de sessão.
    $profile = presPerformer();

    test()->actingAs($profile->user)
        ->patchJson(route('performer.availability.toggle'), ['visible' => 'talvez'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visible');
});

// ─── DERIVAÇÃO: online = sessão recente E sem opt-out ─────────────────────────

it('deriva online da atividade recente, na janela de 10 minutos', function () {
    $profile = presPerformer();

    // Nunca teve atividade → offline (sem sessão, sem presença).
    expect($profile->isOnline())->toBeFalse();

    // Ativa agora e a 9 min atrás: dentro da janela.
    presOnline($profile, now());
    expect($profile->fresh()->isOnline())->toBeTrue();

    presOnline($profile, now()->subMinutes(9));
    expect($profile->fresh()->isOnline())->toBeTrue();

    // 11 min atrás: fora da janela, sem job — a expiração é da LEITURA.
    presOnline($profile, now()->subMinutes(11));
    expect($profile->fresh()->isOnline())->toBeFalse();
});

it('o opt-out derruba o online mesmo com sessao ativa agora', function () {
    // appear_offline é a PRIMEIRA guarda de isOnline: mesmo ativa neste segundo,
    // quem optou por invisível não aparece como online.
    $profile = presOnline(presPerformer());
    expect($profile->fresh()->isOnline())->toBeTrue();

    $profile->forceFill(['appear_offline' => true])->save();
    expect($profile->fresh()->isOnline())->toBeFalse();
});

// ─── EXIBIÇÃO: o badge no card ───────────────────────────────────────────────

it('entrega is_available true nas tres portas quando online', function () {
    presOnline(presPerformer());

    foreach ([presAuthRows(), presPublicRows(), presApiRows()] as $rows) {
        expect($rows[0]['is_available'])->toBeTrue();
    }
});

it('entrega is_available false para quem esta inativo', function () {
    presPerformer(); // nunca teve atividade

    foreach ([presAuthRows(), presPublicRows(), presApiRows()] as $rows) {
        expect($rows[0])->toHaveKey('is_available')
            ->and($rows[0]['is_available'])->toBeFalse();
    }
});

it('some do online e apaga a faixa de atividade quando invisivel', function () {
    // Ativa agora, mas em opt-out: nenhum sinal de presença vaza — nem is_available,
    // nem a faixa "Ativa hoje" (a promessa da tela dela é "invisível no catálogo").
    $profile = presOnline(presPerformer());
    $profile->forceFill(['appear_offline' => true])->save();

    foreach ([presAuthRows(), presPublicRows(), presApiRows()] as $rows) {
        expect($rows[0]['is_available'])->toBeFalse()
            ->and($rows[0]['activity_label'])->toBeNull();
    }
});

// ─── EXIBIÇÃO: is_available convive com is_live para a tela ocultar o badge ───

it('entrega is_available e is_live juntos para a tela ocultar o badge', function () {
    // Online + ao vivo: o resource manda os dois; a tela mostra só o LiveBadge
    // (`v-if="is_available && !is_live"`). Sem runner de JS, o pé server-side
    // trava a regressão: se is_live sumisse, o badge voltaria durante a live.
    $profile = presOnline(presPerformer('Ana', profileAttributes: ['is_live' => true]));

    foreach ([
        test()->get(route('performers.public.show', $profile->slug)),
        test()->actingAs(presMember())->get(route('catalog.show', $profile->slug)),
    ] as $response) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('performer.is_available', true)
            ->where('performer.is_live', true)
        );
    }
});

// ─── FILTRO: "Online agora" ──────────────────────────────────────────────────

it('filtra o catalogo por online agora nas tres portas', function () {
    presOnline(presPerformer('Ana'));                       // ativa agora
    presPerformer('Bia');                                   // nunca ativa
    presOnline(presPerformer('Cris'), now()->subMinutes(20)); // ativa, mas venceu

    $invisivel = presOnline(presPerformer('Duda'));         // ativa, mas em opt-out
    $invisivel->forceFill(['appear_offline' => true])->save();

    // Só quem está na janela E visível casa. A venceu-há-20min e a invisível NÃO
    // entram — a faceta pergunta "online AGORA", o mesmo corte da leitura.
    expect(presNames(presAuthRows(['available' => 1])))->toBe(['Ana'])
        ->and(presNames(presPublicRows(['available' => 1])))->toBe(['Ana'])
        ->and(presNames(presApiRows(['available' => 1])))->toBe(['Ana']);
});

it('deixa passar todo mundo quando o filtro nao esta marcado', function () {
    presOnline(presPerformer('Ana'));
    presPerformer('Bia');

    // A faceta é opt-in: sem ela, quem está offline continua no catálogo.
    expect(presNames(presAuthRows()))->toBe(['Ana', 'Bia'])
        ->and(presNames(presPublicRows()))->toBe(['Ana', 'Bia'])
        ->and(presNames(presApiRows()))->toBe(['Ana', 'Bia']);
});

it('recusa um valor de filtro nao-booleano', function () {
    presPerformer('Ana');

    test()->getJson(route('performers.index', ['available' => 'xpto']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('available');
});

// ─── MENSAGEM: NUNCA depende da presença (o ponto crítico do PO) ──────────────

it('entrega mensagem do membro mesmo com a performer invisivel', function () {
    // Receber mensagem NUNCA dependeu da presença/visibilidade (ChatService só
    // olha participante + conversa ativa + acesso de chat). Com a performer em
    // opt-out (invisível no catálogo), o membro com acesso continua enviando.
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, 5);
    grantChatAccess($member, $conversation);

    $performer->forceFill(['appear_offline' => true])->save();

    $message = app(ChatService::class)->sendMessage($conversation->fresh(), $member, 'oi, tudo bem?');

    expect($message->exists)->toBeTrue()
        ->and(Message::where('conversation_id', $conversation->id)
            ->where('sender_id', $member->id)
            ->exists())->toBeTrue();
});

// ─── PRIVACIDADE: nem o flag nem o carimbo de atividade saem ──────────────────

it('nunca expoe appear_offline nem o timestamp de atividade em nenhuma porta', function () {
    // O público vê só o booleano derivado `is_available`. O flag de opt-out e o
    // `last_active_at` cru são $hidden — expor o flag anunciaria "escondida de
    // propósito"; o timestamp seria presença ao minuto.
    $profile = presOnline(presPerformer('Ana', profileAttributes: ['state' => 'SP']));

    foreach ([presAuthRows(), presPublicRows(), presApiRows()] as $rows) {
        expect($rows[0])->not->toHaveKey('appear_offline')
            ->and($rows[0])->not->toHaveKey('last_active_at')
            ->and($rows[0])->toHaveKey('is_available');
    }
});

it('mantem appear_offline fora do fillable', function () {
    // Mass assignment não pode ligar o opt-out — a escrita é só por forceFill no
    // endpoint dedicado, mesma regra de discrete_mode e do 2FA.
    $profile = presPerformer();

    $profile->fill(['appear_offline' => true]);

    // fill() ignora o campo fora do $fillable — o opt-out não foi ligado.
    expect($profile->appear_offline)->not->toBeTrue();
});

// ─── Hard Delete ─────────────────────────────────────────────────────────────

it('zera appear_offline no hard delete', function () {
    $profile = presPerformer();
    $profile->forceFill(['appear_offline' => true])->save();

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    // `withTrashed`: o perfil vira soft-delete no fim do expurgo.
    $scrubbed = PerformerProfile::withTrashed()->find($profile->id);

    expect($scrubbed->appear_offline)->toBeFalse();
});
