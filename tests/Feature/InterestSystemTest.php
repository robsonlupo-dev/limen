<?php

use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
    $member = interestMember();

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

it('debits exactly 15 tokens (100% platform) and reveals the performer on unlock', function () {
    $profile = interestPerformer();
    $member = interestMember(50);

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
    $member = interestMember();

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
        $member = interestMember();
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertCreated();
    }

    $extra = interestMember();
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $extra->id])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'daily_limit');

    expect(PerformerInterest::count())->toBe(5);
});

it('silently drops interest to a member who opted out (no leak to the performer)', function () {
    $profile = interestPerformer();
    $member = interestMember();
    $member->update(['interests_opt_out' => true]);

    // Resposta idêntica ao sucesso — a performer não percebe o opt-out.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
        ->assertCreated()
        ->assertExactJson(['sent' => true]);

    expect(PerformerInterest::count())->toBe(0);
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

    $response = $this->actingAs($member)->getJson(route('interests.index'));

    $response->assertOk()->assertJsonPath('interests.data.0.status', 'sent');
    $response->assertJsonPath('interests.data.0.performer', null);
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
