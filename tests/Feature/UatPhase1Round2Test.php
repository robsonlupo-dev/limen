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

it('mostra TODOS os niveis como tile ao Free — Premium compravel, Exclusivo/FC bloqueados (21/08/2026)', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN, 15);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    r2Publish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);
    r2Publish($profile, PerformerContent::LEVEL_FC_ONLY, 80);

    $free = r2Member(); // sem assinatura

    $contents = collect(r2Contents(test()->actingAs($free)->get(route('catalog.show', $profile->slug))))
        ->keyBy('access_level');

    // Os 4 níveis APARECEM como tile (nenhum some da galeria).
    expect($contents)->toHaveCount(4);

    // Aberto (pago p/ não-assinante) e Premium (avulso): compráveis, bloqueados, e
    // CRÍTICO — sem bytes (paywall server-side, não blur de CSS).
    foreach (['open', 'premium'] as $lvl) {
        expect($contents[$lvl]['locked'])->toBeTrue()
            ->and($contents[$lvl]['can_unlock'])->toBeTrue()
            ->and($contents[$lvl]['image_url'])->toBeNull()
            ->and($contents[$lvl]['required_tier_label'])->toBeNull();
    }
    expect($contents['premium']['price_tokens'])->toBe(20);

    // Exclusivo e FC Only: bloqueados, NÃO compráveis, com upsell do TIER (nunca o
    // nível de conteúdo), e sem bytes.
    expect($contents['exclusive']['can_unlock'])->toBeFalse()
        ->and($contents['exclusive']['required_tier_label'])->toBe('Black')
        ->and($contents['exclusive']['image_url'])->toBeNull()
        ->and($contents['fc_only']['can_unlock'])->toBeFalse()
        ->and($contents['fc_only']['required_tier_label'])->toBe('Círculo de Fundadores')
        ->and($contents['fc_only']['image_url'])->toBeNull();
});

it('mostra Aberto+Premium compraveis e Exclusivo bloqueado (upsell Black) ao Prestige', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    r2Publish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);

    $prestige = r2Member('prestige');

    $contents = collect(r2Contents(test()->actingAs($prestige)->get(route('catalog.show', $profile->slug))))
        ->keyBy('access_level');

    // Prestige vê os 3 tiles: Aberto grátis, Premium comprável, Exclusivo bloqueado.
    expect($contents)->toHaveCount(3)
        ->and($contents['open']['locked'])->toBeFalse()      // Aberto grátis p/ assinante
        ->and($contents['premium']['can_unlock'])->toBeTrue()
        ->and($contents['exclusive']['can_unlock'])->toBeFalse()
        ->and($contents['exclusive']['required_tier_label'])->toBe('Black');
});

it('na pagina publica o visitante deslogado ve TODOS os tiles, bloqueados e sem bytes', function () {
    $profile = r2Performer();
    r2Publish($profile, PerformerContent::LEVEL_OPEN);
    r2Publish($profile, PerformerContent::LEVEL_PREMIUM, 20);

    $contents = collect(r2Contents(test()->get(route('performers.public.show', $profile->slug))))
        ->keyBy('access_level');

    expect($contents)->toHaveCount(2)
        ->and($contents['open']['locked'])->toBeTrue()
        ->and($contents['open']['can_unlock'])->toBeFalse()   // deslogado não desbloqueia
        ->and($contents['open']['image_url'])->toBeNull()
        ->and($contents['premium']['locked'])->toBeTrue()
        ->and($contents['premium']['can_unlock'])->toBeFalse()
        ->and($contents['premium']['image_url'])->toBeNull();
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
    // Guarda de regressão: o AppLayout o monta incondicionalmente (ícone
    // desktop-only, escondido no celular — ver PanicButtonVisibilityTest).
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
