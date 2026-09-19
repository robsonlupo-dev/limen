<?php

use App\Models\Report;
use App\Models\User;
use App\Models\PerformerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Página inicial da moderação (feat/moderator-overview): resumo das três filas.
 * Cobre o gate (moderador+admin entram; membro/performer não) e as contagens.
 * Helpers com prefixo ovw* para o arquivo rodar isolado.
 */
function ovwModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function ovwReport(): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $profileUser = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $profileUser->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    return Report::open($reporter, $profile, 'spam', 'conteúdo suspeito');
}

it('lets a moderator open the overview with the three queue counts', function () {
    $this->actingAs(ovwModerator())
        ->get(route('moderacao.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Overview')
            ->has('queues', fn (Assert $q) => $q
                ->where('reports', 0)
                ->where('member_photos', 0)
                ->where('voice_intros', 0)));
});

it('lets an admin open the overview', function () {
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->get(route('moderacao.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Moderacao/Overview'));
});

it('counts pending reports in the overview', function () {
    ovwReport();
    ovwReport();

    $this->actingAs(ovwModerator())
        ->get(route('moderacao.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('queues.reports', 2));
});

it('forbids a consumer and a performer from the overview', function () {
    foreach (['consumer', 'performer'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->get(route('moderacao.overview'))->assertForbidden();
    }
});

it('redirects a logged-out visitor to login', function () {
    $this->get(route('moderacao.overview'))->assertRedirect(route('login'));
});
