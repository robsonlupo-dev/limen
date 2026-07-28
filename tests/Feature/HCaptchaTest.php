<?php

use App\Models\User;
use App\Rules\HCaptchaValid;
use App\Services\HCaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * hCaptcha em login e cadastro (Sprint 9). Ver docs/HCAPTCHA.md.
 *
 * Duas teses, e a primeira é a que protege a suíte inteira:
 *
 *  1. **DESLIGADO é no-op de verdade.** `HCAPTCHA_ENABLED=false` é o padrão, o
 *     valor versionado e o estado de todo o CI — se o campo passasse a ser
 *     exigido nesse modo, ou se uma requisição saísse para hcaptcha.com, cada
 *     teste de auth do projeto quebraria. Por isso o `Http::preventStrayRequests`
 *     abaixo: não basta o login funcionar, tem que funcionar SEM falar com o
 *     terceiro.
 *
 *  2. **LIGADO fecha as quatro portas** — login web, login API, cadastro web
 *     (membro e wizard da performer) e cadastro API. A regra tem uma dona só
 *     (HCaptchaValid) exatamente para a quinta porta não nascer sem ela.
 *
 * Helpers locais (prefixo cap*) para o arquivo ser autossuficiente.
 */

/** Liga o captcha com um par de chaves sintético. */
function capEnable(): void
{
    config([
        'hcaptcha.enabled' => true,
        'hcaptcha.sitekey' => 'test-sitekey',
        'hcaptcha.secret' => 'test-secret',
    ]);
}

/** O que o hCaptcha responderia a um token bom / ruim. */
function capFakeVerify(bool $success): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => $success], 200),
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

// ─── DESLIGADO: no-op completo ──────────────────────────────────────────────

it('deixa o login web passar sem o campo e sem falar com o hcaptcha', function () {
    // Qualquer requisição HTTP não declarada vira exceção: é assim que o teste
    // prova que NENHUM byte sai para hcaptcha.com com a feature desligada.
    Http::preventStrayRequests();

    capUser();

    $this->post(route('login.store'), ['email' => 'membro@example.com', 'password' => 'Password1'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

it('deixa o cadastro web passar sem o campo e sem falar com o hcaptcha', function () {
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

it('nao manda o sitekey para a tela quando esta desligado', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hcaptcha.enabled', false)
            ->where('hcaptcha.sitekey', null)
        );
});

it('o verificador nem toca na rede quando esta desligado', function () {
    Http::preventStrayRequests();

    // Mesmo com um token qualquer na mão: desligado, a resposta é true sem
    // requisição. Guarda contra um chamador futuro passar por cima da regra.
    expect(app(HCaptchaVerifier::class)->verify('qualquer-coisa'))->toBeTrue();
});

// ─── LIGADO: token ausente ──────────────────────────────────────────────────

it('recusa o login web sem token', function () {
    capEnable();
    Http::preventStrayRequests(); // sem token, nem chega a consultar o provedor
    capUser();

    $this->post(route('login.store'), ['email' => 'membro@example.com', 'password' => 'Password1'])
        ->assertSessionHasErrors(HCaptchaValid::FIELD);

    $this->assertGuest();
});

it('recusa o cadastro web sem token', function () {
    capEnable();
    Http::preventStrayRequests();

    $this->post(route('register.store'), capWebRegisterPayload())
        ->assertSessionHasErrors(HCaptchaValid::FIELD);

    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
});

it('recusa as portas da API sem token', function () {
    capEnable();
    Http::preventStrayRequests();

    capUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'membro@example.com',
        'password' => 'Password1',
    ])->assertUnprocessable()->assertJsonValidationErrors(HCaptchaValid::FIELD);

    $this->postJson('/api/v1/auth/register/consumer', capApiRegisterPayload())
        ->assertUnprocessable()->assertJsonValidationErrors(HCaptchaValid::FIELD);

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
    ]))->assertUnprocessable()->assertJsonValidationErrors(HCaptchaValid::FIELD);
});

// ─── LIGADO: token inválido ─────────────────────────────────────────────────

