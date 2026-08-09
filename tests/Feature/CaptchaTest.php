<?php

use App\Models\User;
use App\Rules\CaptchaValid;
use App\Services\Captcha\CaptchaManager;
use App\Services\Captcha\HcaptchaDriver;
use App\Services\Captcha\NullCaptchaDriver;
use App\Services\Captcha\TurnstileDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Captcha em login e cadastro — driver abstrato (Sprint 16). Ver docs/CAPTCHA.md.
 *
 * O captcha ganhou dois provedores intercambiáveis (hCaptcha e Cloudflare
 * Turnstile), selecionados por `CAPTCHA_PROVIDER`. As teses:
 *
 *  1. **`none` é no-op de verdade.** É o padrão, o valor versionado e o estado
 *     de todo o CI — se o campo passasse a ser exigido nesse modo, ou se uma
 *     requisição saísse para um provedor, cada teste de auth do projeto
 *     quebraria. Por isso o `Http::preventStrayRequests`: não basta o login
 *     funcionar, tem que funcionar SEM falar com terceiro.
 *  2. **Ligado fecha as portas** — login web, login API, cadastro web (membro e
 *     wizard) e cadastro API — e vale para OS DOIS provedores. A regra tem uma
 *     dona só (CaptchaValid) e a escolha do provedor tem outra (CaptchaManager).
 *  3. **O contrato de segurança do provedor único é preservado** — segredo
 *     nunca vai ao frontend, `remoteip` nunca vai ao siteverify, fail-open em
 *     queda do provedor, e reset/exclusão de rotas.
 *
 * Helpers locais (prefixo cap*) para o arquivo ser autossuficiente.
 */

/** Liga o captcha num provedor com um par de chaves sintético. */
function capEnable(string $provider = 'hcaptcha'): void
{
    config([
        'captcha.provider' => $provider,
        "captcha.providers.{$provider}.sitekey" => 'test-sitekey',
        "captcha.providers.{$provider}.secret" => 'test-secret',
    ]);
}

/** O que o provedor responderia a um token bom / ruim (ambos os hosts). */
function capFakeVerify(bool $success): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => $success], 200),
        'challenges.cloudflare.com/*' => Http::response(['success' => $success], 200),
    ]);
}

function capUser(string $email = 'membro@example.com'): User
{
    return User::factory()->create([
        'email' => $email,
        'password' => Hash::make('Password1'),
        'role' => 'consumer',
        'status' => 'active',
    ]);
}

/** Payload do cadastro WEB de membro (POST /cadastro). */
function capWebRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'tipo' => 'membro',
        'name' => 'Novo Membro',
        'email' => 'novo@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        // CPF estruturalmente válido, de teste — não pertence a ninguém.
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
        'preferred_world' => 'mulheres',
    ], $overrides);
}

/** Payload do cadastro da API v1 (POST /api/v1/auth/register/consumer). */
function capApiRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Novo Membro',
        'email' => 'novo-api@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
        'terms_version' => '1.0',
    ], $overrides);
}

// ─── Seleção de driver (CaptchaManager) ─────────────────────────────────────

it('resolve o driver certo a partir do provider', function () {
    $manager = app(CaptchaManager::class);

    config(['captcha.provider' => 'hcaptcha']);
    expect($manager->driver())->toBeInstanceOf(HcaptchaDriver::class)
        ->and($manager->enabled())->toBeTrue();

    config(['captcha.provider' => 'turnstile']);
    expect($manager->driver())->toBeInstanceOf(TurnstileDriver::class)
        ->and($manager->enabled())->toBeTrue();

    // Qualquer valor fora do conhecido (inclusive 'none' e vazio) cai no null.
    config(['captcha.provider' => 'none']);
    expect($manager->driver())->toBeInstanceOf(NullCaptchaDriver::class)
        ->and($manager->enabled())->toBeFalse();

    config(['captcha.provider' => 'coisa-invalida']);
    expect($manager->driver())->toBeInstanceOf(NullCaptchaDriver::class);
});

it('as props do front trazem provider e sitekey quando ligado, e escondem tudo quando none', function () {
    capEnable('turnstile');
    expect(app(CaptchaManager::class)->sharedProps())->toBe([
        'enabled' => true,
        'provider' => 'turnstile',
        'sitekey' => 'test-sitekey',
    ]);

    config(['captcha.provider' => 'none']);
    expect(app(CaptchaManager::class)->sharedProps())->toBe([
        'enabled' => false,
        'provider' => null,
        'sitekey' => null,
    ]);
});

