<?php

use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TipService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeDashboardConsumer(int $balance = 0): User
{
    $consumer = makeWebConsumer();

    if ($balance > 0) {
        app(TokenService::class)->credit($consumer, $balance, 'purchase');
    }

    return $consumer;
}

function sendDashboardTip(User $consumer, PerformerProfile $profile, int $amount, ?string $createdAt = null): Tip
{
    $tip = app(TipService::class)->send($consumer, $profile, $amount, (string) Str::uuid());

    if ($createdAt) {
        DB::table('tips')->where('id', $tip->id)->update(['created_at' => $createdAt]);
    }

    return $tip->fresh();
}

// ─── Acesso ─────────────────────────────────────────────────────────────────

it('performer ativo acessa o dashboard', function () {
    [$performer] = makeWebPerformer();

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performer/Dashboard'));
});

it('performer pending recebe 403', function () {
    [$performer] = makeWebPerformer(['status' => 'pending']);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertForbidden();
});

it('performer suspended recebe 403', function () {
    [$performer] = makeWebPerformer(['status' => 'suspended']);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertForbidden();
});

it('consumer nao acessa rota de performer', function () {
    $consumer = makeWebConsumer();

    $this->actingAs($consumer)
        ->get('/performer/dashboard')
        ->assertForbidden();
});

it('admin nao acessa rota de performer', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/performer/dashboard')
        ->assertForbidden();
});

it('visitante nao autenticado e redirecionado para login', function () {
    $this->get('/performer/dashboard')
        ->assertRedirect(route('login'));
});

// ─── Dados ──────────────────────────────────────────────────────────────────

it('saldo retornado bate com token_wallets', function () {
    [$performer] = makeWebPerformer();
    $consumer = makeDashboardConsumer(100);
    [, $profile] = [$performer, $performer->performerProfile];

    sendDashboardTip($consumer, $profile, 50);

    $walletBalance = TokenWallet::where('user_id', $performer->id)->value('balance');

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('wallet', $walletBalance));
});

it('total ganho soma apenas creditos tip e split', function () {
    [$performer] = makeWebPerformer();
    $profile = $performer->performerProfile;
    $consumer = makeDashboardConsumer(1000);

    $tip1 = sendDashboardTip($consumer, $profile, 50);
    $tip2 = sendDashboardTip($consumer, $profile, 30);

    $expectedEarned = $tip1->performer_amount + $tip2->performer_amount;

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('totalEarned', $expectedEarned));
});

it('gorjetas aparecem ordenadas por data desc', function () {
    [$performer] = makeWebPerformer();
    $profile = $performer->performerProfile;
    $consumer = makeDashboardConsumer(1000);

    sendDashboardTip($consumer, $profile, 10, now()->subMinutes(10)->toDateTimeString());
    sendDashboardTip($consumer, $profile, 20, now()->subMinutes(5)->toDateTimeString());
    sendDashboardTip($consumer, $profile, 30, now()->toDateTimeString());

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tips.0.amount', fn ($amount) => $amount === (int) floor(30 * $profile->split_pct / 100))
            ->where('tips.2.amount', fn ($amount) => $amount === (int) floor(10 * $profile->split_pct / 100))
        );
});

it('gorjetas limitadas a 10 itens', function () {
    [$performer] = makeWebPerformer();
    $profile = $performer->performerProfile;
    $consumer = makeDashboardConsumer(1000);

    for ($i = 0; $i < 11; $i++) {
        sendDashboardTip($consumer, $profile, 10, now()->subMinutes(11 - $i)->toDateTimeString());
    }

    expect(Tip::where('performer_profile_id', $profile->id)->count())->toBe(11);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('tips', 10));
});

it('remetente anonimizado como Fa #XXXX', function () {
    [$performer] = makeWebPerformer();
    $profile = $performer->performerProfile;
    $consumer = makeDashboardConsumer(100);

    sendDashboardTip($consumer, $profile, 10);

    $expectedFan = FanAlias::label($profile->id, $consumer->id);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('tips.0.fan', $expectedFan));
});

it('nome email e user_id real nao aparecem na resposta das gorjetas', function () {
    [$performer] = makeWebPerformer();
    $profile = $performer->performerProfile;
    $consumer = makeDashboardConsumer(100);

    sendDashboardTip($consumer, $profile, 10);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tips.0', fn (Assert $tip) => $tip
                ->hasAll(['fan', 'amount', 'created_at'])
                ->missing('consumer_id')
                ->missing('email')
                ->missing('name')
                ->missing('user_id')
            )
        );
});

it('kyc status active quando verificacao aprovada', function () {
    [$performer] = makeWebPerformer();
    $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'status' => 'approved',
        'age_confirmed' => true,
    ]);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'active'));
});

it('kyc status rejected quando verificacao rejeitada', function () {
    [$performer] = makeWebPerformer();
    $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'status' => 'rejected',
    ]);

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'rejected'));
});

it('kyc status pending quando nao ha verificacao', function () {
    [$performer] = makeWebPerformer();

    $this->actingAs($performer)
        ->get('/performer/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'pending'));
});
