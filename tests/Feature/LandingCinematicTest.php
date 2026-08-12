<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Landing cinematográfica (feat/landing-cinematic)
|--------------------------------------------------------------------------
|
| A raiz pública "/" renderiza a landing de 5 cenas. Estes testes travam o
| contrato de SERVIDOR: qual componente responde, o redirect do logado, e o
| cartão social (og:description = tagline, og:image = moldura.webp). O contrato
| de CLIENTE (CTA → /cadastro, mídia self-host) mora em
| tests/Unit/LandingCinematicAssetsTest.php — SSR está off, então o HTML das
| cenas não existe na resposta e não dá para assertar aqui.
*/

it('renders the cinematic landing on the public root as the Landing component', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Landing'));
});

it('redirects an authenticated visitor away from the landing to the catalog', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('catalog'));
});

it('exposes the tagline as description/og:description and moldura.webp as og:image', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('meta.description', 'O portal do desejo, verificado e real.')
        ->where('meta.og_description', 'O portal do desejo, verificado e real.')
        ->where('meta.og_image', 'https://thelimen.com.br/landing/moldura.webp'));
});

it('server-renders the social card meta into the document head', function () {
    // Inertia SSR is off, so the scraper only sees what app.blade.php prints
    // from the `meta` prop. This is the byte a WhatsApp/Google preview reads.
    $html = $this->get('/')->getContent();

    expect($html)
        ->toContain('<meta property="og:description" content="O portal do desejo, verificado e real.">')
        ->toContain('<meta property="og:image" content="https://thelimen.com.br/landing/moldura.webp">');
});