// ─── DESLIGADO (none): no-op completo ───────────────────────────────────────

it('deixa o login web passar sem o campo e sem falar com o provedor', function () {
    // Qualquer requisição HTTP não declarada vira exceção: é assim que o teste
    // prova que NENHUM byte sai para o provedor com a feature desligada.
    Http::preventStrayRequests();

    capUser();

    $this->post(route('login.store'), ['email' => 'membro@example.com', 'password' => 'Password1'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

it('deixa o cadastro web passar sem o campo e sem falar com o provedor', function () {
    Http::preventStrayRequests();

    $this->post(route('register.store'), capWebRegisterPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['email' => 'novo@example.com']);
});

it('deixa as portas da API passarem sem o campo', function () {
    Http::preventStrayRequests();

    capUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'membro@example.com',
        'password' => 'Password1',
    ])->assertOk();

    $this->postJson('/api/v1/auth/register/consumer', capApiRegisterPayload())
        ->assertCreated();
});

it('nao manda o sitekey para a tela quando esta em none', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('captcha.enabled', false)
            ->where('captcha.provider', null)
            ->where('captcha.sitekey', null)
        );
});

it('o verificador nem toca na rede quando esta em none', function () {
    Http::preventStrayRequests();

    // Mesmo com um token qualquer na mão: desligado, a resposta é true sem
    // requisição. Guarda contra um chamador futuro passar por cima da regra.
    expect(app(CaptchaManager::class)->verify('qualquer-coisa'))->toBeTrue();
});

// ─── LIGADO: token ausente (nos dois provedores) ────────────────────────────

it('recusa o login web sem token', function (string $provider) {
    capEnable($provider);
    Http::preventStrayRequests(); // sem token, nem chega a consultar o provedor
    capUser();

    $this->post(route('login.store'), ['email' => 'membro@example.com', 'password' => 'Password1'])
        ->assertSessionHasErrors(CaptchaValid::FIELD);

    $this->assertGuest();
})->with(['hcaptcha', 'turnstile']);

it('recusa o cadastro web sem token', function (string $provider) {
    capEnable($provider);
    Http::preventStrayRequests();

    $this->post(route('register.store'), capWebRegisterPayload())
        ->assertSessionHasErrors(CaptchaValid::FIELD);

    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
})->with(['hcaptcha', 'turnstile']);

it('recusa as portas da API sem token', function () {
    capEnable();
    Http::preventStrayRequests();

    capUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'membro@example.com',
        'password' => 'Password1',
    ])->assertUnprocessable()->assertJsonValidationErrors(CaptchaValid::FIELD);

    $this->postJson('/api/v1/auth/register/consumer', capApiRegisterPayload())
        ->assertUnprocessable()->assertJsonValidationErrors(CaptchaValid::FIELD);

    $this->assertDatabaseMissing('users', ['email' => 'novo-api@example.com']);
});

it('recusa o cadastro de performer da API sem token', function () {
    capEnable();
    Http::preventStrayRequests();

    // A porta da performer herda as regras do cadastro de membro
    // (RegisterPerformerRequest extends RegisterConsumerRequest) — este teste
    // existe para a herança não ser desfeita por um override de rules().
    $this->postJson('/api/v1/auth/register/performer', capApiRegisterPayload([
        'email' => 'performer-api@example.com',
        'stage_name' => 'Palco',
        'category' => 'mulheres',
    ]))->assertUnprocessable()->assertJsonValidationErrors(CaptchaValid::FIELD);
});

it('recusa o pedido de OTP sem token', function () {
    capEnable('turnstile');
    Http::preventStrayRequests();

    capUser();

    $this->post(route('otp.request'), ['email' => 'membro@example.com'])
        ->assertSessionHasErrors(CaptchaValid::FIELD);
});

// ─── LIGADO: token inválido ─────────────────────────────────────────────────

it('recusa o login web com token invalido', function (string $provider) {
    capEnable($provider);
    capFakeVerify(false);
    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        CaptchaValid::FIELD => 'token-forjado',
    ])->assertSessionHasErrors(CaptchaValid::FIELD);

    $this->assertGuest();
})->with(['hcaptcha', 'turnstile']);

it('recusa o cadastro web com token invalido', function () {
    capEnable();
    capFakeVerify(false);

    $this->post(route('register.store'), capWebRegisterPayload([
        CaptchaValid::FIELD => 'token-forjado',
    ]))->assertSessionHasErrors(CaptchaValid::FIELD);

    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
});

