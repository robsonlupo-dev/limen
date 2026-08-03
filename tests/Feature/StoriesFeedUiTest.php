<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Carrossel de Stories no topo do catálogo — Sprint 13 (feed UI).
 *
 * A UI em si é Vue (coberta pelo build) e busca `stories.feed` por FETCH no mount
 * — NÃO como prop do catálogo, de propósito: `feedFor` roda `canView` por story e
 * servi-lo junto quebraria a garantia de N+1 do pontinho (StoryCatalogTest). O
 * que este arquivo trava é o CONTRATO que a UI consome do endpoint: a forma dos
 * grupos e o enriquecimento novo com `avatar_url` (URL assinada por `profile_id`,
 * nunca `user_id`), que o círculo precisa e que o payload não tinha.
 *
 * Helpers com prefixo `sf` — as funções do Pest são globais. `chatPerformer`/
 * `chatMember` vêm do Pest.php.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

function sfJpeg(): string
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 60, 120, 180));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function sfUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_sf_');
    file_put_contents($path, sfJpeg());

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

function sfStory(PerformerProfile $profile, string $visibility = 'public')
{
    return app(PerformerStoryService::class)->publish($profile, sfUpload(), $visibility);
}

function sfFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

/** Os grupos do feed como o endpoint que o carrossel consome os serve. */
function sfFeed(User $member): array
{
    return test()->actingAs($member)->getJson(route('stories.feed'))->assertOk()->json('performers');
}

// ─── O contrato do feed que o carrossel consome ──────────────────────────────

it('serve o feed agrupado por performer para o membro', function () {
    $performer = chatPerformer();
    $story = sfStory($performer, 'public');

    $member = chatMember();
    sfFollow($member, $performer);

    $feed = sfFeed($member);

    expect($feed)->toHaveCount(1)
        ->and($feed[0]['performer']['stage_name'])->toBe($performer->stage_name)
        ->and($feed[0]['stories'])->toHaveCount(1)
        ->and($feed[0]['stories'][0]['id'])->toBe($story->id)
        ->and($feed[0]['stories'][0]['seen'])->toBeFalse()
        ->and($feed[0]['has_unseen'])->toBeTrue();
});

it('serve o feed vazio para o membro sem stories', function () {
    // Sem follow não há candidato: o carrossel some inteiro (v-if no componente).
    expect(sfFeed(chatMember()))->toBe([]);
});

it('filtra o feed pelo que o membro pode ver (canView)', function () {
    $performer = chatPerformer();
    $publico = sfStory($performer, 'public');
    sfStory($performer, 'exclusive'); // membro sem tier NÃO alcança

    $member = chatMember();
    sfFollow($member, $performer);

    $feed = sfFeed($member);

    // Mesma regra do serving: só o público entra para quem não tem tier — não há
    // story bloqueado no feed (o paywall vive no strip do perfil).
    expect($feed[0]['stories'])->toHaveCount(1)
        ->and($feed[0]['stories'][0]['id'])->toBe($publico->id);
});

// ─── avatar_url ──────────────────────────────────────────────────────────────

it('inclui a avatar_url assinada quando a performer tem avatar', function () {
    $performer = chatPerformer();
    $performer->update(['avatar_path' => $performer->id.'/avatar.jpg']);
    sfStory($performer, 'public');

    $member = chatMember();
    sfFollow($member, $performer);

    $url = sfFeed($member)[0]['performer']['avatar_url'];

    // URL assinada, e pelo profile_id — nunca o user_id (mesma escolha do
    // PerformerPublicResource).
    expect($url)->toBeString()
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('profile_id='.$performer->id)
        ->and($url)->not->toContain('user_id');
});

it('traz avatar_url null quando a performer não tem avatar', function () {
    $performer = chatPerformer(); // sem avatar_path
    sfStory($performer, 'public');

    $member = chatMember();
    sfFollow($member, $performer);

    expect(sfFeed($member)[0]['performer']['avatar_url'])->toBeNull();
});

// ─── O catálogo NÃO paga o feed no caminho crítico ───────────────────────────

it('não serve o feed de stories como prop do catálogo (evita o N+1 do canView)', function () {
    // O feedFor roda canView por story; servi-lo como prop quebraria a garantia
    // de "uma query para o pontinho" do StoryCatalogTest. O carrossel busca por
    // fetch — então a prop NÃO existe no catálogo.
    $performer = chatPerformer();
    sfStory($performer, 'public');
    $member = chatMember();
    sfFollow($member, $performer);

    test()->actingAs($member)->get(route('catalog'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('storiesFeed'));
});

// ─── Privacidade: o feed não vaza id de membro ───────────────────────────────

it('o feed nunca carrega id de membro nem caminho de disco', function () {
    $performer = chatPerformer();
    $story = sfStory($performer, 'public');
    $member = chatMember();
    sfFollow($member, $performer);
    // Marca uma view para exercitar o ramo `seen`.
    test()->actingAs($member)->get(route('stories.image', $story->id))->assertOk();

    $feed = sfFeed($member->fresh());

    // O item do feed traz id de STORY, nível, seen, is_invite — nada de membro
    // nem de disco. (O varredor de substring do endpoint vive no StoryEndpointsTest.)
    expect($feed[0]['stories'][0])->not->toHaveKey('user_id')
        ->and($feed[0]['stories'][0])->not->toHaveKey('media_path')
        ->and($feed[0]['performer'])->not->toHaveKey('user_id');
});
