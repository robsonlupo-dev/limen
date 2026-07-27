<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Tags e campos "Sobre mim" da performer (Sprint 9).
 *
 * As tags vivem na junção `performer_tag`, não num json[] — decisão da ressalva
 * R8 do backlog (whereJsonContains não usa índice). O que estes testes travam é
 * a semântica da escrita (sync idempotente, limpeza deliberada), o teto de 8, e
 * o expurgo no Hard Delete, que NÃO sai pela FK porque o perfil é soft-delete.
 *
 * Helpers locais com prefixo pt*.
 */
function ptPerformer(string $stageName = 'Ana'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

it('saves the about-me fields from the edit screen', function () {
    $profile = ptPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'tags' => ['fitness', 'viajante', 'conversa'],
            'languages' => ['portugues', 'ingles'],
            'drinks' => 'bebe_socialmente',
            'smokes' => 'nao_fuma',
            'height_cm' => 170,
            'looking_for' => 'Conexões honestas e sem pressa.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();

    expect($profile->tagSlugs())->toEqualCanonicalizing(['fitness', 'viajante', 'conversa']);
    expect($profile->languages)->toBe(['portugues', 'ingles']);
    expect($profile->drinks)->toBe('bebe_socialmente');
    expect($profile->smokes)->toBe('nao_fuma');
    expect($profile->height_cm)->toBe(170);
    expect($profile->looking_for)->toBe('Conexões honestas e sem pressa.');
});

it('replaces the tag set instead of appending to it', function () {
    $profile = ptPerformer();
    $profile->syncTags(['fitness', 'viajante', 'luxo']);

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'tags' => ['fitness', 'yoga'],
        ])
        ->assertRedirect();

    expect($profile->fresh()->tagSlugs())->toEqualCanonicalizing(['fitness', 'yoga']);
});

it('clears every tag when the screen posts an empty selection', function () {
    $profile = ptPerformer();
    $profile->syncTags(['fitness', 'viajante']);

    // Desmarcar tudo é escolha deliberada, não "campo ausente". O controller
    // distingue os dois com array_key_exists — com `! empty` as tags antigas
    // sobreviveriam à limpeza.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'tags' => []])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->tagSlugs())->toBe([]);
});

it('leaves the tags untouched when the request omits them', function () {
    $profile = ptPerformer();
    $profile->syncTags(['fitness', 'viajante']);

    // O onboarding e a API postam o perfil sem o bloco "Sobre mim": ausente tem
    // que significar "não mexe", senão salvar por outra tela apaga as tags.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana'])
        ->assertRedirect();

    expect($profile->fresh()->tagSlugs())->toEqualCanonicalizing(['fitness', 'viajante']);
});

it('rejects more than the maximum number of tags', function () {
    $profile = ptPerformer();
    $tooMany = array_slice(PerformerProfile::allTags(), 0, PerformerProfile::MAX_TAGS + 1);

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'tags' => $tooMany])
        ->assertSessionHasErrors('tags');

    expect($profile->fresh()->tagSlugs())->toBe([]);
});

it('rejects a tag that is not in the catalog', function () {
    $profile = ptPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'tags' => ['fitness', 'nao_existe'],
        ])
        ->assertSessionHasErrors('tags.1');

    expect($profile->fresh()->tagSlugs())->toBe([]);
});

it('rejects the same tag twice instead of hitting the unique index', function () {
    $profile = ptPerformer();

    // Sem `distinct` na regra isto viraria Duplicate entry (500) no índice único
    // (performer_profile_id, tag_slug) em vez de erro de validação.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'tags' => ['fitness', 'fitness'],
        ])
        ->assertSessionHasErrors();

    expect($profile->fresh()->tagSlugs())->toBe([]);
});

