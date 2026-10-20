<?php

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Filtro "Com rosto" do catálogo (roadmap Fatal Model, Onda B §4.1). Prefixo `sf`.
 */
function sfPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = 'Face'.$n;

    return $user->performerProfile()->create([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function sfContent(PerformerProfile $p, bool $showsFace, string $status = 'ready'): void
{
    (new PerformerContent)->forceFill([
        'performer_profile_id' => $p->id,
        'access_level' => 'open',
        'price_tokens' => 0,
        'path' => 'content/'.uniqid().'.jpg',
        'status' => $status,
        'shows_face' => $showsFace,
    ])->save();
}

/** @return list<int> */
function sfIds(array $filters): array
{
    return collect(app(PerformerCatalogService::class)->publicSearch(null, $filters)->items())
        ->pluck('id')->all();
}

it('"Com rosto" mostra só quem tem peça PRONTA marcada com rosto', function () {
    $comRosto = sfPerformer();
    sfContent($comRosto, true);

    $semRosto = sfPerformer();
    sfContent($semRosto, false);

    $processando = sfPerformer();
    sfContent($processando, true, 'processing');

    $ids = sfIds(['has_face' => true]);

    expect($ids)
        ->toContain($comRosto->id)
        ->not->toContain($semRosto->id)       // tem peça, mas sem rosto
        ->not->toContain($processando->id);   // rosto, mas ainda não pronta
});

it('sem o filtro, mostra quem tem e quem não tem rosto', function () {
    $comRosto = sfPerformer();
    sfContent($comRosto, true);
    $semRosto = sfPerformer();
    sfContent($semRosto, false);

    $ids = sfIds([]);
    expect($ids)->toContain($comRosto->id)->toContain($semRosto->id);
});

it('a vitrine pública aceita o filtro has_face sem erro', function () {
    $p = sfPerformer();
    sfContent($p, true);

    test()->get(route('performers.public', ['has_face' => 1]))->assertOk();
});
