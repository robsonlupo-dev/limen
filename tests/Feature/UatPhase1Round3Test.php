<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * UAT fase 1, round 3 — cinco achados:
 *  1. Live não iniciava ("camera is not allowed in this document"): o header
 *     Permissions-Policy barrava a câmera com `camera=()`.
 *  2. Panic button invisível (canto inferior): reposicionado ao topo direito.
 *  3. Avatar cortado no card: transbordo dentro da capa com overflow-hidden.
 *  4. Capa sem redimensionar: agora 1200x400 (3:1) server-side no upload.
 *  5. Performer levava 403 em /catalogo: virou redirect (302) ao painel.
 *
 * Helpers locais (prefixo r3*) para o arquivo rodar isolado.
 */
function r3Performer(string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create([
        'stage_name' => 'Ana R3',
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
    ]);
}

// ─── #1 Câmera liberada para a própria origem no Permissions-Policy ───────────

it('libera camera e microfone para a propria origem no Permissions-Policy', function () {
    $policy = test()->get('/')->headers->get('Permissions-Policy');

    // `camera=()` barrava o getUserMedia do <LiveRoom> no MESMO documento — a
    // live nunca começava. `self` libera só a origem da Limen, nunca terceiros.
    expect($policy)->toContain('camera=(self)')
        ->and($policy)->toContain('microphone=(self)')
        // Geolocalização segue barrada: a localização do produto é UF opt-in.
        ->and($policy)->toContain('geolocation=()');
});

// ─── #2 Panic button visível no topo direito ─────────────────────────────────

it('posiciona o panic button visivel no topo direito, discreto e com tooltip', function () {
    $src = File::get(resource_path('js/Components/PanicButton.vue'));

    expect($src)->toContain('fixed top-4 right-4')      // topo direito, perto do usuário
        ->and($src)->toContain('text-[#6f6a62]')        // cor discreta pedida pelo PO
        ->and($src)->toContain('title="Saída rápida"')  // tooltip no hover
        // Continua na camada de topo (invariante do PanicButtonLayerTest).
        ->and($src)->toContain('z-[10001]');
});

// ─── #3 Avatar do card fora do overflow-hidden da capa ───────────────────────

it('mantem o avatar do card com recorte circular e fixo (nao cortado)', function () {
    foreach (['PerformerCard', 'PublicPerformerCard'] as $component) {
        $src = File::get(resource_path("js/Components/{$component}.vue"));

        // Requisitos do PO: object-cover + rounded-full + dimensão fixa no
        // container do avatar. O transbordo (-bottom-6) deixou de ser cortado
        // porque o avatar virou irmão da capa (fora do overflow-hidden dela).
        expect($src)->toContain('h-14 w-14 rounded-full')
            ->and($src)->toContain('-bottom-6 left-4')
            ->and($src)->toContain('object-cover');
    }
});

// ─── #4 Capa redimensionada para 1200x400 (3:1) no upload ────────────────────

it('redimensiona a capa para 1200x400 (3:1) no upload, forcando a proporcao', function () {
    Storage::fake('local');
    $profile = r3Performer();

    // Foto retrato bem fora de 3:1 — o corte tem de forçar a proporção da capa.
    test()->actingAs($profile->user)->post(route('performer.profile.cover-photo'), [
        'file' => UploadedFile::fake()->image('retrato.jpg', 900, 1600),
    ])->assertRedirect();

    $profile->refresh();

    // Saída sempre JPEG → caminho fixo, sem depender da extensão do upload.
    expect($profile->cover_path)->toBe("performer-media/{$profile->user_id}/cover.jpg");

    $bytes = Storage::disk('local')->get($profile->cover_path);
    [$width, $height] = getimagesizefromstring($bytes);

    expect($width)->toBe(1200)->and($height)->toBe(400);
});

it('troca a capa antiga sem deixar orfao, mesmo mudando a extensao de origem', function () {
    Storage::fake('local');
    $profile = r3Performer();

    test()->actingAs($profile->user)->post(route('performer.profile.cover-photo'), [
        'file' => UploadedFile::fake()->image('primeira.png', 1200, 400),
    ])->assertRedirect();

    $first = $profile->fresh()->cover_path;

    test()->actingAs($profile->user)->post(route('performer.profile.cover-photo'), [
        'file' => UploadedFile::fake()->image('segunda.jpg', 1200, 400),
    ])->assertRedirect();

    // Mesmo caminho (cover.jpg) sobrescrito — nenhum arquivo antigo órfão.
    expect($profile->fresh()->cover_path)->toBe($first);
    Storage::disk('local')->assertExists($first);
});

// ─── #5 Performer redireciona em /catalogo (302, não 403) ────────────────────

it('redireciona a performer ativa de /catalogo para o painel', function () {
    $profile = r3Performer('active');

    test()->actingAs($profile->user)->get(route('catalog'))
        ->assertRedirect(route('performer.dashboard'));
});

it('redireciona a performer ainda em onboarding para o onboarding', function () {
    // Performer não-ativa (sem perfil pronto): cai no onboarding, não no painel
    // gateado por performer-active.
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);

    test()->actingAs($user)->get(route('catalog'))
        ->assertRedirect(route('performer.onboarding'));
});

it('mantem o admin barrado em /catalogo com 403', function () {
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    test()->actingAs($admin)->get(route('catalog'))->assertForbidden();
});

it('deixa o membro entrar em /catalogo', function () {
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);

    test()->actingAs($member)->get(route('catalog'))->assertOk();
});
