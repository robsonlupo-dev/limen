<?php

use App\Models\PerformerProfile;
use App\Models\PerformerVoiceIntro;
use App\Models\User;
use App\Services\PerformerCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Filtro "Com voz" do catálogo (roadmap Fatal Model, Onda A). Prefixo `vf`.
 */
function vfPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = 'Voice'.$n;

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

function vfGiveVoice(PerformerProfile $p, string $status = PerformerVoiceIntro::STATUS_APPROVED): void
{
    (new PerformerVoiceIntro)->forceFill([
        'performer_profile_id' => $p->id,
        'path' => 'voice/'.uniqid().'.mp3',
        'status' => $status,
    ])->save();
}

/** @return list<int> ids devolvidos pela vitrine pública com os filtros dados */
function vfIds(array $filters): array
{
    return collect(app(PerformerCatalogService::class)->publicSearch(null, $filters)->items())
        ->pluck('id')->all();
}

it('sem o filtro, mostra quem tem e quem não tem voz', function () {
    $comVoz = vfPerformer();
    vfGiveVoice($comVoz);
    $semVoz = vfPerformer();

    $ids = vfIds([]);
    expect($ids)->toContain($comVoz->id)->toContain($semVoz->id);
});

it('"Com voz" mostra só quem tem intro de voz aprovada', function () {
    $comVoz = vfPerformer();
    vfGiveVoice($comVoz);
    $semVoz = vfPerformer();

    $ids = vfIds(['has_voice' => true]);
    expect($ids)->toContain($comVoz->id)->not->toContain($semVoz->id);
});

it('voz em processamento, pendente ou rejeitada NÃO conta como "Com voz"', function () {
    $proc = vfPerformer();
    vfGiveVoice($proc, 'pending');
    $rej = vfPerformer();
    vfGiveVoice($rej, 'rejected');

    expect(vfIds(['has_voice' => true]))
        ->not->toContain($proc->id)
        ->not->toContain($rej->id);
});

it('a vitrine pública aceita o filtro has_voice sem erro', function () {
    $p = vfPerformer();
    vfGiveVoice($p);

    test()->get(route('performers.public', ['has_voice' => 1]))->assertOk();
});