it('recusa o login web com token invalido', function () {
    capEnable();
    capFakeVerify(false);
    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        HCaptchaValid::FIELD => 'token-forjado',
    ])->assertSessionHasErrors(HCaptchaValid::FIELD);

    $this->assertGuest();
});

it('recusa o cadastro web com token invalido', function () {
    capEnable();
    capFakeVerify(false);

    $this->post(route('register.store'), capWebRegisterPayload([
        HCaptchaValid::FIELD => 'token-forjado',
    ]))->assertSessionHasErrors(HCaptchaValid::FIELD);

    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
});

it('recusa o login da API com token invalido', function () {
    capEnable();
    capFakeVerify(false);
    capUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        HCaptchaValid::FIELD => 'token-forjado',
    ])->assertUnprocessable()->assertJsonValidationErrors(HCaptchaValid::FIELD);
});

// ─── LIGADO: token válido ───────────────────────────────────────────────────

it('deixa passar o login web com token valido', function () {
    capEnable();
    capFakeVerify(true);
    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        HCaptchaValid::FIELD => 'token-bom',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

it('deixa passar o cadastro web com token valido', function () {
    capEnable();
    capFakeVerify(true);

    $this->post(route('register.store'), capWebRegisterPayload([
        HCaptchaValid::FIELD => 'token-bom',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['email' => 'novo@example.com']);
});

it('deixa passar o cadastro da API com token valido', function () {
    capEnable();
    capFakeVerify(true);

    $this->postJson('/api/v1/auth/register/consumer', capApiRegisterPayload([
        HCaptchaValid::FIELD => 'token-bom',
    ]))->assertCreated();
});

// ─── O que vai (e o que não vai) para o provedor ────────────────────────────

it('manda o segredo e o token para o siteverify, e nunca o IP', function () {
    capEnable();
    capFakeVerify(true);

    app(HCaptchaVerifier::class)->verify('token-bom');

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://api.hcaptcha.com/siteverify')
            ->and($request['secret'])->toBe('test-secret')
            ->and($request['response'])->toBe('token-bom');

        // `remoteip` é opcional no siteverify e fica FORA de propósito: mandá-lo
        // seria a Limen transmitindo ativamente o IP do titular ao
        // subprocessador. Ver docs/HCAPTCHA.md.
        expect($request->data())->not->toHaveKey('remoteip');

        return true;
    });
});

it('nunca entrega o segredo ao frontend', function () {
    capEnable();

    $response = $this->get(route('login'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('hcaptcha.enabled', true)
        ->where('hcaptcha.sitekey', 'test-sitekey')
        ->missing('hcaptcha.secret')
    );

    // E nem por acidente no corpo da página.
    expect($response->getContent())->not->toContain('test-secret');
});

// ─── Fail-open em falha do provedor ─────────────────────────────────────────

it('deixa o login passar quando o hcaptcha esta fora do ar', function () {
    capEnable();
    // 503: indisponibilidade do provedor, não recusa do token. Trancar o login
    // aqui transformaria uma queda do hCaptcha numa queda da plataforma — mesma
    // escolha de fail-OPEN do GeoBlock. Ver config/hcaptcha.php, item 3.
    Http::fake(['api.hcaptcha.com/*' => Http::response('', 503)]);

    capUser();

    $this->post(route('login.store'), [
        'email' => 'membro@example.com',
        'password' => 'Password1',
        HCaptchaValid::FIELD => 'token-qualquer',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

it('distingue provedor fora do ar de token recusado', function () {
    capEnable();

    // A distinção é o coração do fail-open: 5xx passa, `success:false` barra.
    //
    // Uma sequência, e não dois `Http::fake()` seguidos: o segundo fake NÃO
    // substitui o primeiro — os stubs se acumulam e o primeiro que casa a URL
    // continua vencendo, então as duas chamadas receberiam o 500.
    Http::fake([
        'api.hcaptcha.com/*' => Http::sequence()
            ->push('', 500)
            ->push(['success' => false], 200),
    ]);

    expect(app(HCaptchaVerifier::class)->verify('t'))->toBeTrue()
        ->and(app(HCaptchaVerifier::class)->verify('t'))->toBeFalse();
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
