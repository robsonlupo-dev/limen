<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryInteraction;
use App\Models\StoryPollVote;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use App\Services\StoryInteractionService;
use App\Support\StoryPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Enquete no story (roadmap social, Onda 1b).
 *
 * Eixos: (1) prender a enquete é só da DONA, não no exclusivo, texto filtrado;
 * (2) votar usa a MESMA porta do serving (paywall), é imutável, e Ghost/Discreto
 * não entram no agregado; (3) o que a performer vê é AGREGADO (distribuição),
 * nunca "quem votou". Helpers com prefixo `pl` (poll).
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function plUpload(): UploadedFile
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 120, 80, 160));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    $path = tempnam(sys_get_temp_dir(), 'limen_pl_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

function plPerformer(string $stage = 'Pô'): PerformerProfile
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

function plMember(?string $circleSlug = null): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);

    if ($circleSlug !== null) {
        Subscription::factory()->circle($circleSlug)->create([
            'user_id' => $member->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);
    }

    return $member->fresh();
}

function plStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, plUpload(), $visibility);
}

function plFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

/** Prende uma enquete pelo service (atalho de setup). */
function plAttach(PerformerProfile $profile, PerformerStory $story, array $options = ['A', 'B']): StoryInteraction
{
    return app(StoryInteractionService::class)->attach($profile, $story, 'Qual?', $options);
}

// ─── Performer: prender / remover ────────────────────────────────────────────

it('a performer prende uma enquete no story dela', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');

    $this->actingAs($profile->user)
        ->postJson(route('performer.stories.poll.store', $story->id), [
            'prompt' => 'Praia ou estúdio?',
            'options' => ['Praia', 'Estúdio'],
        ])
        ->assertCreated()
        ->assertJsonPath('poll.prompt', 'Praia ou estúdio?')
        ->assertJsonPath('poll.options', ['Praia', 'Estúdio']);

    expect(StoryInteraction::where('performer_story_id', $story->id)->value('type'))->toBe('poll');
});

it('recusa enquete em story de outra performer (403)', function () {
    $me = plPerformer('Ana');
    $other = plPerformer('Bia');
    $storyDela = plStory($other, 'public');

    $this->actingAs($me->user)
        ->postJson(route('performer.stories.poll.store', $storyDela->id), ['prompt' => 'Q', 'options' => ['A', 'B']])
        ->assertStatus(403);

    expect(StoryInteraction::count())->toBe(0);
});

it('recusa enquete em story exclusivo (403) — sem superfície de audiência', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'exclusive');

    $this->actingAs($profile->user)
        ->postJson(route('performer.stories.poll.store', $story->id), ['prompt' => 'Q', 'options' => ['A', 'B']])
        ->assertStatus(403);

    expect(StoryInteraction::count())->toBe(0);
});

it('recusa número de opções fora do intervalo (422)', function (array $options) {
    $profile = plPerformer();
    $story = plStory($profile, 'public');

    $this->actingAs($profile->user)
        ->postJson(route('performer.stories.poll.store', $story->id), ['prompt' => 'Q', 'options' => $options])
        ->assertStatus(422)
        ->assertJsonValidationErrors('options');
})->with([
    'uma só' => [['Só uma']],
    'cinco' => [['A', 'B', 'C', 'D', 'E']],
]);

it('aplica o filtro anti-contato nas opções da enquete (422)', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');

    // Uma opção tentando vazar telefone — SafeProfileText recusa, como na bio.
    $this->actingAs($profile->user)
        ->postJson(route('performer.stories.poll.store', $story->id), [
            'prompt' => 'Me chama?',
            'options' => ['Sim', 'meu zap 11 98888-7777'],
        ])
        ->assertStatus(422);

    expect(StoryInteraction::count())->toBe(0);
});

it('substitui a enquete anterior (uma por story)', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');

    plAttach($profile, $story, ['A', 'B']);
    plAttach($profile, $story, ['X', 'Y', 'Z']);

    expect(StoryInteraction::where('performer_story_id', $story->id)->count())->toBe(1)
        ->and(StoryInteraction::where('performer_story_id', $story->id)->value('options'))->toBe(['X', 'Y', 'Z']);
});

it('a performer remove a enquete', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story);

    $this->actingAs($profile->user)
        ->deleteJson(route('performer.stories.poll.destroy', $story->id))
        ->assertOk();

    expect(StoryInteraction::count())->toBe(0);
});

// ─── Membro: votar ───────────────────────────────────────────────────────────

it('o membro vota e recebe a distribuição', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['Praia', 'Estúdio']);
    $member = plMember();
    plFollow($member, $profile);

    $this->actingAs($member)
        ->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])
        ->assertOk()
        ->assertJsonPath('my_vote', 0)
        ->assertJsonPath('results', [1, 0])
        ->assertJsonPath('total', 1);

    expect(StoryPollVote::count())->toBe(1);
});

