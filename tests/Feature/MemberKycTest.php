<?php

use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * KYC Nível 2 do MEMBRO — selfie-only (o documento fica para o Sprint 9).
 *
 * O membro nasce em `pending_kyc`, é segurado fora das áreas de membro pelo
 * EnsureMemberVerified e só vira `active` quando o admin aprova a selfie. A
 * fonte do envio é a MESMA da performer (KycSubmissionService) — aqui provamos o
 * comportamento da porta web do membro e o gate.
 *
 * Sobre o e-mail de aprovação/rejeição: SendKycApprovedEmail/RejectedEmail são
 * despachados com `->afterCommit()`, e sob RefreshDatabase a transação do teste
 * nunca dá commit — o job não chega à fila no ambiente de teste. Por isso o
 * "e-mail disparado" é provado pelo lado que roda DENTRO da transação de
 * aprovação/rejeição: o audit `kyc.approved`/`kyc.rejected` (e, na rejeição, o
 * motivo no metadata, que é exatamente o que o e-mail carrega). Mesma disciplina
 * do KycPhase5Test.
 */
function memberKycMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'pending_kyc']);
}

function memberKycSelfie(): UploadedFile
{
    return UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg');
}

function memberKycAdmin(): User
{
    return User::factory()->admin()->create();
}

// ─── Cadastro nasce pending_kyc ──────────────────────────────────────────────

it('cria o membro com status pending_kyc no cadastro', function () {
    $this->post('/cadastro', [
        'tipo' => 'membro',
        'name' => 'Novo Membro',
        'email' => 'novo.membro@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-06-15',
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('consumer.kyc.index'));

    $user = User::where('email', 'novo.membro@example.com')->firstOrFail();
    expect($user->status)->toBe('pending_kyc');
});

// ─── Gate: pending_kyc é redirecionado das áreas de membro ───────────────────

it('redireciona o membro pending_kyc para /verificacao ao acessar o painel', function () {
    $this->actingAs(memberKycMember())
        ->get(route('consumer.dashboard'))
        ->assertRedirect(route('consumer.kyc.index'));
});

it('redireciona o membro pending_kyc ao acessar a carteira e as assinaturas', function () {
    $member = memberKycMember();

    $this->actingAs($member)
        ->get(route('wallet.index'))
        ->assertRedirect(route('consumer.kyc.index'));

    $this->actingAs($member)
        ->get(route('subscribe.index'))
        ->assertRedirect(route('consumer.kyc.index'));
});

// ─── Envio da selfie ─────────────────────────────────────────────────────────

it('cria uma IdentityVerification pendente quando o membro envia a selfie', function () {
    Storage::fake('kyc');

    $member = memberKycMember();

    $this->actingAs($member)
        ->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()])
        ->assertRedirect(route('consumer.kyc.waiting'))
        ->assertSessionHasNoErrors();

    $verification = $member->identityVerifications()->sole();

    expect($verification->status)->toBe('pending')
        ->and($verification->selfie_path)->not->toBeNull()
        // Selfie-only: sem documento (estrutura reservada ao Sprint 9).
        ->and($verification->document_type)->toBeNull()
        ->and($verification->document_number)->toBeNull();
});

it('nao deixa o membro enviar a selfie duas vezes com verificacao ativa', function () {
    Storage::fake('kyc');

    $member = memberKycMember();

    $this->actingAs($member)
        ->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()])
        ->assertRedirect(route('consumer.kyc.waiting'));

    $this->actingAs($member)
        ->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()])
        ->assertRedirect()
        ->assertSessionHasErrors('selfie');

    expect(IdentityVerification::where('user_id', $member->id)->count())->toBe(1);
});

// ─── Aprovação e rejeição pelo admin ─────────────────────────────────────────

it('ativa o membro e registra a aprovacao quando o admin aprova', function () {
    Storage::fake('kyc');

    $member = memberKycMember();
    $this->actingAs($member)->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()]);
    $verification = $member->identityVerifications()->sole();

    $this->actingAs(memberKycAdmin())
        ->post(route('admin.kyc.panel.approve', $verification))
        ->assertRedirect();

    $member->refresh();
    expect($member->status)->toBe('active')
        ->and($member->age_verified_at)->not->toBeNull();

    // Fonte do e-mail de aprovação (despachado afterCommit): a linha de audit
    // roda na mesma transação e prova que o caminho de aprovação executou.
    $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.approved']);
});

it('rejeita com motivo e deixa o membro reenviar a selfie', function () {
    Storage::fake('kyc');

    $member = memberKycMember();
    $this->actingAs($member)->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()]);
    $verification = $member->identityVerifications()->sole();

    $this->actingAs(memberKycAdmin())
        ->post(route('admin.kyc.panel.reject', $verification), ['reason' => 'Selfie fora de foco'])
        ->assertRedirect();

    $verification->refresh();
    $member->refresh();

    // Rejeitado: continua pending_kyc (não é promovido) e o motivo vai no audit
    // — que é o corpo do e-mail de rejeição.
    expect($verification->status)->toBe('rejected')
        ->and($member->status)->toBe('pending_kyc');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'kyc.rejected',
        'subject_id' => $verification->id,
    ]);
    expect(
        AuditLog::where('action', 'kyc.rejected')
            ->where('subject_id', $verification->id)
            ->value('metadata')['reason'] ?? null
    )->toBe('Selfie fora de foco');

    // Com a verificação rejeitada, a tela de verificação volta a renderizar o
    // formulário (não redireciona para a sala de espera) — pode reenviar.
    $this->actingAs($member)
        ->get(route('consumer.kyc.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Consumer/Kyc/Index')->where('kycStatus', 'rejected'));

    $this->actingAs($member)
        ->post(route('consumer.kyc.submit'), ['selfie' => memberKycSelfie()])
        ->assertRedirect(route('consumer.kyc.waiting'))
        ->assertSessionHasNoErrors();
});

// ─── Membro ativo passa sem redirect ─────────────────────────────────────────

it('deixa o membro active acessar o painel sem redirect', function () {
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)
        ->get(route('consumer.dashboard'))
        ->assertOk();
});

// ─── Fila admin: badge de tipo e de blacklist ────────────────────────────────

it('mostra o badge de Membro e o alerta de blacklist na fila admin', function () {
    $member = memberKycMember();
    $member->forceFill(['blacklist_hit' => true])->save();
    $member->identityVerifications()->create([
        'selfie_path' => 'kyc/x/selfie.jpg.enc',
        'provider' => 'fake',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);

    $this->actingAs(memberKycAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk()
        ->assertSee('Membro')
        ->assertSee('CPF banido anteriormente');
});

// ─── O gate não afeta performer nem admin ────────────────────────────────────

it('nao redireciona performer nem admin para o KYC de membro', function () {
    // A performer/admin batem no role:consumer ANTES do member.verified: são
    // barrados por papel (403), nunca desviados para a tela de selfie do membro.
    // É o que prova que o EnsureMemberVerified só age sobre o consumer.
    $performer = User::factory()->performer()->create(['status' => 'pending']);
    $this->actingAs($performer)
        ->get(route('consumer.dashboard'))
        ->assertForbidden();

    $this->actingAs(memberKycAdmin())
        ->get(route('consumer.dashboard'))
        ->assertForbidden();

    // E o admin usa a própria área normalmente, sem qualquer desvio.
    $this->actingAs(memberKycAdmin())
        ->get(route('admin.kyc.panel'))
        ->assertOk();
});