it('rejects a height outside the slider range', function () {
    $profile = ptPerformer();

    foreach ([PerformerProfile::HEIGHT_MIN_CM - 1, PerformerProfile::HEIGHT_MAX_CM + 1] as $height) {
        $this->actingAs($profile->user)
            ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'height_cm' => $height])
            ->assertSessionHasErrors('height_cm');
    }

    expect($profile->fresh()->height_cm)->toBeNull();
});

it('rejects a drinks or smokes value outside the enum', function () {
    $profile = ptPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'drinks' => 'bebe_muito'])
        ->assertSessionHasErrors('drinks');

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'smokes' => 'charuto'])
        ->assertSessionHasErrors('smokes');
});

it('rejects a language outside the list', function () {
    $profile = ptPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'languages' => ['portugues', 'klingon'],
        ])
        ->assertSessionHasErrors('languages.1');
});

it('lets a tag be filtered through an indexed join instead of a json scan', function () {
    $withTag = ptPerformer('Ana');
    $withTag->syncTags(['fitness']);
    $withoutTag = ptPerformer('Bianca');
    $withoutTag->syncTags(['gourmet']);

    // A razão de ser da tabela de junção: este whereHas entra pelo índice de
    // tag_slug. Se as tags voltarem para json[], este teste continua passando
    // mas o plano vira full scan — o que ele trava é a relação, não o plano.
    $found = PerformerProfile::query()
        ->whereHas('tags', fn ($q) => $q->where('tag_slug', 'fitness'))
        ->pluck('stage_name')
        ->all();

    expect($found)->toBe(['Ana']);
});

it('exposes the about-me fields on the public catalog', function () {
    $profile = ptPerformer();
    $profile->syncTags(['fitness', 'gourmet']);
    $profile->update([
        'languages' => ['portugues'],
        'drinks' => 'nao_bebe',
        'smokes' => 'nao_fuma',
        'height_cm' => 168,
        'looking_for' => 'Algo leve.',
    ]);

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('performer.tags', fn ($tags) => collect($tags)->sort()->values()->all() === ['fitness', 'gourmet'])
            ->where('performer.languages', ['portugues'])
            ->where('performer.drinks', 'nao_bebe')
            ->where('performer.smokes', 'nao_fuma')
            ->where('performer.height_cm', 168)
            ->where('performer.looking_for', 'Algo leve.')
            ->etc()
        );
});

it('does not fire one query per card for the tags of a catalog page', function () {
    foreach (range(1, 5) as $i) {
        ptPerformer("Perf {$i}")->syncTags(['fitness', 'luxo']);
    }

    DB::enableQueryLog();
    $this->get(route('performers.public'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Com eager load a junção custa UMA query para a página inteira. Sem ele
    // seriam 5 a mais (uma por card) — e 24 numa página cheia.
    expect($queries)->toBeLessThan(15);
});

it('purges the tags on hard delete, which the foreign key never does', function () {
    $profile = ptPerformer();
    $profile->syncTags(['fitness', 'viajante']);

    expect(DB::table('performer_tag')->where('performer_profile_id', $profile->id)->count())->toBe(2);

    app(DeletionService::class)->executeDeletion($profile->user);

    // O perfil é soft-delete: a linha continua em performer_profiles, então o
    // cascadeOnDelete da FK NUNCA dispara. Quem apaga é o DeletionService.
    expect(DB::table('performer_tag')->where('performer_profile_id', $profile->id)->count())->toBe(0);
});

it('scrubs the about-me free text on hard delete', function () {
    $profile = ptPerformer();
    $profile->update([
        'languages' => ['portugues'],
        'drinks' => 'bebe_socialmente',
        'smokes' => 'fuma',
        'height_cm' => 175,
        'looking_for' => 'Texto livre do titular.',
    ]);

    app(DeletionService::class)->executeDeletion($profile->user);

    $row = DB::table('performer_profiles')->where('id', $profile->id)->first();

    expect($row->looking_for)->toBeNull();
    expect($row->languages)->toBeNull();
    expect($row->drinks)->toBeNull();
    expect($row->smokes)->toBeNull();
    expect($row->height_cm)->toBeNull();
});
