<?php

use App\Models\User;
use App\Services\DeletionService;
use App\Services\DocumentAcceptanceService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP das performers.
 *
 * A regra vive no TwoFactorService; estes testes cobrem o service E os PONTOS
 * DE APLICAÇÃO — um segundo fator que grava o segredo mas não barra a sessão
 * é pior do que não ter, porque a tela diz "protegido".
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function twoFactorPerformer(string $status = 'active'): User
{
    $user = User::factory()->create([
        'role' => 'performer',
        'status' => $status,
        'email_verified_at' => now(),
    ]);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $status === 'active',
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    // Os documentos são gate anterior ao 2FA; sem o aceite o redirect do
    // DocumentsAccepted mascararia o que estes testes querem medir.
    acceptAllDocuments($user);

    return $user->fresh();
}

function acceptAllDocuments(User $user): void
{
    // Pelo service e não montando as linhas na mão: o formato do aceite (versão
    // resolvida pelo config, fingerprint em HMAC) é dono dele, e reproduzir
    // isso aqui deixaria o teste passando contra um formato que o app não usa.
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));
}

/** Código TOTP válido agora para o segredo gravado do usuário. */
function totpFor(User $user): string
{
    return (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret);
}

/**
 * Um TOTP válido AGORA, com o contador de replay zerado antes.
 *
 * O uso único do TOTP (RFC 6238 §5.2) grava o timestep consumido, então dois
 * códigos seguidos dentro dos mesmos 30s se recusam — que é o comportamento
 * correto e tem teste próprio. Só que Google2FA lê o relógio por
 * `microtime()`, imune ao `Carbon::setTestNow`, então o teste não consegue
 * ANDAR para o passo seguinte: rebobinar o contador é o equivalente.
 *
 * Use nos testes cujo objeto NÃO é o replay; para esses, `totpFor()` cru.
 */
function freshTotpFor(User $user): string
{
    // UPDATE direto, e não forceFill+save: a instância em memória carrega o
    // valor de ANTES do confirm (null), então zerar por atributo não fica
    // dirty e o save não escreve nada — o teste passaria a medir o contrário
    // do que diz.
    DB::table('users')->where('id', $user->id)->update(['two_factor_last_used_ts' => null]);

    return totpFor($user);
}

/** Liga e confirma o 2FA, devolvendo os recovery codes emitidos. */
function enableAndConfirm(User $user): array
{
    $service = app(TwoFactorService::class);
    $setup = $service->enable($user);
    expect($service->confirm($user->fresh(), totpFor($user)))->toBeTrue();

    return $setup['recovery_codes'];
}

// ─── Service: cadastro ──────────────────────────────────────────────────────

it('enable() gera secret e recovery codes cifrados em repouso', function () {
    $user = twoFactorPerformer();

    $setup = app(TwoFactorService::class)->enable($user);

    expect($setup['secret'])->toBeString()->not->toBeEmpty()
        ->and($setup['recovery_codes'])->toHaveCount(TwoFactorService::RECOVERY_CODE_COUNT)
        ->and($setup['qr_svg'])->toContain('<svg')
        ->and($setup['otpauth_uri'])->toStartWith('otpauth://totp/');

    $user->refresh();
    expect($user->two_factor_secret)->toBe($setup['secret'])
        ->and($user->two_factor_recovery_codes)->toBe($setup['recovery_codes'])
        // Ainda NÃO está ligado: falta provar o autenticador.
        ->and($user->two_factor_confirmed_at)->toBeNull();

    // O que está no banco é ciphertext, não o segredo. Um dump não rende fator.
    $raw = DB::table('users')->where('id', $user->id)->first();
    expect($raw->two_factor_secret)->not->toBe($setup['secret'])
        ->and($raw->two_factor_secret)->not->toContain($setup['secret'])
        ->and($raw->two_factor_recovery_codes)->not->toContain($setup['recovery_codes'][0]);
});