it('voto é imutável: votar de novo devolve o voto original', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember();
    plFollow($member, $profile);

    $this->actingAs($member)->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])->assertOk();

    // Tenta trocar para 1 → continua 0, uma linha só.
    $this->actingAs($member)
        ->postJson(route('stories.poll.vote', $story->id), ['option_index' => 1])
        ->assertOk()
        ->assertJsonPath('my_vote', 0)
        ->assertJsonPath('results', [1, 0]);

    expect(StoryPollVote::count())->toBe(1);
});

it('recusa voto em story que o membro não alcança (403), como a imagem', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'subscribers');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember(); // sem Círculo.

    $this->actingAs($member)->get(route('stories.image', $story->id))->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])
        ->assertForbidden();

    expect(StoryPollVote::count())->toBe(0);
});

it('recusa opção fora do intervalo com 422 limpo (não 500 de HTML)', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember();
    plFollow($member, $profile);

    // option_index 5 não existe (só 0 e 1). O Form Request passa (integer>=0), o
    // teto real é do service; o controller traduz para 422 (rota web → JSON), nunca
    // um 500 de HTML que o fetch do front não sabe ler.
    $this->actingAs($member)
        ->postJson(route('stories.poll.vote', $story->id), ['option_index' => 5])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_option');

    expect(StoryPollVote::count())->toBe(0);
});

it('não grava voto de membro com Ghost Mode (não entra no agregado)', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember('black');
    plFollow($member, $profile);
    $member->forceFill(['ghost_mode' => true])->save();

    // Resposta normal (200) com a visão de resultado, mas nada gravado.
    $this->actingAs($member->fresh())
        ->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])
        ->assertOk()
        ->assertJsonPath('results', [0, 0]);

    expect(StoryPollVote::count())->toBe(0);
});

it('retira o voto antigo quando o membro fica invisível (Ghost) e revota', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    $interaction = plAttach($profile, $story, ['A', 'B']);
    $member = plMember('black');
    plFollow($member, $profile);
    $member->forceFill(['ghost_mode' => true])->save();

    // Simula um voto feito ANTES de o perk valer (linha plantada direto — o Black
    // nasce com Ghost por padrão, então não dá para gravá-lo pelo endpoint agora).
    StoryPollVote::forceCreate([
        'story_interaction_id' => $interaction->id,
        'member_id' => $member->id,
        'option_index' => 0,
    ]);
    expect(StoryPollVote::count())->toBe(1);

    // Já invisível, toca de novo: o voto antigo sai do agregado (paridade com reação).
    $this->actingAs($member->fresh())->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])->assertOk();

    expect(StoryPollVote::count())->toBe(0);
});

// ─── Agregado e revelação ────────────────────────────────────────────────────

it('resultados só aparecem no feed depois de votar', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember();
    plFollow($member, $profile);

    // Antes de votar: opções sim, resultados não.
    $before = $this->actingAs($member->fresh())->getJson(route('stories.feed'))->json('performers.0.stories.0.interaction');
    expect($before['options'])->toBe(['A', 'B'])
        ->and($before['my_vote'])->toBeNull()
        ->and($before['results'])->toBeNull();

    $this->actingAs($member->fresh())->postJson(route('stories.poll.vote', $story->id), ['option_index' => 1])->assertOk();

    // Depois: resultados revelados.
    $after = $this->actingAs($member->fresh())->getJson(route('stories.feed'))->json('performers.0.stories.0.interaction');
    expect($after['my_vote'])->toBe(1)
        ->and($after['results'])->toBe([0, 1])
        ->and($after['total'])->toBe(1);
});

it('o painel da performer vê a distribuição, nunca quem votou', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);

    foreach ([0, 0, 1] as $i => $opt) {
        $m = plMember();
        plFollow($m, $profile);
        $this->actingAs($m)->postJson(route('stories.poll.vote', $story->id), ['option_index' => $opt])->assertOk();
    }

    $poll = StoryPresenter::one($story->fresh(), app(PerformerStoryService::class))['poll'];

    expect($poll['results'])->toBe([2, 1])
        ->and($poll['total'])->toBe(3)
        // AGREGADO: nada que identifique quem votou.
        ->and(array_keys($poll))->toBe(['type', 'prompt', 'options', 'results', 'total']);
});

it('não expõe id de membro nas props da enquete no perfil', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $voter = plMember();
    plFollow($voter, $profile);
    $this->actingAs($voter)->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])->assertOk();

    $content = $this->actingAs($voter->fresh())
        ->get(route('catalog.show', $profile->slug))
        ->getContent();

    expect($content)->not->toContain('member_id')
        ->not->toContain('story_poll_votes');
});

// ─── Retenção ────────────────────────────────────────────────────────────────

it('apaga enquete e votos quando o story é destruído', function () {
    $profile = plPerformer();
    $story = plStory($profile, 'public');
    plAttach($profile, $story, ['A', 'B']);
    $member = plMember();
    plFollow($member, $profile);
    $this->actingAs($member)->postJson(route('stories.poll.vote', $story->id), ['option_index' => 0])->assertOk();

    expect(StoryInteraction::count())->toBe(1)->and(StoryPollVote::count())->toBe(1);

    app(PerformerStoryService::class)->destroy($story->fresh());

    expect(StoryInteraction::count())->toBe(0)->and(StoryPollVote::count())->toBe(0);
});
