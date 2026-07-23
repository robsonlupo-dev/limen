<?php

use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Porta WEB do envio de KYC (performer.onboarding.kyc). A porta API Sanctum já
 * é coberta por KycPhase5Test; as duas delegam ao KycSubmissionService, e estes
 * testes provam que a web se comporta como rota web: sessão + redirect + erros
 * na sessão. Helpers com prefixo onboardingKyc* (o Pest carrega tudo junto).
 */
function onboardingKycPerformer(): User
{
    // Factory de performer já aceita os documentos vigentes (documents.accepted).
    return User::factory()->performer()->create(['status' => 'pending']);
}

function onboardingKycPayload(): array
{
    return [
        'document_type' => 'rg',
        'cpf' => '529.982.247-25',
        'full_legal_name' => 'Maria Onboarding Silva',
        'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
        'document_front' => UploadedFile::fake()->create('front.jpg', 500, 'image/jpeg'),
        'selfie' => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
    ];
}

it('lets a pending performer submit KYC through the web onboarding route', function () {
    Storage::fake('kyc');

    $performer = onboardingKycPerformer();

    $this->actingAs($performer)
        ->post(route('performer.onboarding.kyc'), onboardingKycPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $verification = $performer->identityVerifications()->sole();

    expect($verification->status)->toBe('pending')
        ->and($verification->document_type)->toBe('rg')
        ->and($verification->document_number)->toBe('52998224725')
        ->and($verification->document_front_path)->not->toBeNull()
        ->and($verification->selfie_path)->not->toBeNull()
        ->and(AuditLog::where('action', 'kyc.submitted')->count())->toBe(1);
});

it('rejects a duplicate submission while a verification is active', function () {
    Storage::fake('kyc');

    $performer = onboardingKycPerformer();

    $this->actingAs($performer)->post(route('performer.onboarding.kyc'), onboardingKycPayload());

    $this->actingAs($performer)
        ->post(route('performer.onboarding.kyc'), onboardingKycPayload())
        ->assertRedirect()
        ->assertSessionHasErrors('kyc');

    expect(IdentityVerification::where('user_id', $performer->id)->count())->toBe(1);
});

it('lets a rejected performer resubmit', function () {
    Storage::fake('kyc');

    $performer = onboardingKycPerformer();
    $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Maria Onboarding Silva',
        'date_of_birth' => '1998-01-01',
        'status' => 'rejected',
    ]);

    $this->actingAs($performer)
        ->post(route('performer.onboarding.kyc'), onboardingKycPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(IdentityVerification::where('user_id', $performer->id)->pluck('status')->sort()->values()->all())
        ->toBe(['pending', 'rejected']);
});

it('validates the web submission with session errors, not JSON', function () {
    Storage::fake('kyc');

    $performer = onboardingKycPerformer();

    // Menor de 18 + arquivo com mime errado.
    $payload = onboardingKycPayload();
    $payload['date_of_birth'] = now()->subYears(17)->format('Y-m-d');
    $payload['document_front'] = UploadedFile::fake()->create('front.pdf', 500, 'application/pdf');

    $this->actingAs($performer)
        ->post(route('performer.onboarding.kyc'), $payload)
        ->assertRedirect()
        ->assertSessionHasErrors(['date_of_birth', 'document_front']);

    expect(IdentityVerification::where('user_id', $performer->id)->exists())->toBeFalse();
});

it('denies the web KYC route to consumers', function () {
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)
        ->post(route('performer.onboarding.kyc'), onboardingKycPayload())
        ->assertForbidden();
});

it('shows the rejection reason from the audit trail on the onboarding page', function () {
    $performer = onboardingKycPerformer();
    $verification = $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Maria Onboarding Silva',
        'date_of_birth' => '1998-01-01',
        'status' => 'rejected',
    ]);

    app(\App\Services\KycService::class)->reject($verification, 'Documento ilegível.', null);

    $this->actingAs($performer)
        ->get(route('performer.onboarding'))
        ->assertInertia(fn ($page) => $page
            ->component('Performer/Onboarding')
            ->where('kycStatus', 'rejected')
            ->where('kycRejectionReason', 'Documento ilegível.')
        );
});