it('o QR é gerado localmente, sem host externo', function () {
    $setup = app(TwoFactorService::class)->enable(twoFactorPerformer());

    // A otpauth:// carrega o segredo em claro — terceirizar o desenho do QR
    // (o `chart.googleapis.com/...` de todo tutorial de TOTP) entregaria o
    // segundo fator de todas as performers a um host de terceiro.
    //
    // As declarações de namespace são removidas antes da checagem: `xmlns` é
    // identificador de vocabulário XML, não endereço buscado pelo navegador.
    $markup = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $setup['qr_svg']);

    expect($markup)->not->toContain('http')
        // Nem por <image href>, que é o outro jeito de o SVG buscar rede.
        ->and($markup)->not->toContain('<image');
});

it('enable() recusa regerar o segredo com 2FA já ativo', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    // Sem esta recusa, quem roubou a sessão trocaria o segundo fator por um seu
    // sem nunca apresentar um fator.
    expect(fn () => app(TwoFactorService::class)->enable($user->fresh()))
        ->toThrow(LogicException::class);
});

// ─── Service: confirmação ───────────────────────────────────────────────────

it('confirm() com código válido marca confirmed_at', function () {
    $user = twoFactorPerformer();
    $service = app(TwoFactorService::class);
    $service->enable($user);

    expect($service->confirm($user->fresh(), totpFor($user)))->toBeTrue();

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($service->isEnabled($user))->toBeTrue();
});

it('confirm() com código inválido rejeita e não liga o 2FA', function () {
    $user = twoFactorPerformer();
    $service = app(TwoFactorService::class);
    $service->enable($user);

    expect($service->confirm($user->fresh(), '000000'))->toBeFalse();
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('confirm() não aceita recovery code — o passo prova o autenticador', function () {
    $user = twoFactorPerformer();
    $service = app(TwoFactorService::class);
    $codes = $service->enable($user)['recovery_codes'];

    // Confirmar por recovery code ligaria o 2FA de quem nunca configurou app
    // nenhum — e os códigos a tela acabou de exibir.
    expect($service->confirm($user->fresh(), $codes[0]))->toBeFalse()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

// ─── Service: verificação e recovery codes ──────────────────────────────────

it('verify() aceita TOTP válido sem consumir recovery code', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    expect($service->verify($user->fresh(), freshTotpFor($user)))->toBeTrue()
        ->and($service->remainingRecoveryCodes($user->fresh()))->toBe(8);
});

it('verify() com recovery code o consome — uso único', function () {
    $user = twoFactorPerformer();
    $codes = enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    expect($service->verify($user->fresh(), $codes[0]))->toBeTrue()
        ->and($service->remainingRecoveryCodes($user->fresh()))->toBe(7);

    // Segunda apresentação do MESMO código: já foi queimado.
    expect($service->verify($user->fresh(), $codes[0]))->toBeFalse()
        ->and($service->remainingRecoveryCodes($user->fresh()))->toBe(7);

    // Os outros sete continuam valendo.
    expect($service->verify($user->fresh(), $codes[1]))->toBeTrue()
        ->and($service->remainingRecoveryCodes($user->fresh()))->toBe(6);
});

it('verify() rejeita código de outra performer', function () {
    $a = twoFactorPerformer();
    $b = twoFactorPerformer();
    enableAndConfirm($a);
    $codesB = enableAndConfirm($b);

    $service = app(TwoFactorService::class);

    expect($service->verify($a->fresh(), totpFor($b)))->toBeFalse()
        ->and($service->verify($a->fresh(), $codesB[0]))->toBeFalse()
        // E o código de B segue intacto — A não consome o que não é dele.
        ->and($service->remainingRecoveryCodes($b->fresh()))->toBe(8);
});

it('regenerar recovery codes invalida o lote anterior', function () {
    $user = twoFactorPerformer();
    $old = enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    $new = $service->regenerateRecoveryCodes($user->fresh(), freshTotpFor($user));

    expect($new)->toHaveCount(8)
        ->and(array_intersect($old, $new))->toBeEmpty()
        ->and($service->verify($user->fresh(), $old[0]))->toBeFalse()
        ->and($service->verify($user->fresh(), $new[0]))->toBeTrue();
});

it('regenerar recovery codes exige um fator válido', function () {
    $user = twoFactorPerformer();
    $old = enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    expect($service->regenerateRecoveryCodes($user->fresh(), '000000'))->toBeNull()
        // Nada foi trocado: o lote antigo continua de pé.
        ->and($service->verify($user->fresh(), $old[0]))->toBeTrue();
});

// ─── Service: desativação ───────────────────────────────────────────────────

it('disable() exige código válido antes de desativar', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    expect($service->disable($user->fresh(), '000000'))->toBeFalse();

    $user->refresh();
    expect($service->isEnabled($user))->toBeTrue()
        ->and($user->two_factor_secret)->not->toBeNull();
});

it('disable() com código válido limpa segredo e recovery codes', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    expect($service->disable($user->fresh(), freshTotpFor($user)))->toBeTrue();

    $user->refresh();
    expect($service->isEnabled($user))->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('disable() aceita recovery code — é a saída de quem perdeu o celular', function () {
    $user = twoFactorPerformer();
    $codes = enableAndConfirm($user);

    expect(app(TwoFactorService::class)->disable($user->fresh(), $codes[0]))->toBeTrue();
});

// ─── Middleware ─────────────────────────────────────────────────────────────

it('middleware redireciona performer com 2FA sem 2fa_verified na sessão', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    // actingAs monta a sessão sem passar pelo desafio — é exatamente o estado
    // de quem acabou de logar.
    $this->actingAs($user->fresh())
        ->get(route('performer.dashboard'))
        ->assertRedirect(route('performer.2fa.challenge'));
});

it('middleware cobre as rotas compartilhadas, não só performer.*', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    // Gatear só o dashboard deixaria a conta sequestrada conversando com
    // membros — a superfície de impersonation que o fator existe para fechar.
    $this->actingAs($user->fresh())
        ->get(route('chat.index'))
        ->assertRedirect(route('performer.2fa.challenge'));
});

it('middleware libera a sessão que passou pelo desafio', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    $this->actingAs($user->fresh())
        ->withSession([TwoFactorService::SESSION_KEY => $user->id])
        ->get(route('performer.dashboard'))
        ->assertOk();
});

