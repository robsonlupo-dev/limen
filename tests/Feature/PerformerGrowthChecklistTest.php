<?php

use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\User;
use App\Support\PerformerGrowthChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Checklist de crescimento do painel (roadmap Fatal Model, Onda A). Prefixo `gc`.
 */
function gcPerformer(array $profileAttributes = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = 'Perf'.$n;

    return $user->performerProfile()->create(array_merge([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileAttributes));
}

function gcStory(PerformerProfile $p): PerformerStory
{
    $s = new PerformerStory;
    $s->forceFill([
        'performer_profile_id' => $p->id,
        'media_path' => 'stories/'.uniqid().'.jpg',
        'visibility_level' => 'public',
        'expires_at' => now()->addDay(),
    ])->save();

    return $s;
}

/** @return array<string, array> itens indexados por key */
function gcByKey(array $checklist): array
{
    return collect($checklist['items'])->keyBy('key')->all();
}

it('performer sem nada: itens presentes e todos pendentes', function () {
    $c = PerformerGrowthChecklist::for(gcPerformer());
    $items = gcByKey($c);

    expect($items)->toHaveKeys(['voz', 'capa', 'frase', 'stories_semana'])
        ->and($items['capa']['done'])->toBeFalse()
        ->and($items['frase']['done'])->toBeFalse()
        ->and($items['stories_semana']['done'])->toBeFalse()
        ->and($c['done'])->toBe(0);
});

it('marca capa e frase quando preenchidas', function () {
    $p = gcPerformer(['cover_path' => 'covers/x.jpg', 'headline' => 'Curadoria e discrição']);
    $items = gcByKey(PerformerGrowthChecklist::for($p));

    expect($items['capa']['done'])->toBeTrue()
        ->and($items['frase']['done'])->toBeTrue();
});

it('a meta de stories respeita o limiar semanal', function () {
    $p = gcPerformer();
    gcStory($p);
    gcStory($p);

    expect(gcByKey(PerformerGrowthChecklist::for($p))['stories_semana']['done'])->toBeFalse();

    gcStory($p); // chega a 3

    expect(gcByKey(PerformerGrowthChecklist::for($p))['stories_semana']['done'])->toBeTrue();
});

it('não conta stories de mais de 7 dias', function () {
    $p = gcPerformer();
    $s = gcStory($p);
    PerformerStory::where('id', $s->id)->update(['created_at' => now()->subDays(9)]);

    // os de hoje contam; o velho não
    gcStory($p);
    gcStory($p);

    expect(gcByKey(PerformerGrowthChecklist::for($p))['stories_semana']['done'])->toBeFalse();
});

it('o item "ao vivo" só aparece com a feature de live ligada', function () {
    config(['features.live_enabled' => false]);
    expect(gcByKey(PerformerGrowthChecklist::for(gcPerformer())))->not->toHaveKey('ao_vivo_mes');

    config(['features.live_enabled' => true]);
    expect(gcByKey(PerformerGrowthChecklist::for(gcPerformer())))->toHaveKey('ao_vivo_mes');
});

it('marca "ao vivo este mês" quando houve transmissão no mês', function () {
    config(['features.live_enabled' => true]);
    $p = gcPerformer();

    expect(gcByKey(PerformerGrowthChecklist::for($p))['ao_vivo_mes']['done'])->toBeFalse();

    LiveSession::create([
        'performer_profile_id' => $p->id,
        'room_name' => 'sala-'.uniqid(),
        'status' => 'ended',
        'viewer_count' => 0,
    ]);

    expect(gcByKey(PerformerGrowthChecklist::for($p))['ao_vivo_mes']['done'])->toBeTrue();
});

it('done/total refletem o progresso', function () {
    config(['features.live_enabled' => false]);
    $p = gcPerformer(['cover_path' => 'c.jpg', 'headline' => 'oi']);
    $c = PerformerGrowthChecklist::for($p);

    expect($c['total'])->toBe(4)   // voz, capa, frase, stories (sem live)
        ->and($c['done'])->toBe(2); // capa + frase
});

it('todo item traz um href para a tela onde se completa', function () {
    config(['features.live_enabled' => true]);
    $items = PerformerGrowthChecklist::for(gcPerformer())['items'];

    expect($items)->not->toBeEmpty();
    foreach ($items as $item) {
        expect($item)->toHaveKey('href')
            ->and($item['href'])->toBeString()
            ->and($item['href'])->not->toBeEmpty();
    }
});
