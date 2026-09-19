<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Segurança por ID do membro (feat/admin-members). Cobre o gate admin-only, o
 * anonimato por padrão (nenhuma PII sem revelação), o reveal auditado
 * ("quebrar o vidro"), e suspender/reativar. Helpers com prefixo mem*.
 */
function memAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

function memMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'name' => 'ZzSecretMemberName',
        'email' => 'zz-secret-member@example.test',
    ], $overrides));
}

// ─── Gate ────────────────────────────────────────────────────────────────────

it('lets an admin open the members panel', function () {
    $this->actingAs(memAdmin())->get('/admin/membros')->assertOk()->assertSee('segurança por ID');
});

it('forbids consumer, performer and moderator', function () {
    foreach (['consumer', 'performer', 'moderator'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->get('/admin/membros')->assertForbidden();
    }
});

it('redirects a logged-out visitor to login', function () {
    $this->get('/admin/membros')->assertRedirect(route('login'));
});

// ─── Anonimato por padrão ────────────────────────────────────────────────────

it('finds a member by id but shows NO PII by default', function () {
    $member = memMember();

    $html = $this->actingAs(memAdmin())->get('/admin/membros?id='.$member->id)
        ->assertOk()
        ->assertSee('#'.$member->id)   // identificador por ID aparece
        ->getContent();

    // Nome e e-mail NÃO aparecem sem revelação.
    expect($html)->not->toContain('ZzSecretMemberName')
        ->and($html)->not->toContain('zz-secret-member@example.test');
});

it('does not resolve a performer or admin id through the members panel', function () {
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $this->actingAs(memAdmin())->get('/admin/membros?id='.$performer->id)
        ->assertOk()
        ->assertSee('Nenhum membro');
});

it('rejects non-numeric lookups', function () {
    $this->actingAs(memAdmin())->get('/admin/membros?id=abc')
        ->assertOk()
        ->assertSee('Nenhum membro');
});

// ─── Reveal (break the glass) ────────────────────────────────────────────────

it('keeps identity reveal OFF by default (dark launch flag)', function () {
    $member = memMember();

    $this->actingAs(memAdmin())
        ->post(route('admin.members.reveal', $member->id), ['reason' => 'x'])
        ->assertForbidden();

    expect(AuditLog::where('action', 'member.identity_revealed')->count())->toBe(0);
});

it('reveals identity only with a reason and writes an audit entry', function () {
    config()->set('features.member_identity_reveal', true);
    $admin = memAdmin();
    $member = memMember();

    $res = $this->actingAs($admin)->followingRedirects()
        ->post(route('admin.members.reveal', $member->id), ['reason' => 'Ordem judicial 123/2026'])
        ->assertOk();

    // A identidade aparece UMA vez após a revelação.
    expect($res->getContent())->toContain('ZzSecretMemberName')
        ->and($res->getContent())->toContain('zz-secret-member@example.test');

    // Auditoria registrada com quem/por quê.
    $log = AuditLog::where('action', 'member.identity_revealed')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($member->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata['reason'] ?? null)->toBe('Ordem judicial 123/2026');
});

it('requires a reason to reveal identity', function () {
    config()->set('features.member_identity_reveal', true);
    $member = memMember();

    $this->actingAs(memAdmin())
        ->post(route('admin.members.reveal', $member->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(AuditLog::where('action', 'member.identity_revealed')->count())->toBe(0);
});

it('does not reveal a performer through the members panel', function () {
    config()->set('features.member_identity_reveal', true);
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $this->actingAs(memAdmin())
        ->post(route('admin.members.reveal', $performer->id), ['reason' => 'x'])
        ->assertForbidden();
});

// ─── Suspender / reativar ────────────────────────────────────────────────────

it('suspends a member with a reason and audits it', function () {
    $admin = memAdmin();
    $member = memMember();

    $this->actingAs($admin)
        ->post(route('admin.members.suspend', $member->id), ['reason' => 'Abuso reportado'])
        ->assertRedirect(route('admin.members', ['id' => $member->id]))
        ->assertSessionHas('success');

    expect($member->fresh()->status)->toBe('suspended');
    expect(AuditLog::where('action', 'member.suspended')->where('subject_id', $member->id)->exists())->toBeTrue();
});

it('requires a reason to suspend', function () {
    $member = memMember();

    $this->actingAs(memAdmin())
        ->post(route('admin.members.suspend', $member->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($member->fresh()->status)->toBe('active');
});

it('reactivates a suspended member', function () {
    $admin = memAdmin();
    $member = memMember(['status' => 'suspended']);

    $this->actingAs($admin)
        ->post(route('admin.members.reactivate', $member->id))
        ->assertRedirect(route('admin.members', ['id' => $member->id]));

    expect($member->fresh()->status)->toBe('active');
});

it('does not reactivate a banned member', function () {
    $member = memMember(['status' => 'banned']);

    $this->actingAs(memAdmin())
        ->post(route('admin.members.reactivate', $member->id))
        ->assertSessionHas('info');

    expect($member->fresh()->status)->toBe('banned');
});

it('forbids suspending an admin or a performer through this panel', function () {
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs(memAdmin())->post(route('admin.members.suspend', $performer->id), ['reason' => 'x'])->assertForbidden();
    $this->actingAs(memAdmin())->post(route('admin.members.suspend', $otherAdmin->id), ['reason' => 'x'])->assertForbidden();
});

it('denies the members panel actions to non-admins', function () {
    $member = memMember();
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)->post(route('admin.members.suspend', $member->id), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($consumer)->post(route('admin.members.reveal', $member->id), ['reason' => 'x'])->assertForbidden();
});
