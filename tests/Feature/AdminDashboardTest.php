<?php

use App\Models\CallSession;
use App\Models\LiveSession;
use App\Models\Payout;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\AdminMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Painel admin de receita (Sprint 16). Agregados do ledger + contadores + payouts
// em needs_review. Financeiro sensível: gate admin + ZERO PII de membro na view.
// Helpers locais (adm*).

function admWallet(User $user): TokenWallet
{
    return TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
}

function admLedger(TokenWallet $wallet, string $type, int $amount, ?Carbon $at = null): void
{
    $row = TokenLedger::create([
        'wallet_id' => $wallet->id,
        'entry_type' => $type,
        'amount' => $amount,
        'balance_after' => 0,
    ]);

    if ($at !== null) {
        TokenLedger::where('id', $row->id)->update(['created_at' => $at]);
    }
}

function admPerformer(string $stageName = 'Perf'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stageName.' '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function admMetrics(): AdminMetricsService
{
    return app(AdminMetricsService::class);
}

// ─── Receita: SUM por entry_type ─────────────────────────────────────────────

it('soma tokens vendidos, gastos por tipo, payout, retenção e receita bruta', function () {
    $wallet = admWallet(User::factory()->create(['role' => 'consumer']));

    admLedger($wallet, 'purchase', 1000);
    admLedger($wallet, 'spend_tip', -100);
    admLedger($wallet, 'spend_gift', -40);
    admLedger($wallet, 'spend_content', -200);
    admLedger($wallet, 'spend_live', -70);
    admLedger($wallet, 'spend_call', -50);
    admLedger($wallet, 'spend_chat_access', -2);
    admLedger($wallet, 'payout_reserve', -300);
    admLedger($wallet, 'payout_reversal', 100); // saque falho volta a ser devido

    $r = admMetrics()->revenue()['last30'];

    expect($r['tokens_sold'])->toBe(1000)
        ->and($r['spent_by_type'])->toBe([
            'chat' => 2, 'gorjeta' => 100, 'presente' => 40,
            'conteudo' => 200, 'live' => 70, 'chamada' => 50,
        ])
        ->and($r['spent_total'])->toBe(462)
        // Payout líquido: -(reserve + reversal) = -(-300 + 100) = 200.
        ->and($r['tokens_paid_out'])->toBe(200)
        // Retenção: vendidos - pagos = 1000 - 200.
        ->and($r['retention'])->toBe(800)
        // Receita bruta = 1000 × 84960/955 ≈ 88963 centavos.
        ->and($r['gross_revenue_cents'])->toBe(88963);
});

it('separa a janela de hoje da de 30 dias e exclui o que é mais antigo', function () {
    $this->travelTo(Carbon::parse('2026-08-15 15:00:00', 'America/Sao_Paulo'));

    $wallet = admWallet(User::factory()->create(['role' => 'consumer']));

    admLedger($wallet, 'purchase', 500); // hoje
    admLedger($wallet, 'purchase', 300, now()->subDays(10)); // dentro de 30d, não hoje
    admLedger($wallet, 'purchase', 200, now()->subDays(40)); // fora de tudo

    $revenue = admMetrics()->revenue();

    expect($revenue['today']['tokens_sold'])->toBe(500)
        ->and($revenue['last30']['tokens_sold'])->toBe(800);
});

// ─── Contadores ──────────────────────────────────────────────────────────────

it('conta membros/performers ativos (7d) e lives/chamadas de hoje', function () {
    // Ativos nos últimos 7 dias.
    User::factory()->count(2)->create(['role' => 'consumer', 'last_login_at' => now()->subDays(2)]);
    User::factory()->create(['role' => 'consumer', 'last_login_at' => now()->subDays(10)]); // fora
    User::factory()->create(['role' => 'performer', 'last_login_at' => now()->subDays(3)]);
    User::factory()->create(['role' => 'performer', 'last_login_at' => now()->subDays(20)]); // fora

    $profile = admPerformer();

    // Uma live hoje, uma antiga.
    LiveSession::create(['performer_profile_id' => $profile->id, 'room_name' => 'r'.Str::random(6), 'status' => 'live', 'viewer_count' => 0, 'started_at' => now()]);
    $old = LiveSession::create(['performer_profile_id' => $profile->id, 'room_name' => 'r'.Str::random(6), 'status' => 'ended', 'viewer_count' => 0, 'started_at' => now()->subDays(5)]);
    LiveSession::where('id', $old->id)->update(['created_at' => now()->subDays(5)]);

    // Uma chamada 1:1 hoje, uma antiga; um group show hoje (não conta).
    $member = User::factory()->create(['role' => 'consumer']);
    $call = CallSession::create(['performer_profile_id' => $profile->id, 'member_id' => $member->id, 'room_name' => 'c'.Str::random(6), 'price_per_minute' => 10]);
    $call->forceFill(['type' => 'private'])->save();

    $group = CallSession::create(['performer_profile_id' => $profile->id, 'member_id' => $member->id, 'room_name' => 'g'.Str::random(6), 'price_per_minute' => 10]);
    $group->forceFill(['type' => 'group'])->save();

    $counters = admMetrics()->counters();

    expect($counters['active_members'])->toBe(2)
        ->and($counters['active_performers'])->toBe(1)
        ->and($counters['lives_today'])->toBe(1)
        ->and($counters['calls_today'])->toBe(1); // group não conta
});

// ─── Payouts em needs_review ─────────────────────────────────────────────────

it('lista só os payouts em needs_review, com performer e valores (sem PIX)', function () {
    $profile = admPerformer('Ana');

    Payout::create([
        'performer_id' => $profile->user_id,
        'tokens' => 1000,
        'amount_brl' => '600.00',
        'pix_key' => 'segredo@pix.test',
        'pix_key_type' => 'email',
        'status' => 'needs_review',
        'requested_at' => now(),
    ]);
    // Um payout pago não deve aparecer.
    Payout::create([
        'performer_id' => $profile->user_id,
        'tokens' => 50,
        'amount_brl' => '30.00',
        'pix_key' => 'x@pix.test',
        'pix_key_type' => 'email',
        'status' => 'paid',
        'requested_at' => now(),
    ]);

    $list = admMetrics()->pendingPayouts();

    expect($list)->toHaveCount(1)
        ->and($list[0]['tokens'])->toBe(1000)
        ->and($list[0]['performer'])->toContain('Ana')
        // A chave PIX NUNCA entra no shape exposto.
        ->and($list[0])->not->toHaveKey('pix_key');
});

// ─── Gate admin ──────────────────────────────────────────────────────────────

it('deixa o admin ver o painel', function () {
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->get('/admin/dashboard')
        ->assertOk()
        ->assertSee('Painel de Receita');
});

it('barra membro, performer e moderador', function () {
    foreach (['consumer', 'performer', 'moderator'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
    }
});

it('redireciona o visitante deslogado', function () {
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
});

// ─── Segurança: nenhuma PII de membro na view ────────────────────────────────

it('não expõe PII de membro no painel (só agregados)', function () {
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    // Membro com dados distintivos + atividade no ledger.
    $member = User::factory()->create([
        'role' => 'consumer',
        'name' => 'ZzMemberSecretName',
        'email' => 'zz-member-secret@example.test',
    ]);
    admLedger(admWallet($member), 'purchase', 500);
    admLedger(admWallet($member), 'spend_tip', -20);

    $html = $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->getContent();

    expect($html)->not->toContain('ZzMemberSecretName')
        ->and($html)->not->toContain('zz-member-secret@example.test')
        // O agregado aparece (500 vendidos), provando que os dados estão lá em SOMA.
        ->and($html)->toContain('500');
});
