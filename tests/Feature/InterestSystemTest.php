<?php

use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Sistema de Interesse Controlado (Sprint 3). Ver docs/INTEREST_SYSTEM_SPEC.md.
 * Performer envia sinal binário; membro paga 15 tokens (100% plataforma) para
 * desbloquear quem é. Chat fica para depois.
 *
 * Helpers locais (prefixo interest*) para o arquivo ser autossuficiente.
 */
function interestMember(int $balance = 0): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($balance > 0) {
        app(TokenService::class)->credit($member, $balance, 'purchase');
    }

    return $member;
}

/**
 * Membro que já segue a performer. O envio de interesse só aceita seguidores
 * (ver SendInterestRequest), então é este o alvo válido na maioria dos casos.
 */
function interestFollower(PerformerProfile $profile, int $balance = 0): User
{
    $member = interestMember($balance);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    return $member;
}

/**
 * Enche a lista até um seguidor a menos que o Piso de Anonimato, para que o
 * seguidor criado A SEGUIR pelo teste feche o piso e a lista fique visível.
 *
 * Os testes abaixo falam de anonimização, cooldown e opt-out — não do piso.
 * Sem o preenchimento eles passariam a medir o piso (lista vazia) em vez do que
 * pretendem provar. A cobertura do piso em si vive em AnonimityFloorTest.
 */
function padFollowersBelowFloor(PerformerProfile $profile): void
{
    foreach (range(1, (int) config('interest.anonymity_floor') - 1) as $ignored) {
        interestFollower($profile);
    }
}

function interestPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

it('lets an active performer send a binary interest without revealing or charging', function () {
    $profile = interestPerformer();
    $member = interestFollower($profile);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated()
        ->assertExactJson(['sent' => true]);

    $interest = PerformerInterest::sole();
    expect($interest->status)->toBe('sent');
    expect($interest->unlocked_at)->toBeNull();
    expect($interest->unlock_ledger_id)->toBeNull();
    // Nenhum token se moveu no envio.
    expect(TokenLedger::count())->toBe(0);
});

it('debits exactly 15 tokens from a non-subscriber (100% platform) and reveals the performer on unlock', function () {
    $profile = interestPerformer();
    $member = interestMember(50); // sem assinatura

    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson(route('interests.unlock', $interest))
        ->assertOk()
        ->assertJsonPath('status', 'unlocked')
        ->assertJsonPath('performer.stage_name', $profile->stage_name)
        ->assertJsonPath('performer.slug', $profile->slug)
        ->assertJsonPath('new_balance', 35);

    // Débito de 15 no ledger append-only; performer NÃO é creditada.
    $entry = TokenLedger::where('entry_type', 'spend_interest_unlock')->sole();
    expect($entry->amount)->toBe(-15);
    expect(TokenWallet::where('user_id', $member->id)->value('balance'))->toBe(35);
    expect(TokenLedger::count())->toBe(2); // purchase + unlock, nada creditado à performer

    $interest->refresh();
    expect($interest->status)->toBe('unlocked');
    expect($interest->unlock_ledger_id)->toBe($entry->id);
});

it('reveals the performer for free (no debit) when the member has an active subscription', function () {
    $profile = interestPerformer();
    // Saldo 0 de propósito: o assinante desbloqueia sem depender de tokens.
    $member = interestMember(0);
    Subscription::factory()->create(['user_id' => $member->id]); // Círculo ativo

    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson(route('interests.unlock', $interest))
        ->assertOk()
        ->assertJsonPath('status', 'unlocked')
        ->assertJsonPath('performer.stage_name', $profile->stage_name)
        ->assertJsonPath('new_balance', 0);

    // Nenhum débito: sem lançamento de desbloqueio e sem vínculo de ledger.
    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(0);
    expect(TokenWallet::where('user_id', $member->id)->value('balance') ?? 0)->toBe(0);

    $interest->refresh();
    expect($interest->status)->toBe('unlocked');
    expect($interest->unlock_ledger_id)->toBeNull(); // revelado de graça
});