it('middleware não afeta membros (role=consumer)', function () {
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($member)->get(route('catalog'))->assertOk();
    $this->actingAs($member)->get(route('consumer.dashboard'))->assertOk();
});

it('middleware não afeta performer sem 2FA configurado', function () {
    $user = twoFactorPerformer();

    $this->actingAs($user)->get(route('performer.dashboard'))->assertOk();
});

it('middleware não prende a performer no meio do cadastro do 2FA', function () {
    $user = twoFactorPerformer();
    // Segredo gerado, autenticador ainda não provado. Gatear aqui trancaria a
    // conta para fora com um QR que ela nunca chegou a escanear.
    app(TwoFactorService::class)->enable($user);

    $this->actingAs($user->fresh())->get(route('performer.dashboard'))->assertOk();
});

it('a performer barrada ainda consegue fazer logout', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    $this->actingAs($user->fresh())
        ->post(route('logout'))
        ->assertRedirect(route('landing'));

    $this->assertGuest();
});

// ─── Rotas web ──────────────────────────────────────────────────────────────

it('a tela do desafio libera a sessão com um código válido', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    $this->actingAs($user->fresh())
        ->post(route('performer.2fa.verify'), ['code' => freshTotpFor($user)])
        ->assertRedirect(route('performer.dashboard'))
        ->assertSessionHas(TwoFactorService::SESSION_KEY, $user->id);

    $this->get(route('performer.dashboard'))->assertOk();
});

it('o desafio com código errado não libera a sessão', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    $this->actingAs($user->fresh())
        ->from(route('performer.2fa.challenge'))
        ->post(route('performer.2fa.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code')
        ->assertSessionMissing(TwoFactorService::SESSION_KEY);
});

it('confirmar pelo web já deixa a sessão verificada', function () {
    $user = twoFactorPerformer();
    app(TwoFactorService::class)->enable($user);

    // Sem isto, a performer confirmaria e cairia no desafio pedindo o MESMO
    // código, que a janela atual já gastou.
    $this->actingAs($user->fresh())
        ->post(route('performer.2fa.confirm'), ['code' => totpFor($user)])
        ->assertRedirect(route('performer.2fa.show'))
        ->assertSessionHas(TwoFactorService::SESSION_KEY, $user->id);
});

