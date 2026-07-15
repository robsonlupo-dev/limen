<?php

use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Painel do membro (/painel): saldo, quem ele segue, interesses recebidos e
 * gorjetas enviadas.
 *
 * Helpers locais (prefixo md*) para o arquivo ser autossuficiente.
 */
function mdMember(int $balance = 0): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($balance > 0) {
        app(TokenService::class)->credit($member, $balance, 'purchase');
    }

    return $member;
}

function mdPerformer(bool $verified = true): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $verified,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function mdTip(User $member, PerformerProfile $profile, int $amount): Tip
{
    // O ledger é append-only e as FKs do tip apontam para ele; criamos as duas
    // pontas para o registro ser realista.
    $debit = app(TokenService::class)->debit($member, $amount, 'spend_tip');
    $credit = app(TokenService::class)->credit($profile->user, $amount, 'tip_credit');

    return Tip::create([
        'consumer_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'amount' => $amount,
        'performer_amount' => (int) round($amount * 0.65),
        'platform_amount' => $amount - (int) round($amount * 0.65),
        'idempotency_key' => (string) Str::uuid(),
        'consumer_ledger_id' => $debit->id,
        'performer_ledger_id' => $credit->id,
    ]);
}

it('renders the member dashboard with balance, follows, interests and tips', function () {
    $member = mdMember(100);
    $followed = mdPerformer();
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $followed->id]);
    mdTip($member, $followed, 30);

    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Dashboard')
            ->where('balance', 70) // 100 comprados - 30 de gorjeta
            ->where('followingCount', 1)
            ->has('following', 1)
            ->where('following.0.stage_name', $followed->stage_name)
            ->where('tipsSummary.count', 1)
            ->where('tipsSummary.tokens', 30)
            ->has('tips', 1)
            ->where('tips.0.performer', $followed->stage_name)
            ->where('tips.0.amount', 30)
        );
});

it('counts interests without ever naming the performer who sent them', function () {
    $member = mdMember();
    $lockedFrom = mdPerformer();
    $unlockedFrom = mdPerformer();

    PerformerInterest::create([
        'performer_profile_id' => $lockedFrom->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    PerformerInterest::create([
        'performer_profile_id' => $unlockedFrom->id,
        'member_id' => $member->id,
        'status' => 'unlocked',
        'sent_at' => now(),
        'unlocked_at' => now(),
    ]);

    $response = $this->actingAs($member)->get(route('consumer.dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('interests.locked', 1)
        ->where('interests.unlocked', 1)
    );

    // O painel só conta. Quem enviou um interesse bloqueado não pode vazar aqui
    // — a revelação é exclusiva da caixa, depois do pagamento.
    expect($response->getContent())->not->toContain($lockedFrom->stage_name);
    expect($response->getContent())->not->toContain($lockedFrom->slug);
});

it('leaves suppressed interests out of the dashboard counts', function () {
    $member = mdMember();
    PerformerInterest::create([
        'performer_profile_id' => mdPerformer()->id,
        'member_id' => $member->id,
        'status' => 'suppressed',
        'sent_at' => now(),
    ]);

    // Suprimido é invisível ao membro: contá-lo aqui revelaria a ele o próprio
    // opt-out em ação, e mostraria um número que a caixa não explica.
    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('interests.locked', 0)
            ->where('interests.unlocked', 0)
        );
});

it('drops followed performers who left the public catalog', function () {
    $member = mdMember();
    $active = mdPerformer();
    $deverified = mdPerformer(verified: false);
    $suspended = mdPerformer();
    $suspended->user->forceFill(['status' => 'suspended'])->save();

    foreach ([$active, $deverified, $suspended] as $profile) {
        Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
    }

    // Cards levam ao perfil: mostrar quem saiu do catálogo geraria link para 404.
    // A contagem tem que sair do MESMO escopo da lista — se contasse os 3 e
    // listasse 1, a diferença entregaria que alguém que ele segue foi suspensa.
    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('following', 1)
            ->where('following.0.stage_name', $active->stage_name)
            ->where('followingCount', 1)
        );
});

it('shows another member nothing of this member on the dashboard', function () {
    $member = mdMember(50);
    $other = mdMember(999);
    $profile = mdPerformer();
    mdTip($other, $profile, 40);
    Follow::create(['user_id' => $other->id, 'performer_profile_id' => $profile->id]);
    PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $other->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    // O painel é estritamente do dono da sessão.
    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance', 50)
            ->where('followingCount', 0)
            ->where('interests.locked', 0)
            ->where('tipsSummary.count', 0)
            ->has('tips', 0)
        );
});

it('renders an empty dashboard for a brand-new member', function () {
    $this->actingAs(mdMember())
        ->get(route('consumer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance', 0)
            ->where('followingCount', 0)
            ->has('following', 0)
            ->where('interests.locked', 0)
            ->where('tipsSummary.tokens', 0)
            ->has('tips', 0)
        );
});

it('denies the member dashboard to a performer', function () {
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $this->actingAs($performer)->get(route('consumer.dashboard'))->assertForbidden();
});

it('denies the member dashboard to a guest', function () {
    $this->get(route('consumer.dashboard'))->assertRedirect(route('login'));
});

it('caps the following preview and points to the full catalog', function () {
    $member = mdMember();

    foreach (range(1, 8) as $i) {
        Follow::create(['user_id' => $member->id, 'performer_profile_id' => mdPerformer()->id]);
    }

    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('following', 6) // preview
            ->where('followingCount', 8) // total real
        );
});

it('never exposes the wallet of another member through the balance', function () {
    $member = mdMember(10);
    mdMember(5000);

    // Saldo vem do ledger do próprio membro (TokenService), não de um agregado.
    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', 10));

    expect(TokenLedger::count())->toBe(2);
});
