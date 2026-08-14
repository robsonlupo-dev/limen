<?php

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LiveKitService;
use App\Services\PerformerContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * UAT fase 1, round 2 — quatro achados:
 *  1. Conteúdo permanente invisível no perfil (o membro não via as fotos pagas).
 *  2. Live dava 500 ("scheme 'wss' is not supported" no RoomServiceClient).
 *  3. Panic button ausente para o membro nas telas GuestLayout.
 *  4. Performer conseguia entrar em /catalogo.
 *
 * Helpers locais (prefixo r2*) para o arquivo rodar isolado.
 */
function r2Performer(string $stage = 'Ana UAT'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stage,
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
    ]);
}

function r2Member(?string $tier = null): User
{
    $user = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);

    if ($tier !== null) {
        Subscription::factory()->for($user)->circle($tier)->create();
        $user->refresh();
    }

    return $user;
}

function r2Publish(PerformerProfile $profile, string $level, int $price = 20): PerformerContent
{
    return app(PerformerContentService::class)->publish(
        $profile,
        UploadedFile::fake()->image('c.jpg', 800, 600),
        $level,
        $price,
    );
}

/** As peças no prop `contents` da tela (autenticada ou pública). */
function r2Contents($response): array
{
    return $response->assertOk()->viewData('page')['props']['contents'];
}

// ─── #1 Conteúdo permanente visível no perfil ────────────────────────────────

it('mostra so o conteudo Aberto (pago) para o membro Free, no catalogo autenticado', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN, 15);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM);
    r2Publish($profile, PerformerContent::LEVEL_EXCLUSIVE);
    r2Publish($profile, PerformerContent::LEVEL_FC_ONLY);

    $free = r2Member(); // sem assinatura

    $contents = r2Contents(test()->actingAs($free)->get(route('catalog.show', $profile->slug)));

    // Só o Aberto aparece — Premium/Exclusivo/FC Only NÃO chegam ao Free (M.13.13).
    expect($contents)->toHaveCount(1)
        ->and($contents[0]['access_level'])->toBe(PerformerContent::LEVEL_OPEN)
        // Aberto para não-assinante é PAGO: bloqueado, com preço, desbloqueável.
        ->and($contents[0]['locked'])->toBeTrue()
        ->and($contents[0]['can_unlock'])->toBeTrue()
        ->and($contents[0]['price_tokens'])->toBe(15)
        // Bloqueado NÃO recebe URL de bytes (paywall não é blur de CSS).
        ->and($contents[0]['image_url'])->toBeNull();
});

it('mostra Aberto + Premium para um membro Prestige', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM);
    r2Publish($profile, PerformerContent::LEVEL_EXCLUSIVE);

    $prestige = r2Member('prestige');

    $levels = collect(r2Contents(test()->actingAs($prestige)->get(route('catalog.show', $profile->slug))))
        ->pluck('access_level')
        ->sort()
        ->values()
        ->all();

    // Prestige alcança Aberto e Premium; Exclusivo (Black+) fica de fora.
    expect($levels)->toBe([PerformerContent::LEVEL_OPEN, PerformerContent::LEVEL_PREMIUM]);
});

it('na pagina publica o visitante deslogado ve so o Aberto, bloqueado e sem desbloqueio', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM);

    $contents = r2Contents(test()->get(route('performers.public.show', $profile->slug)));

    expect($contents)->toHaveCount(1)
        ->and($contents[0]['access_level'])->toBe(PerformerContent::LEVEL_OPEN)
        ->and($contents[0]['locked'])->toBeTrue()
        // Visitante deslogado não desbloqueia aqui — cai no cadastro.
        ->and($contents[0]['can_unlock'])->toBeFalse()
        ->and($contents[0]['image_url'])->toBeNull();
});

it('o assinante ve o conteudo Aberto liberado (gratis) com a URL de bytes', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN);

    $insider = r2Member('insider'); // Aberto é grátis para QUALQUER assinante

    $contents = r2Contents(test()->actingAs($insider)->get(route('catalog.show', $profile->slug)));

    expect($contents)->toHaveCount(1)
        ->and($contents[0]['locked'])->toBeFalse()
        ->and($contents[0]['image_url'])->not->toBeNull();
});

// ─── #2 Live 500: o RoomServiceClient precisa de https, não wss ───────────────

it('converte o scheme wss/ws do LIVEKIT_URL para https/http no RoomServiceClient', function () {
    // O RoomServiceClient (Twirp sobre HTTP) recusa wss/ws — era o 500 do
    // /performer/live/start. A conversão mora no LiveKitService (dona única).
    $method = new ReflectionMethod(LiveKitService::class, 'roomServiceUrl');

    $resolve = function (string $url) use ($method) {
        config(['livekit.url' => $url]);

        return $method->invoke(app(LiveKitService::class));
    };

    expect($resolve('wss://limen.livekit.cloud'))->toBe('https://limen.livekit.cloud')
        ->and($resolve('ws://localhost:7880'))->toBe('http://localhost:7880')
        // https/http já corretos passam intactos.
        ->and($resolve('https://limen.livekit.cloud'))->toBe('https://limen.livekit.cloud')
        ->and($resolve('http://localhost:7880'))->toBe('http://localhost:7880');
});

// ─── #3 Panic button nas telas GuestLayout (membro logado) ───────────────────

it('monta o panic button no GuestLayout para o usuario logado', function () {
    $guestLayout = File::get(resource_path('js/Layouts/GuestLayout.vue'));

    // Importado E montado sob v-if de login: o membro que chega numa tela pública
    // por link direto tem a mesma saída rápida que tem no AppLayout.
    expect($guestLayout)->toContain("import PanicButton from '@/Components/PanicButton.vue'")
        ->and($guestLayout)->toMatch('/<PanicButton\s+v-if="isLoggedIn"\s*\/>/');
});

it('mantem o panic button montado no AppLayout', function () {
    // Guarda de regressão: o AppLayout o monta incondicionalmente (agora com a
    // prop :lift-mobile, que sobe a pílula acima da barra inferior do painel).
    expect(File::get(resource_path('js/Layouts/AppLayout.vue')))->toContain('<PanicButton');
});

// ─── #4 /catalogo: membro entra, performer redireciona, admin 403 ────────────
// (R3 trocou o 403 da performer por um redirect ao painel — UX melhor.)

it('deixa o membro (consumer) entrar em /catalogo', function () {
    test()->actingAs(r2Member())->get(route('catalog'))->assertOk();
});

it('redireciona a performer ativa de /catalogo para o painel (302, nao 403)', function () {
    $profile = r2Performer(); // status active → painel

    test()->actingAs($profile->user)->get(route('catalog'))
        ->assertRedirect(route('performer.dashboard'));
});

it('bloqueia o admin em /catalogo com 403', function () {
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    test()->actingAs($admin)->get(route('catalog'))->assertForbidden();
});

it('mantem o perfil /catalogo/{slug} acessivel a performer e admin (so o index e consumer-only)', function () {
    $profile = r2Performer();
    $other = r2Performer('Bia UAT');
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    // O show de um perfil específico não é gateado por role:consumer de propósito.
    test()->actingAs($other->user)->get(route('catalog.show', $profile->slug))->assertOk();
    test()->actingAs($admin)->get(route('catalog.show', $profile->slug))->assertOk();
});