it('recusa o login da API com token invalido', function () {
    capEnable();
    capFakeVerify(false);
    capUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        CaptchaValid::FIELD => 'token-forjado',
    ])->assertUnprocessable()->assertJsonValidationErrors(CaptchaValid::FIELD);
});

// ─── LIGADO: token válido (nos dois provedores) ─────────────────────────────

it('deixa passar o login web com token valido', function (string $provider) {
    capEnable($provider);
    capFakeVerify(true);
    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        CaptchaValid::FIELD => 'token-bom',
    ])->assertRedirect();

    $this->assertAuthenticated();
})->with(['hcaptcha', 'turnstile']);

it('deixa passar o cadastro web com token valido', function () {
    capEnable();
    capFakeVerify(true);

    $this->post(route('register.store'), capWebRegisterPayload([
        CaptchaValid::FIELD => 'token-bom',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['email' => 'novo@example.com']);
});

it('deixa passar o cadastro da API com token valido', function () {
    capEnable();
    capFakeVerify(true);

    $this->postJson('/api/v1/auth/register/consumer', capApiRegisterPayload([
        CaptchaValid::FIELD => 'token-bom',
    ]))->assertCreated();
});

// ─── O que vai (e o que não vai) para o provedor ────────────────────────────

it('manda o segredo e o token para o siteverify do provedor certo, e nunca o IP', function (string $provider, string $expectedUrl) {
    capEnable($provider);
    capFakeVerify(true);

    app(CaptchaManager::class)->verify('token-bom');

    Http::assertSent(function ($request) use ($expectedUrl) {
        expect($request->url())->toBe($expectedUrl)
            ->and($request['secret'])->toBe('test-secret')
            ->and($request['response'])->toBe('token-bom');

        // `remoteip` é opcional no siteverify e fica FORA de propósito: mandá-lo
        // seria a Limen transmitindo ativamente o IP do titular ao
        // subprocessador. Ver docs/CAPTCHA.md.
        expect($request->data())->not->toHaveKey('remoteip');

        return true;
    });
})->with([
    ['hcaptcha', 'https://api.hcaptcha.com/siteverify'],
    ['turnstile', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'],
]);

it('nunca entrega o segredo ao frontend', function () {
    capEnable();

    $response = $this->get(route('login'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('captcha.enabled', true)
        ->where('captcha.provider', 'hcaptcha')
        ->where('captcha.sitekey', 'test-sitekey')
        ->missing('captcha.secret')
    );

    // E nem por acidente no corpo da página.
    expect($response->getContent())->not->toContain('test-secret');
});

// ─── Fail-open em falha do provedor ─────────────────────────────────────────

it('deixa o login passar quando o provedor esta fora do ar', function () {
    capEnable();
    // 503: indisponibilidade do provedor, não recusa do token. Trancar o login
    // aqui transformaria uma queda do provedor numa queda da plataforma — mesma
    // escolha de fail-OPEN do GeoBlock. Ver config/captcha.php, item 3.
    Http::fake(['api.hcaptcha.com/*' => Http::response('', 503)]);

    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        CaptchaValid::FIELD => 'token-qualquer',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

it('distingue provedor fora do ar de token recusado', function () {
    capEnable('turnstile');

    // A distinção é o coração do fail-open: 5xx passa, `success:false` barra.
    //
    // Uma sequência, e não dois `Http::fake()` seguidos: o segundo fake NÃO
    // substitui o primeiro — os stubs se acumulam e o primeiro que casa a URL
    // continua vencendo, então as duas chamadas receberiam o 500.
    Http::fake([
        'challenges.cloudflare.com/*' => Http::sequence()
            ->push('', 500)
            ->push(['success' => false], 200),
    ]);

    expect(app(CaptchaManager::class)->verify('t'))->toBeTrue()
        ->and(app(CaptchaManager::class)->verify('t'))->toBeFalse();
});

// ─── Onde o captcha NÃO entra ───────────────────────────────────────────────

it('nao aplica captcha no reset de senha nem no reenvio de verificacao', function () {
    capEnable();
    Http::preventStrayRequests();

    // Decisão do PO: só login e cadastro. Se o captcha vazasse para cá, estas
    // rotas passariam a exigir um token que a tela nem renderiza.
    capUser();

    $this->post(route('password.email'), ['email' => 'membro@example.com'])
        ->assertSessionHasNoErrors();

    $this->actingAs(capUser('outro@example.com'))
        ->post(route('verification.send'))
        ->assertSessionHasNoErrors();
});
