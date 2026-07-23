<?php

use App\Models\AgeVerification;
use App\Models\FraudBlacklist;
use App\Models\User;
use App\Services\AuthService;
use App\Support\CpfHash;
use App\Support\DocumentHash;
use Illuminate\Support\Str;

/**
 * Lista negra antifraude. Cobre a gravação no ban (hash, nunca PII crua), a
 * detecção no cadastro (sinal, não bloqueio) e o alerta na fila de KYC.
 * Helpers com prefixo fb* para o arquivo rodar isolado.
 */
const FB_CPF = '52998224725';        // válido
const FB_CPF_LIMPO = '11144477735';  // válido, nunca banido

function fbAdmin(): User
{
    return User::factory()->admin()->create();
}

/** Performer com KYC aprovado — document_number guarda o CPF (cast encrypted). */
function fbBannablePerformer(string $cpf = FB_CPF): User
{
    $user = User::factory()->performer()->create(['status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'FB '.Str::random(4),
        'slug' => 'fb-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
    $user->identityVerifications()->create([
        'document_type' => 'cpf',
        'document_number' => $cpf,
        'full_legal_name' => 'Fulana Teste',
        'date_of_birth' => '1995-01-01',
        'provider' => 'manual',
        'provider_status' => 'approved',
        'status' => 'approved',
    ]);

    return $user;
}

/** Membro cujo CPF já morreu em HMAC no age_verifications (sem KYC). */
function fbBannableMember(string $cpf = FB_CPF): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    AgeVerification::create([
        'user_id' => $user->id,
        'method' => AgeVerification::METHOD_CPF_DOB,
        'cpf_hmac' => CpfHash::make($cpf),
        'verified_at' => now(),
    ]);

    return $user;
}

// ─── Gravação no ban ─────────────────────────────────────────────────────────

it('records a blacklist entry with cpf_hash AND document_hash when banning a performer with KYC', function () {
    $admin = fbAdmin();
    $performer = fbBannablePerformer();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $performer), ['reason' => 'Conteúdo proibido.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $entry = FraudBlacklist::sole();

    expect($entry->cpf_hash)->toBe(CpfHash::make(FB_CPF))
        ->and($entry->document_hash)->toBe(DocumentHash::make(FB_CPF))
        ->and($entry->banned_user_id)->toBe($performer->id)
        ->and($entry->banned_by)->toBe($admin->id)
        ->and($entry->reason)->toBe('Conteúdo proibido.')
        // Nunca a PII crua.
        ->and($entry->cpf_hash)->not->toContain(FB_CPF);
});

it('records the hash when banning a performer whose KYC is still pending', function () {
    // O caso mais comum: conteúdo proibido pego na fila, KYC ainda pending.
    // O performer não tem age_verification — se o hash não viesse do KYC
    // pending, nada seria gravado e a lista falharia justamente aqui.
    $admin = fbAdmin();
    $performer = User::factory()->performer()->create(['status' => 'active']);
    $performer->performerProfile()->create([
        'stage_name' => 'Pending Perf',
        'slug' => 'pend-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);
    $performer->identityVerifications()->create([
        'document_type' => 'cpf',
        'document_number' => FB_CPF,
        'full_legal_name' => 'Nome',
        'date_of_birth' => '1995-01-01',
        'provider' => 'manual',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $performer), ['reason' => 'Conteúdo proibido na revisão.']);

    $entry = FraudBlacklist::sole();
    expect($entry->cpf_hash)->toBe(CpfHash::make(FB_CPF))
        ->and($entry->document_hash)->toBe(DocumentHash::make(FB_CPF));
});

it('records only cpf_hash when banning a member without KYC', function () {
    $admin = fbAdmin();
    $member = fbBannableMember();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $member), ['reason' => 'Fraude.']);

    $entry = FraudBlacklist::sole();

    expect($entry->cpf_hash)->toBe(CpfHash::make(FB_CPF))
        ->and($entry->document_hash)->toBeNull()
        ->and($entry->banned_user_id)->toBe($member->id);
});

it('creates no entry when banning a user with no verification at all', function () {
    $admin = fbAdmin();
    $bare = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $bare), ['reason' => 'Sem verificação.'])
        ->assertSessionHas('success');

    expect(FraudBlacklist::count())->toBe(0)
        ->and($bare->fresh()->status)->toBe('banned');
});

