<?php

use App\Events\CatalogLivePresence;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\LiveSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * Pacote 2 do UAT Fase 9 — badge "AO VIVO" do catálogo sem F5. O
 * LiveSessionService transmite CatalogLivePresence a cada transição
 * (start/stop/reconciliação) nos canais `catalog.{world}` do perfil, e o
 * Catalog/Index assina o canal do mundo corrente. Helpers PRÓPRIOS (prefixo
 * `clp`), mesma disciplina do LiveConsoleTest.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
});

function clpFakeLiveKit(): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();
    $lk->shouldReceive('removeParticipant')->andReturnNull()->byDefault();

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function clpPerformer(array $worlds = ['mulheres']): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => $worlds[0],
        'worlds' => $worlds,
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

// ── Dispatch nas transições reais do service ─────────────────────────────────

it('start da live transmite catalog.live com live=true nos mundos do perfil', function () {
    clpFakeLiveKit();
    $performer = clpPerformer(['mulheres']);
    Event::fake([CatalogLivePresence::class]);

    app(LiveSessionService::class)->start($performer);

    Event::assertDispatched(CatalogLivePresence::class, fn (CatalogLivePresence $e) => $e->live === true
        && $e->slug === $performer->performerProfile->slug
        && $e->worlds === ['mulheres']);
});

it('stop da live transmite catalog.live com live=false', function () {
    clpFakeLiveKit();
    $performer = clpPerformer(['mulheres']);
    app(LiveSessionService::class)->start($performer);

    Event::fake([CatalogLivePresence::class]);
    app(LiveSessionService::class)->stop($performer->fresh());

    Event::assertDispatched(CatalogLivePresence::class, fn (CatalogLivePresence $e) => $e->live === false
        && $e->slug === $performer->performerProfile->slug);
});

// ── Forma do evento: canal por mundo, payload público, fail-closed ──────────

it('o evento sai em um canal privado catalog.{world} POR MUNDO do perfil', function () {
    $performer = clpPerformer(['mulheres', 'casais']);
    $event = CatalogLivePresence::fromProfile($performer->performerProfile, true);

    $channels = collect($event->broadcastOn())->map(fn ($c) => $c->name)->all();

    expect($channels)->toBe(['private-catalog.mulheres', 'private-catalog.casais'])
        ->and($event->broadcastAs())->toBe('catalog.live');
});

it('mundo fora da lista oficial nao ganha canal (fail-closed)', function () {
    $performer = clpPerformer(['mulheres']);
    $profile = $performer->performerProfile;
    $profile->forceFill(['worlds' => ['mulheres', 'mundo-inventado']])->save();

    $event = CatalogLivePresence::fromProfile($profile->fresh(), true);

    expect(collect($event->broadcastOn())->map(fn ($c) => $c->name)->all())
        ->toBe(['private-catalog.mulheres']);
});

it('o payload e o item publico da trilha Agora — e nunca o room_name', function () {
    $performer = clpPerformer();
    $profile = $performer->performerProfile;

    $with = CatalogLivePresence::fromProfile($profile, true)->broadcastWith();

    expect(array_keys($with))->toBe(['slug', 'stage_name', 'avatar_url', 'live'])
        ->and($with['slug'])->toBe($profile->slug)
        ->and($with['stage_name'])->toBe($profile->stage_name)
        ->and($with['avatar_url'])->toBeNull() // sem avatar_path → sem URL
        ->and($with['live'])->toBeTrue();
});

// ── Autorização do canal + front assinando certo (estáticas) ────────────────

it('channels.php registra catalog.{world} validando contra WORLDS', function () {
    $src = file_get_contents(base_path('routes/channels.php'));

    expect($src)->toContain("Broadcast::channel('catalog.{world}'")
        ->toContain('PerformerProfile::WORLDS');
});

it('o catalogo assina o canal do mundo corrente e LARGA o canal ao sair', function () {
    $src = file_get_contents(resource_path('js/Pages/Catalog/Index.vue'));

    expect($src)->toContain(".listen('.catalog.live', onCatalogLive)")
        // Canal próprio do catálogo → leave (não stopListening, que é a regra
        // dos canais COMPARTILHADOS como live.{slug}).
        ->toContain('window.Echo?.leave(`catalog.${world}`)')
        // Troca de mundo troca de canal.
        ->toContain('leaveCatalogChannel(prev)')
        // A trilha passa a renderizar o estado VIVO, não a prop estática.
        ->toContain(':lives="liveNow"');
});

it('a gorjeta escalona a animacao por valor (>=50 grande, >=100 festa)', function () {
    $src = file_get_contents(resource_path('js/Components/LiveOverlay.vue'));

    expect($src)->toContain('TIP_STYLE_BIG')
        ->toContain('TIP_STYLE_FESTA')
        ->toContain("amount >= 100")
        ->toContain("amount >= 50")
        ->toContain("item.anim === 'diamante' || item.anim === 'tipfesta'");
});
