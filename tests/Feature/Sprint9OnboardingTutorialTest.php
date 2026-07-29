<?php

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Tutorial de primeira entrada no catálogo (4 slides, overlay client-side).
//
// O que é testável no servidor é só o FLAG: o overlay em si é Vue e a decisão de
// montar vive em Catalog/Index.vue. Estes testes travam o contrato que o
// componente consome — e, principalmente, travam a separação entre este flag e o
// `introSeen` da splash de visitante, que foi o furo do desenho original.

function makeTutorialConsumer(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
    ], $attrs));
}

// ─── 1. Flag compartilhado reflete o cookie ──────────────────────────────────

it('shares tutorialSeen=false when the tutorial cookie is absent', function () {
    $this->actingAs(makeTutorialConsumer())
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tutorialSeen', false));
});

it('shares tutorialSeen=true when the tutorial cookie is present', function () {
    $this->actingAs(makeTutorialConsumer())
        ->withCookie('limen_tutorial_seen', '1')
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tutorialSeen', true));
});

// ─── 2. O flag é INDEPENDENTE da splash de visitante ─────────────────────────
// Este é o teste que importa. `limen_intro_seen` é setado pelo IntroAnimation em
// qualquer página de GuestLayout (landing, login, cadastro), ou seja, ANTES de o
// membro chegar ao catálogo. Se os dois flags compartilhassem cookie, o tutorial
// nasceria morto para todo mundo que entrou pelo funil normal.

it('still shows the tutorial to a member who already saw the guest intro splash', function () {
    $this->actingAs(makeTutorialConsumer())
        ->withCookie('limen_intro_seen', '1')
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('introSeen', true)
            ->where('tutorialSeen', false)
        );
});

it('does not mark the guest intro as seen when the tutorial was dismissed', function () {
    $this->actingAs(makeTutorialConsumer())
        ->withCookie('limen_tutorial_seen', '1')
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tutorialSeen', true)
            ->where('introSeen', false)
        );
});

// ─── 3. Cookie isento de criptografia ────────────────────────────────────────
// Mesma razão dos outros flags de UI: o browser escreve em texto puro, e um
// cookie criptografado seria descartado na leitura — o tutorial repetiria para
// sempre.

it('exempts the tutorial flag cookie from encryption', function () {
    expect(app(EncryptCookies::class)->isDisabled('limen_tutorial_seen'))->toBeTrue();
});

// ─── 4. O flag acompanha qualquer resposta Inertia ───────────────────────────
// Compartilhado no HandleInertiaRequests (e não numa prop do CatalogController),
// então uma segunda superfície de onboarding não precisa de backend novo.

it('shares tutorialSeen on a guest response too', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tutorialSeen', false));
});
