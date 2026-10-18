<?php

use App\Models\ContentSave;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\PerformerRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * "Parecidas com quem você salvou" (roadmap Fatal Model, Onda A). Prefixo `rc`.
 */
function rcMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function rcPerformer(string $world = 'mulheres', array $tags = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = 'Rec'.$n;

    $profile = $user->performerProfile()->create([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => $world,
        'worlds' => [$world],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    if ($tags !== []) {
        $profile->syncTags($tags);
    }

    return $profile;
}

function rcSaveContentOf(User $member, PerformerProfile $owner): void
{
    $content = new PerformerContent;
    $content->forceFill([
        'performer_profile_id' => $owner->id,
        'access_level' => 'open',
        'price_tokens' => 0,
        'path' => 'content/'.uniqid().'.jpg',
    ])->save();

    (new ContentSave)->forceFill([
        'user_id' => $member->id,
        'performer_content_id' => $content->id,
    ])->save();
}

/** @return list<int> */
function rcRecommendIds(User $member): array
{
    return app(PerformerRecommendationService::class)->similarToSaved($member)->pluck('id')->all();
}

it('recomenda por afinidade de tag e de mundo, excluindo as sementes e outro mundo', function () {
    $member = rcMember();
    $seed = rcPerformer('mulheres', ['fitness', 'yoga']);
    app(FavoriteService::class)->toggle($member, $seed);

    $tagMatch = rcPerformer('mulheres', ['fitness']); // tag em comum
    $worldMatch = rcPerformer('mulheres', []);        // só o mesmo mundo
    $other = rcPerformer('homens', ['gourmet']);      // mundo diferente, sem tag

    $ids = rcRecommendIds($member);

    expect($ids)
        ->toContain($tagMatch->id)
        ->toContain($worldMatch->id)
        ->not->toContain($seed->id)   // semente nunca é recomendada
        ->not->toContain($other->id); // fora do mundo e sem tag em comum

    // mais tags em comum vem antes de só-mundo
    expect(array_search($tagMatch->id, $ids, true))
        ->toBeLessThan(array_search($worldMatch->id, $ids, true));
});

it('sem favoritos nem salvos, não recomenda nada', function () {
    $member = rcMember();
    rcPerformer('mulheres', ['fitness']); // existe no catálogo, mas sem semente do membro

    expect(app(PerformerRecommendationService::class)->similarToSaved($member))->toBeEmpty();
});

it('a dona de uma peça salva vira semente (excluída) e sua afinidade puxa candidatas', function () {
    $member = rcMember();
    $owner = rcPerformer('mulheres', ['fitness']);
    rcSaveContentOf($member, $owner);

    $tagMatch = rcPerformer('mulheres', ['fitness']);

    $ids = rcRecommendIds($member);

    expect($ids)
        ->not->toContain($owner->id) // dona de peça salva é semente
        ->toContain($tagMatch->id);
});

it('visitante anônimo não recebe recomendação', function () {
    expect(app(PerformerRecommendationService::class)->similarToSaved(null))->toBeEmpty();
});

it('a página de Salvos entrega as recomendações ao membro', function () {
    $member = rcMember();
    app(FavoriteService::class)->toggle($member, rcPerformer('mulheres', ['fitness']));
    rcPerformer('mulheres', ['fitness']); // candidata

    test()->actingAs($member)->get(route('saved.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recommendations', 1));
});
