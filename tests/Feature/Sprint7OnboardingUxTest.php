<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Sprint 7 — Onboarding UX: PerformerOnboardingWizard, KycGate e
 * KycPendingBanner + empty states.
 *
 * Não há runner de testes JS no projeto; os componentes são cobertos em duas
 * camadas, seguindo o padrão da suíte:
 *  1. HTTP/Inertia — as rotas, props e gates que selecionam cada estado do
 *     componente (é o contrato servidor↔componente);
 *  2. Fonte dos .vue — trava o copy definido pelo PO nesta sprint (banner,
 *     empty states). Mudar o texto é decisão de produto, e o teste força a
 *     mudança a ser deliberada.
 */

/**
 * Helpers locais (prefixo sprint7 — o Pest carrega tudo junto na suíte cheia,
 * e depender do makeWebPerformer de outro arquivo quebra a rodada filtrada).
 */
function sprint7Performer(array $userAttrs = [], array $profileAttrs = []): User
{
    $user = User::factory()->performer()->create($userAttrs);

    $user->performerProfile()->create(array_merge([
        'stage_name' => 'Aurora Vex '.Str::random(4),
        'category' => 'mulheres',
    ], $profileAttrs));

    return $user;
}

function sprint7ActivePerformer(array $profileAttrs = []): User
{
    return sprint7Performer(['status' => 'active'], $profileAttrs);
}

// ─── Wizard · fase register (passos 1–3 → POST único em register.store) ─────

it('renders the performer wizard variant of the register page', function () {
    $this->get('/cadastro?tipo=performer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('tipo', 'performer'));
});

it('creates the performer account from the wizard payload and lands on onboarding', function () {
    $this->post('/cadastro', [
        'tipo' => 'performer',
        'name' => 'Luna Prado',
        'email' => 'luna.wizard@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1995-03-10',
        'stage_name' => 'Luna do Portal',
        'category' => 'mulheres',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('performer.onboarding'));

    $user = User::where('email', 'luna.wizard@example.com')->sole();

    expect($user->role)->toBe('performer')
        ->and($user->status)->toBe('pending')
        ->and($user->performerProfile->stage_name)->toBe('Luna do Portal')
        ->and($user->performerProfile->category)->toBe('mulheres');
});

// ─── Wizard · fase profile (passos 4–5 nas rotas de onboarding existentes) ──

it('exposes the profile prefill and kyc status to the onboarding page', function () {
    $performer = sprint7Performer(profileAttrs: ['stage_name' => 'Aurora Vex', 'category' => 'trans', 'bio' => 'Uma bio já escrita.']);

    $this->actingAs($performer)
        ->get(route('performer.onboarding'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Onboarding')
            ->where('profile.stage_name', 'Aurora Vex')
            ->where('profile.bio', 'Uma bio já escrita.')
            ->where('kycStatus', 'not_submitted'));
});

it('saves the bio through the existing onboarding profile route (wizard step 4)', function () {
    $performer = sprint7Performer();

    $this->actingAs($performer)
        ->post(route('performer.onboarding.profile'), ['bio' => 'Minha história começa aqui.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($performer->performerProfile->fresh()->bio)->toBe('Minha história começa aqui.');
});

it('saves the avatar through the existing onboarding avatar route (wizard step 5)', function () {
    Storage::fake('local');

    $performer = sprint7Performer();

    $this->actingAs($performer)
        ->post(route('performer.onboarding.avatar'), [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($performer->performerProfile->fresh()->avatar_path)->not->toBeNull();
});

// ─── KycGate ────────────────────────────────────────────────────────────────

it('lets a pending performer reach the dashboard via "Verificar depois"', function () {
    // O link secundário do KycGate aponta para performer.dashboard; antes do
    // Sprint 7 a pendente levava 403 ali e o link seria uma parede.
    $performer = sprint7Performer();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performer/Dashboard'));
});

it('keeps suspended performers out of the dashboard', function () {
    $performer = sprint7Performer(['status' => 'suspended']);

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertForbidden();
});

it('redirects a performer without profile from the dashboard to onboarding', function () {
    $performer = User::factory()->performer()->create();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertRedirect(route('performer.onboarding'));
});

it('keeps the kyc gate decoupled: start-kyc is an emit, not a route', function () {
    // O KycGate não conhece o provedor: emite 'start-kyc' e o pai abre o fluxo
    // (form hoje, SDK Didit amanhã). Trava que ninguém acople um POST direto.
    $source = file_get_contents(resource_path('js/Components/Onboarding/KycGate.vue'));

    expect($source)->toContain("emit('start-kyc')")
        ->and($source)->toContain('Verificar agora')
        ->and($source)->toContain('Verificar depois')
        ->and($source)->toContain("route('performer.dashboard')")
        ->and($source)->toContain('LGPD');
});

// ─── KycPendingBanner + empty states ────────────────────────────────────────

it('feeds the banner: pending performer sees kycStatus pending on the dashboard', function () {
    $performer = sprint7Performer();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'pending'));
});

it('feeds the banner: approved performer sees kycStatus active (banner hidden)', function () {
    $performer = sprint7ActivePerformer();
    $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'status' => 'approved',
        'age_confirmed' => true,
    ]);

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'active'));
});

it('feeds the banner: rejected verification keeps the banner visible', function () {
    $performer = sprint7ActivePerformer();
    $performer->identityVerifications()->create([
        'document_type' => 'rg',
        'status' => 'rejected',
    ]);

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('kycStatus', 'rejected'));
});

it('locks the banner contract: PO copy, always-on link, no dismiss button', function () {
    $source = file_get_contents(resource_path('js/Components/KycPendingBanner.vue'));

    expect($source)->toContain('Seu perfil não aparece no catálogo ainda.')
        ->and($source)->toContain('Verificar agora')
        // Sem botão de fechar: o banner só sai quando o KYC aprova.
        ->and($source)->not->toContain('close')
        ->and($source)->not->toContain('dismiss');
});

it('locks the empty state copy for the followers tab', function () {
    $source = file_get_contents(resource_path('js/Pages/Performer/Followers.vue'));

    expect($source)->toContain('Seu Portal ainda não foi descoberto.')
        ->and($source)->toContain('Complete seu perfil para aparecer no catálogo.');
});

it('locks the empty state copy for the earnings section', function () {
    $source = file_get_contents(resource_path('js/Pages/Performer/Dashboard.vue'));

    expect($source)->toContain('Seus primeiros apoiadores estão a um post de distância.');
});

it('shows the empty earnings state data: dashboard tips prop is empty for a new performer', function () {
    $performer = sprint7ActivePerformer();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('tips', 0));
});