it('does not grant a free unlock when the subscription has lapsed', function () {
    $profile = interestPerformer();
    $member = interestMember(50);
    // Assinatura vencida (status 'active' mas fora do período pago) NÃO conta.
    Subscription::factory()->expired()->create(['user_id' => $member->id]);

    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson(route('interests.unlock', $interest))
        ->assertOk()
        ->assertJsonPath('new_balance', 35); // debitou 15

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->sole()->amount)->toBe(-15);
});

it('is idempotent — unlocking twice never double-charges', function () {
    $profile = interestPerformer();
    $member = interestMember(50);
    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)->postJson(route('interests.unlock', $interest))->assertOk();
    $this->actingAs($member)->postJson(route('interests.unlock', $interest))->assertOk();

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(1);
    expect(TokenWallet::where('user_id', $member->id)->value('balance'))->toBe(35);
});

it('rejects unlock with insufficient balance without touching the ledger', function () {
    $profile = interestPerformer();
    $member = interestMember(10);
    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson(route('interests.unlock', $interest))
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(0);
    expect($interest->fresh()->status)->toBe('sent');
});

it('blocks a second send to the same member within the cooldown window', function () {
    $profile = interestPerformer();
    $member = interestFollower($profile);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated();

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'cooldown');

    expect(PerformerInterest::count())->toBe(1);
});

it('enforces the daily send limit per performer', function () {
    $profile = interestPerformer();

    // Envia o teto (5) para membros distintos.
    foreach (range(1, 5) as $i) {
        $member = interestFollower($profile);
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertCreated();
    }

    $extra = interestFollower($profile);
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $extra->id])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'daily_limit');

    expect(PerformerInterest::count())->toBe(5);
});

it('suppresses interest to a member who opted out (no leak to the performer)', function () {
    $profile = interestPerformer();
    $member = interestFollower($profile);
    $member->update(['interests_opt_out' => true]);

    // Resposta idêntica ao sucesso — a performer não percebe o opt-out.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated()
        ->assertExactJson(['sent' => true]);

    // A linha existe (é o que faz cooldown/limite contarem), mas suprimida.
    expect(PerformerInterest::sole()->status)->toBe('suppressed');
});

it('applies the cooldown to an opted-out member so the performer cannot detect the opt-out', function () {
    $profile = interestPerformer();
    $optedOut = interestFollower($profile);
    $optedOut->update(['interests_opt_out' => true]);
    $normal = interestFollower($profile);

    foreach ([$optedOut, $normal] as $member) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertCreated();
    }

    // O reenvio precisa falhar IGUAL para os dois. Se o opt-out não gravasse
    // linha, não haveria cooldown e a ausência do erro revelaria o opt-out.
    foreach ([$optedOut, $normal] as $member) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'cooldown');
    }
});

it('counts a suppressed interest against the daily limit', function () {
    $profile = interestPerformer();

    // Cinco envios, todos a membros que optaram por sair, esgotam a cota do dia
    // exatamente como envios normais — o contador não pode denunciar o opt-out.
    foreach (range(1, 5) as $i) {
        $member = interestFollower($profile);
        $member->update(['interests_opt_out' => true]);

        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertCreated();
    }

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => interestFollower($profile)->id])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'daily_limit');
});

it('suppresses interest to an opted-out member even when they unlocked this performer before', function () {
    $profile = interestPerformer();
    $member = interestFollower($profile, 50);
    PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'unlocked',
        'sent_at' => now()->subDays(60),
        'unlocked_at' => now()->subDays(60),
    ]);

    $member->update(['interests_opt_out' => true]);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated();

    // O opt-out vence o auto-unlock: quem optou por sair não recebe, mesmo já
    // tendo pago por esta performer antes.
    expect(PerformerInterest::latest('id')->first()->status)->toBe('suppressed');
});