it('is idempotent on cpf_hash across two banned accounts of the same person', function () {
    $admin = fbAdmin();

    $this->actingAs($admin)->post(route('admin.users.ban', fbBannableMember()), ['reason' => 'A']);
    // Segunda conta, MESMO CPF → não estoura o unique, não duplica.
    $this->actingAs($admin)->post(route('admin.users.ban', fbBannableMember()), ['reason' => 'B']);

    expect(FraudBlacklist::where('cpf_hash', CpfHash::make(FB_CPF))->count())->toBe(1);
});

// ─── hasCpfHash / hasDocumentHash ────────────────────────────────────────────

it('hasCpfHash and hasDocumentHash report membership correctly', function () {
    $admin = fbAdmin();
    FraudBlacklist::addEntry(fbBannablePerformer(), CpfHash::make(FB_CPF), DocumentHash::make(FB_CPF), $admin->id, 'x');

    expect(FraudBlacklist::hasCpfHash(CpfHash::make(FB_CPF)))->toBeTrue()
        ->and(FraudBlacklist::hasCpfHash(CpfHash::make(FB_CPF_LIMPO)))->toBeFalse()
        ->and(FraudBlacklist::hasDocumentHash(DocumentHash::make(FB_CPF)))->toBeTrue()
        ->and(FraudBlacklist::hasDocumentHash(DocumentHash::make(FB_CPF_LIMPO)))->toBeFalse();
});

// ─── Detecção no cadastro de membro ──────────────────────────────────────────

function fbRegisterMember(string $cpf, string $email): User
{
    return app(AuthService::class)->registerConsumer([
        'name' => 'Novo Membro',
        'email' => $email,
        'password' => 'Senha123',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'terms_version' => '1.0',
        'cpf' => $cpf,
    ]);
}

it('flags a new member registering with a blacklisted CPF', function () {
    $admin = fbAdmin();
    FraudBlacklist::addEntry(fbBannableMember(), CpfHash::make(FB_CPF), null, $admin->id, 'banido');

    // CPF mascarado deve casar com o digest do não-mascarado (normalização).
    $newUser = fbRegisterMember('529.982.247-25', 'novo@fb.test');

    expect($newUser->blacklist_hit)->toBeTrue();
});

it('does not flag a new member with a clean CPF', function () {
    $admin = fbAdmin();
    FraudBlacklist::addEntry(fbBannableMember(), CpfHash::make(FB_CPF), null, $admin->id, 'banido');

    $newUser = fbRegisterMember(FB_CPF_LIMPO, 'limpo@fb.test');

    expect($newUser->blacklist_hit)->toBeFalse();
});

// ─── Alerta na fila de KYC ───────────────────────────────────────────────────

it('shows the blacklist badge in the KYC queue for a flagged performer', function () {
    $performer = User::factory()->performer()->create(['status' => 'pending']);
    $performer->forceFill(['blacklist_hit' => true])->save();
    $performer->performerProfile()->create([
        'stage_name' => 'Flagged Perf',
        'slug' => 'flagged-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);
    $performer->identityVerifications()->create([
        'document_type' => 'cpf',
        'document_number' => FB_CPF,
        'full_legal_name' => 'Nome',
        'date_of_birth' => '1995-01-01',
        'provider' => 'manual',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);

    $this->actingAs(fbAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk()
        ->assertSee('CPF banido anteriormente');
});

it('shows no blacklist badge for a performer that is not flagged', function () {
    $performer = User::factory()->performer()->create(['status' => 'pending']);
    $performer->performerProfile()->create([
        'stage_name' => 'Clean Perf',
        'slug' => 'clean-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);
    $performer->identityVerifications()->create([
        'document_type' => 'cpf',
        'document_number' => FB_CPF_LIMPO,
        'full_legal_name' => 'Nome',
        'date_of_birth' => '1995-01-01',
        'provider' => 'manual',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);

    $this->actingAs(fbAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk()
        ->assertDontSee('CPF banido anteriormente');
});

// ─── Mass assignment ─────────────────────────────────────────────────────────

it('never accepts blacklist_hit through mass assignment on User', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $user->fill(['blacklist_hit' => true, 'name' => 'Outro']);

    // fill() ignora blacklist_hit (fora do $fillable): não vira true. E no banco
    // segue o default false.
    expect($user->blacklist_hit)->not->toBeTrue()
        ->and($user->name)->toBe('Outro')
        ->and($user->getFillable())->not->toContain('blacklist_hit')
        ->and($user->fresh()->blacklist_hit)->toBeFalse();
});

it('keeps FraudBlacklist fillable empty (writes only via dedicated methods)', function () {
    expect((new FraudBlacklist)->getFillable())->toBe([]);
});
