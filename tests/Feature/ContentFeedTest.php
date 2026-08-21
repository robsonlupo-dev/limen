<?php

use App\Models\Follow;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Feed de conteúdo permanente do membro (Sprint 16), consumidor do PR #135.
// Reusa ContentVisibilityService (paywall/tier) e content.unlock (desbloqueio).
// Helpers locais (feed*) para o arquivo rodar isolado.

function feedPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function feedMember(?string $tier = null): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($tier !== null) {
        Subscription::factory()->for($user)->circle($tier)->create();
        $user->refresh();
    }

    return $user;
}

function feedPublish(PerformerProfile $profile, string $level = PerformerContent::LEVEL_OPEN, int $price = 10): PerformerContent
{
    return app(PerformerContentService::class)->publish(
        $profile,
        UploadedFile::fake()->image('c.jpg', 400, 400),
        $level,
        $price,
    );
}

function feedFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

// ─── Conteúdo de quem o membro segue, mais recente primeiro ──────────────────

it('mostra o conteúdo das performers seguidas, mais recente primeiro', function () {
    $member = feedMember();
    $a = feedPerformer();
    $b = feedPerformer();
    feedFollow($member, $a);
    feedFollow($member, $b);

    $old = feedPublish($a);
    $new = feedPublish($b); // id maior → mais recente

    $this->actingAs($member)->get(route('feed'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Feed')
            ->has('feed.data', 2)
            ->where('feed.data.0.id', $new->id)
            ->where('feed.data.1.id', $old->id)
            ->where('feed.data.0.performer.slug', $b->slug)
            ->where('followsAnyone', true)
        );
});

it('não mostra conteúdo de performer que o membro NÃO segue', function () {
    $member = feedMember();
    $followed = feedPerformer();
    $stranger = feedPerformer();
    feedFollow($member, $followed);

    $mine = feedPublish($followed);
    feedPublish($stranger); // não seguida — não deve aparecer

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 1)
            ->where('feed.data.0.id', $mine->id)
        );
});

// ─── Gate de tier (M.13.13): só os níveis que o tier alcança APARECEM ────────

it('esconde do não-assinante os níveis TRAVADOS que o tier não alcança', function () {
    $member = feedMember(); // sem tier
    $performer = feedPerformer();
    feedFollow($member, $performer);

    $open = feedPublish($performer, PerformerContent::LEVEL_OPEN);
    feedPublish($performer, PerformerContent::LEVEL_EXCLUSIVE, 20); // exige Black+ (travado)

    // No FEED, só o Aberto aparece — Exclusivo fica fora da query (allowedLevelsFor).
    // (Premium virou avulso e agora APARECE no feed; Exclusivo/FC seguem travados.)
    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 1)
            ->where('feed.data.0.id', $open->id)
            ->where('feed.data.0.access_level', 'open')
        );
});

it('mostra Premium (compra avulsa) no feed até para o não-assinante', function () {
    $member = feedMember(); // sem tier
    $performer = feedPerformer();
    feedFollow($member, $performer);

    feedPublish($performer, PerformerContent::LEVEL_OPEN);
    feedPublish($performer, PerformerContent::LEVEL_PREMIUM, 20); // avulso desde 21/08/2026

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page->has('feed.data', 2));
});

it('mostra Premium para o assinante Prestige', function () {
    $member = feedMember('prestige');
    $performer = feedPerformer();
    feedFollow($member, $performer);

    feedPublish($performer, PerformerContent::LEVEL_OPEN);
    feedPublish($performer, PerformerContent::LEVEL_PREMIUM, 20);

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page->has('feed.data', 2));
});

// ─── Paywall: peça bloqueada NÃO traz image_url ──────────────────────────────

it('não entrega image_url de peça bloqueada (blur não é paywall)', function () {
    $member = feedMember(); // não-assinante paga o Aberto
    $performer = feedPerformer();
    feedFollow($member, $performer);
    feedPublish($performer, PerformerContent::LEVEL_OPEN);

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('feed.data.0.locked', true)
            ->where('feed.data.0.image_url', null)
            ->where('feed.data.0.can_unlock', true)
        );
});

// ─── Performer banida/suspensa não aparece no feed ───────────────────────────

it('não mostra conteúdo de performer suspensa', function () {
    $member = feedMember();
    $performer = feedPerformer();
    feedFollow($member, $performer);
    feedPublish($performer);

    // Suspende a conta da performer — sai do publicCatalog (SQL), some do feed.
    // status fica FORA do $fillable (ato de autoridade), então forceFill.
    $performer->user->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page->has('feed.data', 0));
});

// ─── Estados vazios ──────────────────────────────────────────────────────────

it('estado vazio quando o membro não segue ninguém', function () {
    $member = feedMember();

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 0)
            ->where('followsAnyone', false)
        );
});

it('segue alguém mas sem conteúdo publicado: vazio, followsAnyone true', function () {
    $member = feedMember();
    feedFollow($member, feedPerformer());

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 0)
            ->where('followsAnyone', true)
        );
});

// ─── Paginação (12 por página) ───────────────────────────────────────────────

it('pagina o feed em 12 por página', function () {
    $member = feedMember();
    $performer = feedPerformer();
    feedFollow($member, $performer);

    foreach (range(1, 13) as $i) {
        feedPublish($performer, PerformerContent::LEVEL_OPEN);
    }

    $this->actingAs($member)->get(route('feed'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 12)
            ->where('feed.current_page', 1)
            ->where('feed.last_page', 2)
            ->where('feed.has_more', true)
        );

    $this->actingAs($member)->get(route('feed', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed.data', 1)
            ->where('feed.has_more', false)
        );
});

// ─── Gate de acesso: só membro ───────────────────────────────────────────────

it('não deixa o visitante deslogado ver o feed', function () {
    $this->get(route('feed'))->assertRedirect(route('login'));
});

it('não deixa a performer ver o feed do membro', function () {
    $perf = feedPerformer();

    // role:consumer barra a performer — nunca 200.
    $response = $this->actingAs($perf->user)->get(route('feed'));
    expect($response->status())->not->toBe(200);
});