it('a tela de configurações mostra o estado sem vazar o segredo', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    $response = $this->actingAs($user->fresh())
        ->withSession([TwoFactorService::SESSION_KEY => $user->id])
        ->get(route('performer.2fa.show'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Performer/TwoFactor/Settings')
        ->where('enabled', true)
        ->where('pending', false)
        ->where('remainingRecoveryCodes', 8)
        // Fora do fluxo de setup a tela não repõe o material sensível.
        ->where('setup', null)
    );

    $response->assertDontSee($user->fresh()->two_factor_secret);
});

it('POST /enable com 2FA já ativo devolve 409 e não troca o segredo', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $secret = $user->fresh()->two_factor_secret;

    $this->actingAs($user->fresh())
        ->withSession([TwoFactorService::SESSION_KEY => $user->id])
        ->post(route('performer.2fa.enable'))
        ->assertStatus(409);

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

it('a performer pendente (em KYC) alcança a tela de 2FA', function () {
    // É a janela em que a conta já guarda documento e selfie e ainda não tem
    // segundo fator — adiar o 2FA até a ativação protegeria o KYC tarde demais.
    $user = twoFactorPerformer('pending');

    $this->actingAs($user)->get(route('performer.2fa.show'))->assertOk();
});

it('o membro não alcança as rotas de 2FA da performer', function () {
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($member)->get(route('performer.2fa.show'))->assertForbidden();
    $this->actingAs($member)->get(route('performer.2fa.challenge'))->assertForbidden();
});

it('o desafio brutalmente forçado bate no throttle', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $this->actingAs($user->fresh());

    // 6 dígitos são 1 em 1.000.000 por tentativa; sem teto, um script fecha o
    // espaço em horas.
    for ($i = 0; $i < 5; $i++) {
        $this->from(route('performer.2fa.challenge'))
            ->post(route('performer.2fa.verify'), ['code' => '000000']);
    }

    $this->from(route('performer.2fa.challenge'))
        ->post(route('performer.2fa.verify'), ['code' => '000000'])
        ->assertStatus(429);
});

// ─── Não vazamento ──────────────────────────────────────────────────────────

it('two_factor_secret não aparece em serialização do model', function () {
    $user = twoFactorPerformer();
    $setup = app(TwoFactorService::class)->enable($user);

    $json = $user->fresh()->toJson();

    expect($json)->not->toContain($setup['secret'])
        ->not->toContain($setup['recovery_codes'][0])
        ->not->toContain('two_factor_secret')
        ->not->toContain('two_factor_recovery_codes');
});

it('as colunas de 2FA não entram por mass assignment', function () {
    $user = twoFactorPerformer();

    $user->fill([
        'two_factor_secret' => 'ATACANTE',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->save();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('o segredo não vai para o audit log', function () {
    $user = twoFactorPerformer();
    $setup = app(TwoFactorService::class)->enable($user);

    $logs = DB::table('audit_logs')->where('user_id', $user->id)->get();

    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect((string) $log->metadata)->not->toContain($setup['secret'])
            ->and((string) $log->metadata)->not->toContain($setup['recovery_codes'][0]);
    }
});

it('o login não herda a marca de 2FA da sessão anterior', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    // regenerate() troca o id da sessão mas PRESERVA os dados: sem o forget
    // explícito, a sessão nova nasceria já verificada.
    $this->withSession([TwoFactorService::SESSION_KEY => $user->id])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'senha-de-teste-123',
        ]);

    $this->get(route('performer.dashboard'))
        ->assertRedirect(route('performer.2fa.challenge'));
});

// ─── Porta Sanctum (API) ────────────────────────────────────────────────────
//
// O gate tem que valer nas DUAS portas de auth (CLAUDE.md). Sem isto, bastava
// pedir um token em /api/v1/auth/login com a SENHA e usar /api/v1/performer/*,
// onde moram perfil, KYC e gorjetas — o segundo fator viraria enfeite.

it('o login da API não emite token cheio para quem tem 2FA', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    $response = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'senha-de-teste-123',
    ]);

    $response->assertOk()
        ->assertJson(['two_factor_required' => true])
        ->assertJsonStructure(['challenge_token'])
        // Nem o token real nem o perfil saem antes do fator.
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('data');
});

