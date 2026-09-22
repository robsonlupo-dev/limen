<?php

use App\Exceptions\ChatException;
use App\Models\AuditLog;
use App\Models\ContentFlag;
use App\Models\User;
use App\Models\Warning;
use App\Services\ChatService;
use App\Services\ContentFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fila de conteúdo sinalizado (feat/flagged-content-queue, Fase 4c).
 *
 * Cobre a PIPELINE (conduta do chat vira flag; risco legal não) e a FILA do
 * moderador (agrega por reincidência; advertir/suspender/dispensar). Sem nunca
 * gravar o corpo — só o HMAC da regra. Helpers com prefixo fcq*.
 */
function fcqModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function fcqMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

/** Registra N flags para um usuário, com regras distintas (sem dedup no service). */
function fcqFlag(User $user, int $times, string $source = ContentFlag::SOURCE_CHAT): void
{
    $svc = app(ContentFlagService::class);
    for ($i = 0; $i < $times; $i++) {
        $svc->record($user, $source, hash('sha256', $source.$user->id.$i));
    }
}

// ─── Pipeline: conduta do chat vira flag; risco legal não ─────────────────────

it('uma mensagem de CONDUTA bloqueada gera um content_flag pendente (fonte chat)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    // 'vou te matar' é ameaça (conduct). O envio é bloqueado E sinalizado.
    expect(fn () => app(ChatService::class)->sendMessage($conversation, $performer->user, 'vou te matar'))
        ->toThrow(ChatException::class);

    $flag = ContentFlag::where('user_id', $performer->user->id)->first();
    expect($flag)->not->toBeNull()
        ->and($flag->source)->toBe(ContentFlag::SOURCE_CHAT)
        ->and($flag->category)->toBe(ContentFlag::CATEGORY_CONDUCT)
        ->and($flag->status)->toBe(ContentFlag::STATUS_PENDING)
        // O corpo NUNCA vaza: guardamos só o HMAC da regra (64 hex).
        ->and($flag->rule_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('uma mensagem de RISCO LEGAL bloqueada NÃO gera flag (não é fila de reincidência)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    expect(fn () => app(ChatService::class)->sendMessage($conversation, $performer->user, 'faço programa completo'))
        ->toThrow(ChatException::class);

    expect(ContentFlag::count())->toBe(0);
});

it('o mesmo bloqueio repetido na janela NÃO duplica o flag (piggyback no dedup do audit)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);

    foreach (range(1, 3) as $_) {
        try {
            app(ChatService::class)->sendMessage($conversation, $performer->user, 'vou te matar');
        } catch (ChatException) {
            // esperado — o envio é bloqueado
        }
    }

    expect(ContentFlag::where('user_id', $performer->user->id)->count())->toBe(1);
});

// ─── Serviço: a fonte é parametrizável (live_chat e futuros sinais) ───────────

it('o ContentFlagService registra flags de outras fontes (ex.: live_chat)', function () {
    $member = fcqMember();

    app(ContentFlagService::class)->record($member, ContentFlag::SOURCE_LIVE_CHAT, str_repeat('a', 64));

    $flag = ContentFlag::where('user_id', $member->id)->sole();
    expect($flag->source)->toBe(ContentFlag::SOURCE_LIVE_CHAT)
        ->and($flag->status)->toBe(ContentFlag::STATUS_PENDING);
});

// ─── Fila: agrega por usuário, ordena por reincidência ────────────────────────

it('a fila agrega por usuário e ordena por nº de flags (mais reincidente no topo)', function () {
    $heavy = fcqMember();
    $light = fcqMember();
    fcqFlag($heavy, 3);
    fcqFlag($light, 1);

    $this->actingAs(fcqModerator())
        ->get(route('moderacao.flagged.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Moderacao/FlaggedContent')
            ->has('flagged.data', 2)
            ->where('flagged.data.0.user_id', $heavy->id)
            ->where('flagged.data.0.flag_count', 3)
            ->where('flagged.data.1.user_id', $light->id)
            ->where('flagged.data.1.flag_count', 1)
            ->where('flaggedUserCount', 2));
});

it('flags dispensados não aparecem na fila', function () {
    $member = fcqMember();
    fcqFlag($member, 2);
    app(ContentFlagService::class)->dismissAllFor($member, fcqModerator());

    $this->actingAs(fcqModerator())
        ->get(route('moderacao.flagged.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('flagged.data', 0)->where('flaggedUserCount', 0));
});

// ─── Ações: advertir / suspender / dispensar ──────────────────────────────────

it('advertir um usuário sinalizado cria Warning (sem denúncia) + trilha, e NÃO limpa os flags', function () {
    $member = fcqMember();
    fcqFlag($member, 2);

    $this->actingAs(fcqModerator())
        ->post(route('moderacao.flagged.warn', $member->id), ['reason' => 'conduta reincidente'])
        ->assertRedirect();

    $warning = Warning::where('user_id', $member->id)->first();
    expect($warning)->not->toBeNull()
        ->and($warning->reason)->toBe('conduta reincidente')
        ->and($warning->report_id)->toBeNull(); // fila de flags não tem denúncia de origem
    expect(AuditLog::where('action', 'moderator.warned')->where('subject_id', $member->id)->exists())->toBeTrue();
    // Advertir e dispensar são atos distintos: os flags seguem pendentes.
    expect(ContentFlag::pending()->where('user_id', $member->id)->count())->toBe(2);
});

it('suspender um usuário sinalizado derruba a conta pelo prazo', function () {
    $member = fcqMember();
    fcqFlag($member, 1);

    $this->actingAs(fcqModerator())
        ->post(route('moderacao.flagged.suspend', $member->id), ['reason' => 'ameaça', 'days' => 3])
        ->assertRedirect();

    expect($member->fresh()->status)->toBe('suspended');
});

it('dispensar marca todos os flags pendentes do usuário como dispensados + trilha', function () {
    $member = fcqMember();
    fcqFlag($member, 3);

    $this->actingAs(fcqModerator())
        ->post(route('moderacao.flagged.dismiss', $member->id))
        ->assertRedirect();

    expect(ContentFlag::pending()->where('user_id', $member->id)->count())->toBe(0)
        ->and(ContentFlag::where('user_id', $member->id)->where('status', ContentFlag::STATUS_DISMISSED)->count())->toBe(3);
    expect(AuditLog::where('action', 'moderation.flags_dismissed')->where('subject_id', $member->id)->exists())->toBeTrue();
});

it('advertir exige motivo', function () {
    $member = fcqMember();
    fcqFlag($member, 1);

    $this->actingAs(fcqModerator())
        ->post(route('moderacao.flagged.warn', $member->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(Warning::count())->toBe(0);
});

// ─── Gate + hub ───────────────────────────────────────────────────────────────

it('a fila de conteúdo sinalizado exige moderador (membro → 403)', function () {
    $this->actingAs(fcqMember())
        ->get(route('moderacao.flagged.index'))
        ->assertForbidden();
});

it('o hub da moderação conta os usuários sinalizados pendentes', function () {
    fcqFlag(fcqMember(), 2);
    fcqFlag(fcqMember(), 1);

    $this->actingAs(fcqModerator())
        ->get(route('moderacao.overview'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('queues.flagged', 2));
});
