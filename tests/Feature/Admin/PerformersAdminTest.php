<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Gestão de performers no back-office admin (feat/admin-performers). Cobre o
 * gate admin-only, a listagem/filtro/busca e a garantia de que NENHUMA PII de
 * membro vaza nesta tela (só se lê role=performer). As AÇÕES (tier/ban) têm
 * cobertura própria em PerformerTierTest/UserBanTest — aqui é a fila/leitura.
 * Helpers com prefixo perf* para o arquivo rodar isolado.
 */
function perfAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

function perfWithProfile(string $stageName, array $userOverrides = [], array $profileOverrides = []): User
{
    $user = User::factory()->create(array_merge(['role' => 'performer', 'status' => 'active'], $userOverrides));

    $user->performerProfile()->create(array_merge([
        'stage_name' => $stageName,
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileOverrides));

    return $user;
}

// ─── Gate admin-only ─────────────────────────────────────────────────────────

it('lets an admin see the performers panel', function () {
    $this->actingAs(perfAdmin())->get('/admin/performers')
        ->assertOk()
        ->assertSee('Performers');
});

it('forbids consumer, performer and moderator', function () {
    foreach (['consumer', 'performer', 'moderator'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->get('/admin/performers')->assertForbidden();
    }
});

it('redirects a logged-out visitor to login', function () {
    $this->get('/admin/performers')->assertRedirect(route('login'));
});

// ─── Listagem ────────────────────────────────────────────────────────────────

it('lists performers by stage name', function () {
    perfWithProfile('AuroraStageName');

    $this->actingAs(perfAdmin())->get('/admin/performers')
        ->assertOk()
        ->assertSee('AuroraStageName');
});

it('shows performers without a profile with a fallback label', function () {
    User::factory()->create(['role' => 'performer', 'status' => 'active']); // sem perfil

    $this->actingAs(perfAdmin())->get('/admin/performers')
        ->assertOk()
        ->assertSee('sem perfil');
});

// ─── Filtro por status ───────────────────────────────────────────────────────

it('filters by account status', function () {
    perfWithProfile('ActiveOne', ['status' => 'active']);
    perfWithProfile('BannedOne', ['status' => 'banned']);

    $html = $this->actingAs(perfAdmin())->get('/admin/performers?status=banned')
        ->assertOk()->getContent();

    expect($html)->toContain('BannedOne')->not->toContain('ActiveOne');
});

// ─── Busca por stage name ────────────────────────────────────────────────────

it('searches by stage name', function () {
    perfWithProfile('ZebraUnique');
    perfWithProfile('LlamaUnique');

    $html = $this->actingAs(perfAdmin())->get('/admin/performers?q=Zebra')
        ->assertOk()->getContent();

    expect($html)->toContain('ZebraUnique')->not->toContain('LlamaUnique');
});

// ─── Filtro de verificação ───────────────────────────────────────────────────

it('filters by verification state', function () {
    perfWithProfile('VerifiedOne', [], ['is_verified' => true]);
    perfWithProfile('UnverifiedOne', [], ['is_verified' => false]);

    $verified = $this->actingAs(perfAdmin())->get('/admin/performers?verified=yes')
        ->assertOk()->getContent();
    expect($verified)->toContain('VerifiedOne')->not->toContain('UnverifiedOne');

    $unverified = $this->actingAs(perfAdmin())->get('/admin/performers?verified=no')
        ->assertOk()->getContent();
    expect($unverified)->toContain('UnverifiedOne')->not->toContain('VerifiedOne');
});

// ─── Segurança: nenhuma PII de membro ────────────────────────────────────────

it('never exposes member PII on the performers panel', function () {
    perfWithProfile('LegitPerformer');

    // Membro com dados distintivos — NÃO pode aparecer nesta superfície.
    User::factory()->create([
        'role' => 'consumer',
        'name' => 'ZzMemberSecretName',
        'email' => 'zz-member-secret@example.test',
    ]);

    $html = $this->actingAs(perfAdmin())->get('/admin/performers')
        ->assertOk()->getContent();

    expect($html)
        ->not->toContain('ZzMemberSecretName')
        ->and($html)->not->toContain('zz-member-secret@example.test')
        ->and($html)->toContain('LegitPerformer');
});

// ─── Não vaza e-mail da própria performer (back-office exposto) ───────────────

it('does not print performer email addresses in the list', function () {
    perfWithProfile('EmailHiddenPerf', ['email' => 'perf-email-hidden@example.test']);

    $html = $this->actingAs(perfAdmin())->get('/admin/performers')
        ->assertOk()->getContent();

    expect($html)->not->toContain('perf-email-hidden@example.test');
});
