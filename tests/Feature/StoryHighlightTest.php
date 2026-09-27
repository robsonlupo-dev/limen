<?php

use App\Exceptions\HighlightException;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryHighlight;
use App\Models\User;
use App\Services\HighlightStore;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use App\Services\StoryHighlightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
    Storage::fake(HighlightStore::DISK);
});

// ── fixtures (prefixo hl) ────────────────────────────────────────────────────

function hlJpeg(): string
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 30, 120, 200));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function hlUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_hl_');
    file_put_contents($path, hlJpeg());

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

function hlPerformer(string $stage = 'Ana'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stage,
        'slug' => PerformerProfile::generateSlug($stage),
        'bio' => 'Bio',
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function hlStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, hlUpload(), $visibility);
}

function hlSvc(): StoryHighlightService
{
    return app(StoryHighlightService::class);
}

// ── Serviço ──────────────────────────────────────────────────────────────────

it('cria coleção e respeita o teto', function () {
    config()->set('stories.highlights.max_collections_per_performer', 2);
    $profile = hlPerformer();

    hlSvc()->createCollection($profile, 'Ensaios');
    hlSvc()->createCollection($profile, 'Viagens');
    expect($profile->highlights()->count())->toBe(2);

    expect(fn () => hlSvc()->createCollection($profile, 'Mais'))
        ->toThrow(HighlightException::class);
});

it('adiciona um story público: copia bytes e vira item com capa', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $story = hlStory($profile, 'public');

    $item = hlSvc()->addStory($profile, $collection, $story);

    expect($item->source_story_id)->toBe($story->id)
        ->and(Storage::disk(HighlightStore::DISK)->exists($item->media_path))->toBeTrue();

    $view = hlSvc()->publicView($profile->fresh());
    expect($view)->toHaveCount(1)
        ->and($view[0]['title'])->toBe('Ensaios')
        ->and($view[0]['cover_url'])->toContain((string) $item->id)
        ->and($view[0]['items'])->toHaveCount(1);
});

it('recusa story NÃO-público (paywall intacto)', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'VIP');
    $story = hlStory($profile, 'subscribers');

    expect(fn () => hlSvc()->addStory($profile, $collection, $story))
        ->toThrow(HighlightException::class);
});

it('recusa story de outra performer', function () {
    $me = hlPerformer('Ana');
    $other = hlPerformer('Bia');
    $collection = hlSvc()->createCollection($me, 'Ensaios');
    $storyDelaOutra = hlStory($other, 'public');

    expect(fn () => hlSvc()->addStory($me, $collection, $storyDelaOutra))
        ->toThrow(HighlightException::class);
});

it('recusa coleção de outra performer', function () {
    $me = hlPerformer('Ana');
    $other = hlPerformer('Bia');
    $colDoOutro = hlSvc()->createCollection($other, 'Dele');
    $meuStory = hlStory($me, 'public');

    expect(fn () => hlSvc()->addStory($me, $colDoOutro, $meuStory))
        ->toThrow(HighlightException::class);
});

it('respeita o teto de itens por coleção', function () {
    config()->set('stories.highlights.max_items_per_collection', 1);
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');

    hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));

    expect(fn () => hlSvc()->addStory($profile, $collection->fresh(), hlStory($profile, 'public')))
        ->toThrow(HighlightException::class);
});

it('remove um item: apaga bytes e linha', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $item = hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));
    $path = $item->media_path;

    hlSvc()->removeItem($profile, $item);

    expect(Storage::disk(HighlightStore::DISK)->exists($path))->toBeFalse()
        ->and($collection->items()->count())->toBe(0);
});

it('apaga a coleção inteira com os bytes', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $item = hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));
    $path = $item->media_path;

    hlSvc()->deleteCollection($profile, $collection);

    expect(StoryHighlight::count())->toBe(0)
        ->and(Storage::disk(HighlightStore::DISK)->exists($path))->toBeFalse();
});

// ── HTTP (performer) ─────────────────────────────────────────────────────────

it('a performer gerencia destaques pelos endpoints', function () {
    $profile = hlPerformer();
    $story = hlStory($profile, 'public');

    // criar
    $this->actingAs($profile->user)
        ->postJson(route('performer.highlights.store'), ['title' => 'Ensaios'])
        ->assertOk()->assertJsonCount(1, 'highlights');

    $collection = $profile->highlights()->first();

    // adicionar
    $this->actingAs($profile->user)
        ->postJson(route('performer.highlights.add-story', [$collection->id, $story->id]))
        ->assertOk();
    expect($collection->items()->count())->toBe(1);

    // apagar coleção
    $this->actingAs($profile->user)
        ->deleteJson(route('performer.highlights.destroy', $collection->id))
        ->assertOk()->assertJsonCount(0, 'highlights');
});

it('recusa story não-público pelo endpoint (422)', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'VIP');
    $story = hlStory($profile, 'subscribers');

    $this->actingAs($profile->user)
        ->postJson(route('performer.highlights.add-story', [$collection->id, $story->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', HighlightException::NOT_PUBLIC);
});

it('performer não mexe em coleção de outra (403)', function () {
    $me = hlPerformer('Ana');
    $other = hlPerformer('Bia');
    $colDoOutro = hlSvc()->createCollection($other, 'Dele');

    $this->actingAs($me->user)
        ->deleteJson(route('performer.highlights.destroy', $colDoOutro->id))
        ->assertStatus(403);
});

// ── Serving + exposição pública ──────────────────────────────────────────────

it('serve os bytes do item publicamente', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $item = hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));

    $this->get(route('highlights.image', $item->id))->assertOk();
});

it('para de servir os bytes quando a performer é suspensa (404)', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $item = hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));

    // Antes: de pé → serve.
    $this->get(route('highlights.image', $item->id))->assertOk();

    // Moderação suspende a conta: mídia permanente precisa PARAR de sair.
    $profile->user->forceFill(['status' => 'suspended'])->save();

    $this->get(route('highlights.image', $item->id))->assertNotFound();
});

it('para de servir os bytes quando a conta é encerrada (404)', function () {
    $profile = hlPerformer();
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    $item = hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));

    $profile->user->delete(); // soft delete (item 11 do CLAUDE.md)

    $this->get(route('highlights.image', $item->id))->assertNotFound();
});

it('o perfil público expõe os destaques', function () {
    $profile = hlPerformer('Aurora');
    $collection = hlSvc()->createCollection($profile, 'Ensaios');
    hlSvc()->addStory($profile, $collection, hlStory($profile, 'public'));

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('highlights', 1)
            ->where('highlights.0.title', 'Ensaios'));
});

it('coleção vazia não aparece na visão pública', function () {
    $profile = hlPerformer('Aurora');
    hlSvc()->createCollection($profile, 'Vazia');

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('highlights', 0));
});