it('hides suppressed interests from the member inbox', function () {
    $profile = interestPerformer();
    $member = interestMember();
    $suppressed = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'suppressed',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Interests/Index')
            ->has('interests.data', 0)
        );

    // E não pode ser desbloqueado: para o membro a linha não existe.
    $this->actingAs($member)
        ->postJson(route('interests.unlock', $suppressed))
        ->assertNotFound();

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(0);
});

it('never reveals the performer while the interest is locked', function () {
    $profile = interestPerformer();
    $member = interestMember();
    PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $response = $this->actingAs($member)->get(route('interests.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Consumer/Interests/Index')
        ->where('interests.data.0.status', 'sent')
        ->where('interests.data.0.performer', null)
    );

    // A identidade não pode aparecer em lugar nenhum do payload.
    expect($response->getContent())->not->toContain($profile->stage_name);
    expect($response->getContent())->not->toContain($profile->slug);
});

it('returns 404 when unlocking an interest that belongs to someone else', function () {
    $profile = interestPerformer();
    $owner = interestMember(50);
    $intruder = interestMember(50);
    $interest = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $owner->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($intruder)
        ->postJson(route('interests.unlock', $interest))
        ->assertNotFound();

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(0);
    expect($interest->fresh()->status)->toBe('sent');
});

it('rejects interest aimed at a member who does not follow the performer', function () {
    $profile = interestPerformer();
    $stranger = interestMember();

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $stranger->id])
        ->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('does not let a performer enumerate members through the send endpoint', function () {
    $profile = interestPerformer();

    // Esgota a cota do dia com seguidores legítimos.
    foreach (range(1, 5) as $i) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => interestFollower($profile)->id])
            ->assertCreated();
    }

    $activeStranger = interestMember();
    $suspended = User::factory()->create(['role' => 'consumer', 'status' => 'suspended']);
    $otherPerformer = interestPerformer();

    // Todo id que não segue esta performer responde IGUAL — inclusive um id que
    // nem existe. Sem isso, 404 (desconhecido) vs 422 (daily_limit) revelaria
    // quais ids são membros ativos da plataforma, de graça e sem gastar cota.
    foreach ([$activeStranger->id, $suspended->id, $otherPerformer->user_id, 99999999] as $probe) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $probe])
            ->assertNotFound();
    }

    expect(PerformerInterest::count())->toBe(5);
});

it('rejects interest aimed at a non-member target', function () {
    $profile = interestPerformer();
    $otherPerformer = interestPerformer();

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $otherPerformer->user_id])
        ->assertNotFound();

    expect(PerformerInterest::count())->toBe(0);
});

it('reveals a performer for free once the member has already unlocked them', function () {
    $profile = interestPerformer();
    $member = interestMember(50);

    // Primeiro interesse, desbloqueado (cobra 15).
    $first = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now()->subDays(40), // fora do cooldown
    ]);
    app(InterestService::class)->unlock($member, $first);

    // Novo envio após o cooldown já nasce revelado, sem nova cobrança.
    $second = app(InterestService::class)->send($profile, $member->fresh());
    expect($second->status)->toBe('unlocked');
    expect($second->unlock_ledger_id)->toBeNull();

    // Continua tendo cobrado só uma vez.
    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(1);
    expect(TokenWallet::where('user_id', $member->id)->value('balance'))->toBe(35);
});

it('charges once per performer even across two separate unlocked interests', function () {
    $profile = interestPerformer();
    $member = interestMember(50);

    // Dois interesses da mesma performer (ex.: envios em janelas diferentes).
    $first = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now()->subDays(40),
    ]);
    $second = PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($member)->postJson(route('interests.unlock', $first))->assertOk();
    // Segundo desbloqueio da mesma performer não cobra de novo.
    $this->actingAs($member)
        ->postJson(route('interests.unlock', $second))
        ->assertOk()
        ->assertJsonPath('new_balance', 35);

    expect(TokenLedger::where('entry_type', 'spend_interest_unlock')->count())->toBe(1);
    expect($second->fresh()->unlock_ledger_id)->toBeNull();
});

