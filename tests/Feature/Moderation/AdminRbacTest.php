<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Matriz de RBAC das duas portas (fix/rbac-admin-e-moderador):
 *
 *  - `/moderacao/*`  (moderator.access → canModerate): moderator OU admin.
 *  - área admin-only (admin.access → isAdmin): dinheiro, tier, KYC, waitlist.
 *
 * admin ⊇ moderator: o admin alcança as DUAS; o moderador SÓ a de moderação.
 * consumer/performer não alcançam nenhuma; guest é redirecionado ao login.
 *
 * O ModeratorRoleTest cobre a fila de moderação em detalhe; aqui o foco é a
 * FRONTEIRA admin-only (o gate que faltava ter um dono nomeado) e os atalhos de
 * papel do User. Helpers locais com prefixo rbac* para rodar isolado.
 */
function rbacUser(string $role): User
{
    return User::factory()->create(['role' => $role, 'status' => 'active', 'email_verified_at' => now()]);
}

function rbacPerformer(): User
{
    $user = rbacUser('performer');
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'rbac-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    return $user;
}

// Rotas GET que representam cada porta. As admin-only cobrem os três domínios
// que o moderador NÃO pode ver: financeiro (dashboard), KYC e waitlist.
const RBAC_MODERATION_ROUTE = 'moderacao.reports.index';
const RBAC_ADMIN_ROUTES = ['admin.dashboard', 'admin.kyc.panel', 'admin.waitlist'];

// ─── /moderacao/* — moderator E admin entram ─────────────────────────────────

it('lets both moderator and admin reach the moderation queue', function (string $role) {
    $this->actingAs(rbacUser($role))
        ->get(route(RBAC_MODERATION_ROUTE))
        ->assertOk();
})->with(['moderator', 'admin']);

it('denies the moderation queue to consumer and performer', function () {
    $this->actingAs(rbacUser('consumer'))->get(route(RBAC_MODERATION_ROUTE))->assertForbidden();
    $this->actingAs(rbacPerformer())->get(route(RBAC_MODERATION_ROUTE))->assertForbidden();
});

it('redirects a guest from the moderation queue to login', function () {
    $this->get(route(RBAC_MODERATION_ROUTE))->assertRedirect(route('login'));
});

// ─── Área admin-only — SÓ admin ──────────────────────────────────────────────

it('lets an admin reach every admin-only screen', function (string $name) {
    $this->actingAs(rbacUser('admin'))
        ->get(route($name))
        ->assertOk();
})->with(RBAC_ADMIN_ROUTES);

it('denies every admin-only screen to a moderator', function (string $name) {
    $this->actingAs(rbacUser('moderator'))
        ->get(route($name))
        ->assertForbidden();
})->with(RBAC_ADMIN_ROUTES);

it('denies every admin-only screen to consumer and performer', function (string $name) {
    $this->actingAs(rbacUser('consumer'))->get(route($name))->assertForbidden();
    $this->actingAs(rbacPerformer())->get(route($name))->assertForbidden();
})->with(RBAC_ADMIN_ROUTES);

it('redirects a guest from an admin-only screen to login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

// ─── Fronteira: tier (dinheiro/curadoria) é admin-only, negado ao moderador ───

it('denies granting a performer tier to a moderator', function () {
    // Alvo EXISTENTE: SubstituteBindings roda antes do gate; um id inexistente
    // levaria 404 do binding e passaria sem exercitar o admin.access (armadilha
    // registrada no CLAUDE.md).
    $profile = PerformerProfile::whereNotNull('id')->first()
        ?? rbacPerformer()->performerProfile;

    $this->actingAs(rbacUser('moderator'))
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'verificada'])
        ->assertForbidden();
});

it('lets an admin reach the tier grant endpoint (past the gate)', function () {
    $profile = rbacPerformer()->performerProfile;

    // O admin passa pelo gate: o que volta é a resposta do controller (redirect/
    // validação), NUNCA 403. É a prova de que admin.access liberou.
    $this->actingAs(rbacUser('admin'))
        ->post(route('admin.performers.tier.store', $profile), ['tier' => 'verificada'])
        ->assertStatus(302);
});

// ─── Atalhos de papel do User (fonte única, sem string mágica) ───────────────

it('exposes role helpers that follow admin ⊇ moderator', function () {
    expect(rbacUser('admin')->isAdmin())->toBeTrue()
        ->and(rbacUser('admin')->isModerator())->toBeFalse()
        ->and(rbacUser('admin')->canModerate())->toBeTrue()
        ->and(rbacUser('moderator')->isAdmin())->toBeFalse()
        ->and(rbacUser('moderator')->isModerator())->toBeTrue()
        ->and(rbacUser('moderator')->canModerate())->toBeTrue()
        ->and(rbacUser('consumer')->canModerate())->toBeFalse()
        ->and(rbacUser('consumer')->isAdmin())->toBeFalse();
});
