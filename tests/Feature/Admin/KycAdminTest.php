<?php

use App\Jobs\SendKycApprovedEmail;
use App\Jobs\SendKycRejectedEmail;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Painel web admin de KYC (fila de aprovação). A mutação delega a KycService —
 * a mesma fonte do webhook Didit e da API admin — então o que se testa aqui é
 * a PORTA: allowlist do filtro, guard de status, validação do motivo, 403 de
 * não-admin e, principalmente, que a PII do documento nunca chega à view.
 * Helpers com prefixo kycAdm* para o arquivo rodar isolado.
 */
function kycAdmAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

function kycAdmVerification(string $status = 'pending'): IdentityVerification
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'kycadm-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    return $user->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Nome Legal Sigiloso',
        'date_of_birth' => '1998-01-01',
        'provider' => 'manual',
        'provider_status' => $status,
        'status' => $status,
    ]);
}

it('shows the queue of pending and review verifications to the admin', function () {
    $pending = kycAdmVerification('pending');
    $review = kycAdmVerification('review');
    $approved = kycAdmVerification('approved');
    $rejected = kycAdmVerification('rejected');

    $response = $this->actingAs(kycAdmAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk();

    $ids = collect($response->viewData('verifications')->items())->pluck('id');

    expect($ids)->toContain($pending->id)
        ->toContain($review->id)
        ->not->toContain($approved->id)
        ->not->toContain($rejected->id);

    $response->assertSee($pending->user->performerProfile->stage_name);
});

it('falls back to the queue on a status outside the allowlist', function () {
    $pending = kycAdmVerification('pending');

    $response = $this->actingAs(kycAdmAdmin())
        ->get(route('admin.kyc.panel', ['status' => "all' OR 1=1"]))
        ->assertOk();

    expect($response->viewData('status'))->toBe('queue')
        ->and(collect($response->viewData('verifications')->items())->pluck('id'))
        ->toContain($pending->id);
});

it('approves a verification: performer goes live, audit written, email queued', function (string $status) {
    Queue::fake();

    $admin = kycAdmAdmin();
    $verification = kycAdmVerification($status);
    $performer = $verification->user;

    $this->actingAs($admin)
        ->post(route('admin.kyc.panel.approve', $verification))
        ->assertRedirect()
        ->assertSessionHas('success');

    $verification->refresh();
    $performer->refresh();

    expect($verification->status)->toBe('approved')
        ->and($verification->reviewed_by)->toBe($admin->id)
        ->and($verification->reviewed_at)->not->toBeNull()
        ->and($verification->age_confirmed)->toBeTrue()
        ->and($performer->status)->toBe('active')
        ->and($performer->age_verified_at)->not->toBeNull()
        ->and($performer->performerProfile->is_verified)->toBeTrue();

    $log = AuditLog::where('action', 'kyc.approved')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($verification->id)
        ->and($log->metadata['reviewed_by'])->toBe($admin->id);

    Queue::assertPushed(SendKycApprovedEmail::class, fn ($job) => $job->user->is($performer));
})->with(['pending', 'review']);

it('rejects a verification with a reason: audit carries it, email queued', function (string $status) {
    Queue::fake();

    $admin = kycAdmAdmin();
    $verification = kycAdmVerification($status);

    $this->actingAs($admin)
        ->post(route('admin.kyc.panel.reject', $verification), ['reason' => 'Documento ilegível na frente.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $verification->refresh();

    expect($verification->status)->toBe('rejected')
        ->and($verification->reviewed_by)->toBe($admin->id)
        ->and($verification->reviewed_at)->not->toBeNull()
        // Rejeição NÃO ativa a conta nem verifica o perfil.
        ->and($verification->user->status)->toBe('pending')
        ->and($verification->user->performerProfile->is_verified)->toBeFalse();

    $log = AuditLog::where('action', 'kyc.rejected')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata['reason'])->toBe('Documento ilegível na frente.')
        ->and($log->metadata['reviewed_by'])->toBe($admin->id);

    Queue::assertPushed(
        SendKycRejectedEmail::class,
        fn ($job) => $job->reason === 'Documento ilegível na frente.'
    );
})->with(['pending', 'review']);

it('denies the panel and the actions to non-admins', function () {
    $verification = kycAdmVerification();
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)->get(route('admin.kyc.panel'))->assertForbidden();
    $this->actingAs($member)->post(route('admin.kyc.panel.approve', $verification))->assertForbidden();

    // A própria performer não se autoaprova.
    $this->actingAs($verification->user)
        ->post(route('admin.kyc.panel.approve', $verification))
        ->assertForbidden();

    expect($verification->fresh()->status)->toBe('pending');
});

it('requires a reason to reject', function () {
    Queue::fake();

    $verification = kycAdmVerification();

    $this->actingAs(kycAdmAdmin())
        ->from(route('admin.kyc.panel'))
        ->post(route('admin.kyc.panel.reject', $verification), [])
        ->assertRedirect(route('admin.kyc.panel'))
        ->assertSessionHasErrors('reason');

    expect($verification->fresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
});

it('short-circuits approving an already approved verification', function () {
    Queue::fake();

    $admin = kycAdmAdmin();
    $otherAdmin = kycAdmAdmin();
    $verification = kycAdmVerification('approved');
    $verification->forceFill([
        'reviewed_by' => $otherAdmin->id,
        'reviewed_at' => now()->subDay()->startOfSecond(),
    ])->save();

    $originalReviewedAt = $verification->fresh()->reviewed_at;

    $this->actingAs($admin)
        ->post(route('admin.kyc.panel.approve', $verification))
        ->assertRedirect()
        ->assertSessionHas('info')
        ->assertSessionMissing('success');

    $verification->refresh();

    // Nada regravado: decisão original (e sua trilha) fica de pé.
    expect($verification->reviewed_by)->toBe($otherAdmin->id)
        ->and($verification->reviewed_at->equalTo($originalReviewedAt))->toBeTrue()
        ->and(AuditLog::where('action', 'kyc.approved')->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('never sends document PII to the index view', function () {
    kycAdmVerification();

    $response = $this->actingAs(kycAdmAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk();

    // Nem no HTML…
    $response->assertDontSee('52998224725')
        ->assertDontSee('Nome Legal Sigiloso');

    // …nem nas chaves que a view recebe (o HTML de hoje não usa não é garantia
    // para o de amanhã).
    foreach ($response->viewData('verifications')->items() as $row) {
        expect($row)->not->toHaveKeys([
            'document_number',
            'full_legal_name',
            'date_of_birth',
            'document_front_path',
            'document_back_path',
            'selfie_path',
        ]);
    }
});