it('toggles the member opt-out preference', function () {
    $member = interestMember();

    $this->actingAs($member)
        ->patchJson(route('interests.opt-out'), ['opt_out' => true])
        ->assertOk()
        ->assertJsonPath('interests_opt_out', true);

    expect($member->fresh()->interests_opt_out)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Tela de seguidores — origem do envio de interesse
|--------------------------------------------------------------------------
| A performer só vê membros que já a seguem, sempre anonimizados. É a única
| superfície de onde ela dispara o Interesse Controlado.
*/

it('lists the performer followers anonymised, without member PII', function () {
    $profile = interestPerformer();
    padFollowersBelowFloor($profile);
    $member = interestMember();
    $member->update(['name' => 'Fulano de Tal', 'email' => 'fulano@example.com']);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    $response = $this->actingAs($profile->user)->get(route('performer.followers'));

    // Ordem é created_at desc: o membro recém-criado é o primeiro da lista.
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Performer/Followers')
        ->has('followers.data', (int) config('interest.anonymity_floor'))
        ->where('followers.data.0.member_id', $member->id)
        ->where('followers.data.0.label', 'Membro #' . $member->id)
        ->where('followers.data.0.interest_sent', false)
        ->where('remainingToday', 5)
    );

    // Nome e e-mail do membro nunca podem chegar à performer.
    expect($response->getContent())->not->toContain('Fulano de Tal');
    expect($response->getContent())->not->toContain('fulano@example.com');
});

it('marks a follower already in cooldown so the performer cannot resend', function () {
    $profile = interestPerformer();
    padFollowersBelowFloor($profile);
    $member = interestMember();
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated();

    $this->actingAs($profile->user)
        ->get(route('performer.followers'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('followers.data.0.interest_sent', true)
            ->where('remainingToday', 4)
        );
});

it('shows an opted-out follower exactly like any other follower', function () {
    $profile = interestPerformer();
    padFollowersBelowFloor($profile);
    $optedOut = interestMember();
    $optedOut->update(['interests_opt_out' => true]);
    Follow::create(['user_id' => $optedOut->id, 'performer_profile_id' => $profile->id]);

    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $optedOut->id])
        ->assertCreated();

    // O interesse suprimido conta na lista e na cota: sem isso, o botão voltaria
    // a "enviar" e o contador não cairia — denunciando o opt-out.
    $this->actingAs($profile->user)
        ->get(route('performer.followers'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('followers.data', (int) config('interest.anonymity_floor'))
            ->where('followers.data.0.interest_sent', true)
            ->where('remainingToday', 4)
        );
});

it('leaves suspended and deleted members out of the followers list', function () {
    $profile = interestPerformer();
    padFollowersBelowFloor($profile);
    $active = interestFollower($profile);

    // status não é fillable (proteção de mass-assignment), então update() aqui
    // seria um no-op silencioso — forceFill é o que de fato suspende.
    $suspended = interestFollower($profile);
    $suspended->forceFill(['status' => 'suspended'])->save();

    $deleted = interestFollower($profile);
    $deleted->delete();

    // Quem apagou a conta não pode seguir visível à performer, e listar um id
    // não-enviável viraria oráculo: o envio para ele dá 404 (igual a "não
    // existe"), enquanto um seguidor normal dá 201 — o botão denunciaria a
    // suspensão. Invariante: todo id listado tem que ser enviável.
    $this->actingAs($profile->user)
        ->get(route('performer.followers'))
        ->assertInertia(fn (Assert $page) => $page
            // Só o ativo entra além do preenchimento: suspenso e apagado ficam
            // de fora tanto da lista quanto do total que alimenta o piso.
            ->has('followers.data', (int) config('interest.anonymity_floor'))
            ->where('followers.data.0.member_id', $active->id)
        );

    foreach ([$suspended, $deleted] as $member) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertNotFound();
    }
});

it('denies the followers page to a performer who is not active yet', function () {
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);

    $this->actingAs($user)->get(route('performer.followers'))->assertForbidden();
});

it('denies the followers page to a member', function () {
    $this->actingAs(interestMember())->get(route('performer.followers'))->assertForbidden();
});