it('o token de desafio não abre as rotas de performer da API', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    $challenge = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'senha-de-teste-123',
    ])->json('challenge_token');

    $headers = ['Authorization' => 'Bearer '.$challenge];

    foreach ([
        fn () => $this->getJson(route('performer.profile.show'), $headers),
        fn () => $this->getJson(route('auth.me'), $headers),
        fn () => $this->postJson(route('performer.kyc.submit'), [], $headers),
    ] as $call) {
        $this->app['auth']->forgetGuards();
        $call()->assertForbidden();
    }
});

it('a troca do token de desafio por código válido devolve o token cheio', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    $challenge = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'senha-de-teste-123',
    ])->json('challenge_token');

    $token = $this->postJson(route('auth.2fa.challenge'), ['code' => freshTotpFor($user)], [
        'Authorization' => 'Bearer '.$challenge,
    ])->assertOk()->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    // O guard é um singleton no container, e o container NÃO é reconstruído
    // entre requests do mesmo teste: sem isto, o `sanctum` devolve o usuário
    // que ele memorizou na request anterior — ainda carregando o token de
    // DESAFIO — e o teste mediria o token errado. Em produção cada request
    // sobe a app do zero, então o artefato é só do teste.
    $this->app['auth']->forgetGuards();

    $this->getJson(route('performer.profile.show'), ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    // O token de desafio foi queimado na troca — não sobra credencial de
    // meio-caminho pendurada.
    $this->app['auth']->forgetGuards();

    $this->postJson(route('auth.2fa.challenge'), ['code' => freshTotpFor($user)], [
        'Authorization' => 'Bearer '.$challenge,
    ])->assertUnauthorized();
});

it('a troca com código inválido não emite token', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    $challenge = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'senha-de-teste-123',
    ])->json('challenge_token');

    $this->postJson(route('auth.2fa.challenge'), ['code' => '000000'], [
        'Authorization' => 'Bearer '.$challenge,
    ])->assertStatus(422)->assertJsonMissingPath('token');
});

it('o login da API segue emitindo token cheio para quem não tem 2FA', function () {
    $user = twoFactorPerformer();
    $user->forceFill(['password' => 'senha-de-teste-123'])->save();

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'senha-de-teste-123',
    ])->assertOk()->assertJsonStructure(['token']);
});

// ─── Replay do TOTP ─────────────────────────────────────────────────────────

it('o mesmo código TOTP não passa duas vezes', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);
    $service = app(TwoFactorService::class);

    $code = freshTotpFor($user);

    expect($service->verify($user->fresh(), $code))->toBeTrue()
        // RFC 6238 §5.2: uso único. Sem isto, o código capturado no desafio
        // ainda servia, dentro da janela, para POST /2fa/disable.
        ->and($service->verify($user->fresh(), $code))->toBeFalse();
});

it('o código usado no confirm não serve para desativar em seguida', function () {
    $user = twoFactorPerformer();
    $service = app(TwoFactorService::class);
    $service->enable($user);

    $code = totpFor($user);
    expect($service->confirm($user->fresh(), $code))->toBeTrue()
        ->and($service->disable($user->fresh(), $code))->toBeFalse()
        ->and($service->isEnabled($user->fresh()))->toBeTrue();
});

// ─── Flash de setup e Hard Delete ───────────────────────────────────────────

it('o segredo não fica legível no store de sessão', function () {
    $user = twoFactorPerformer();

    $this->actingAs($user)->post(route('performer.2fa.enable'));

    // O store é `database` com encrypt=false: flashar cru deixaria o segundo
    // fator legível na tabela `sessions`.
    $secret = $user->fresh()->two_factor_secret;
    $flashed = session('2fa_setup');

    expect($flashed)->toBeString()
        ->and($flashed)->not->toContain($secret);
});

it('o Hard Delete LGPD apaga o segundo fator', function () {
    $user = twoFactorPerformer();
    enableAndConfirm($user);

    app(DeletionService::class)->executeDeletion($user->fresh(), 'user_request');

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->two_factor_secret)->toBeNull()
        ->and($raw->two_factor_recovery_codes)->toBeNull()
        ->and($raw->two_factor_confirmed_at)->toBeNull();
});
