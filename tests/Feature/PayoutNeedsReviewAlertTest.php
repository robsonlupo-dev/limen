<?php

use App\Mail\PayoutNeedsReviewMail;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\PayoutService;
use App\Services\TokenService;
use Illuminate\Support\Facades\Mail;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────
// Locais e com nomes próprios para o arquivo rodar isolado (as fábricas de
// usuário compartilhadas vivem em outros arquivos de teste e só carregam na
// suíte inteira).

/** Performer ativo e financiado, pronto para ter payouts criados na mão. */
function alertPerformer(int $funded = 1000): User
{
    $performer = User::factory()->performer()->create(['status' => 'active']);
    $performer->performerProfile()->create([
        'stage_name' => 'Payout Alert Performer',
        'slug' => 'payout-alert-' . strtolower(Illuminate\Support\Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    if ($funded > 0) {
        app(TokenService::class)->credit($performer, $funded, 'purchase');
    }

    return $performer;
}

/** Cria um payout já em needs_review, com a reserva de tokens debitada. */
function needsReviewPayout(User $performer, array $overrides = []): Payout
{
    $payout = Payout::create(array_merge([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'needs_review',
        'requested_at' => now()->subHours(3),
        'unresolved_since' => null,
    ], $overrides));

    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    return $payout;
}

// ─── Tarefa 1: alerta por email ──────────────────────────────────────────────

it('enfileira o email de alerta quando um payout vai para needs_review', function () {
    Mail::fake();
    config(['mail.admin_address' => 'admin@thelimen.com.br']);

    $performer = alertPerformer();

    // Payout ambíguo sem transfer id, com 3h de buscas vazias: o reconcile o estaciona.
    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'requested_at' => now()->subHours(3),
        'unresolved_since' => now()->subHours(3),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(PayoutService::class)->reconcile();

    expect($payout->fresh()->status)->toBe('needs_review');
    Mail::assertQueued(PayoutNeedsReviewMail::class, fn ($mail) => $mail->payout->id === $payout->id
        && $mail->hasTo('admin@thelimen.com.br'));
});

it('NAO enfileira o email se o payout ja foi settled (webhook chegou antes)', function () {
    Mail::fake();
    config(['mail.admin_address' => 'admin@thelimen.com.br']);

    $performer = alertPerformer();

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $transfer = $fake->createTransfer(['value' => 64.35, 'external_reference' => 'payout_settled']);
    $fake->simulateTransferPaid($transfer['id']); // status DONE: o lookup do reconcile resolve como paid

    $payout = Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '64.35',
        'pix_key' => 'performer@example.com',
        'pix_key_type' => 'email',
        'status' => 'processing',
        'asaas_transfer_id' => $transfer['id'],
        'requested_at' => now()->subHours(3),
        'unresolved_since' => now()->subHours(3),
    ]);
    app(TokenService::class)->debit($performer, 1000, 'payout_reserve', 'payout', $payout->id, 'reserva');

    app(PayoutService::class)->reconcile();

    expect($payout->fresh()->status)->toBe('paid');
    Mail::assertNothingQueued();
});

// ─── Tarefa 2: requeue pelo admin ────────────────────────────────────────────

it('requeue muda o status para processing e zera unresolved_since', function () {
    $performer = alertPerformer();
    $payout = needsReviewPayout($performer, ['unresolved_since' => now()->subHours(3)]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/payouts/{$payout->id}/requeue")
        ->assertOk()
        ->assertJson(['message' => 'Payout recolocado na fila de reconciliação']);

    $payout->refresh();
    expect($payout->status)->toBe('processing');
    expect($payout->unresolved_since)->toBeNull();
});

it('requeue retorna 422 se o payout nao esta em needs_review', function () {
    $performer = alertPerformer();
    $payout = needsReviewPayout($performer, ['status' => 'processing']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/payouts/{$payout->id}/requeue")
        ->assertStatus(422);

    expect($payout->fresh()->status)->toBe('processing');
});

it('requeue retorna 404 para payout inexistente', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/payouts/999999/requeue')
        ->assertNotFound();
});

it('requeue exige role admin — 403 para performer e consumer', function () {
    $performer = alertPerformer();
    $payout = needsReviewPayout($performer);

    $this->actingAs($performer, 'sanctum')
        ->postJson("/api/v1/admin/payouts/{$payout->id}/requeue")
        ->assertForbidden();

    $consumer = User::factory()->create(); // role consumer por padrão
    $this->actingAs($consumer, 'sanctum')
        ->postJson("/api/v1/admin/payouts/{$payout->id}/requeue")
        ->assertForbidden();

    expect($payout->fresh()->status)->toBe('needs_review');
});

it('requeue registra o audit log payout.requeued com requeued_by correto', function () {
    $performer = alertPerformer();
    $payout = needsReviewPayout($performer);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/payouts/{$payout->id}/requeue")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payout.requeued',
        'subject_type' => (new Payout)->getMorphClass(),
        'subject_id' => $payout->id,
    ]);

    $log = App\Models\AuditLog::where('action', 'payout.requeued')->latest()->first();
    expect($log->metadata['requeued_by'])->toBe($admin->id);

    // Requeue não move token: a reserva continua de pé, sem estorno.
    expect(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
});
