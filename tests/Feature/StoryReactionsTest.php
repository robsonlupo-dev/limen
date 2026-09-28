<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryReaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use App\Services\StoryReactionService;
use App\Support\StoryPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Reações rápidas a stories (roadmap social, Onda 1b).
 *
 * O eixo destes testes:
 *  1. **A reação usa a MESMA porta do serving** (§ 2.3): reagir a um story fora de
 *     alcance é 403, como a imagem — nada de escrever no conteúdo pago por fora.
 *  2. **O exclusivo não tem reação** (decisão nº 3): sem superfície de audiência,
 *     nem para o Black que ALCANÇA o story.
 *  3. **O que a performer vê é AGREGADO** (faixa + emojis), nunca "quem reagiu".
 *
 * Helpers com prefixo `sr` (story reactions) — as funções do Pest são globais.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function rxUpload(): UploadedFile
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 70, 130, 90));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    $path = tempnam(sys_get_temp_dir(), 'limen_sr_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

function rxPerformer(string $stage = 'Rê'): PerformerProfile
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

function rxMember(?string $circleSlug = null): User
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

function rxStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, rxUpload(), $visibility);
}

function rxFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

// ─── Reagir / toggle ─────────────────────────────────────────────────────────

it('reage a um story que o membro alcança e guarda a reação', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer); // Nível 1 exige seguir (ou ser Black).

    $this->actingAs($member)
        ->postJson(route('stories.react', $story->id), ['reaction' => 'fire'])
        ->assertOk()
        ->assertJsonPath('reaction', 'fire');

    expect(StoryReaction::where('performer_story_id', $story->id)->where('member_id', $member->id)->value('reaction'))
        ->toBe('fire');
});

it('faz toggle: a mesma reação remove, outra troca — uma linha por par', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);

    // Troca love → fire: uma linha só (UNIQUE do par).
    $this->actingAs($member)->postJson(route('stories.react', $story->id), ['reaction' => 'love'])->assertOk();
    $this->actingAs($member)->postJson(route('stories.react', $story->id), ['reaction' => 'fire'])
        ->assertOk()->assertJsonPath('reaction', 'fire');

    expect(StoryReaction::where('performer_story_id', $story->id)->count())->toBe(1);

    // Tocar fire de novo remove.
    $this->actingAs($member)->postJson(route('stories.react', $story->id), ['reaction' => 'fire'])
        ->assertOk()->assertJsonPath('reaction', null);

    expect(StoryReaction::where('performer_story_id', $story->id)->count())->toBe(0);
});

it('recusa reação inválida (422)', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);

    $this->actingAs($member)
        ->postJson(route('stories.react', $story->id), ['reaction' => 'thumbsdown'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reaction');
});

// ─── Paywall: a MESMA porta do serving ───────────────────────────────────────

it('recusa reação a story que o membro não alcança (403), como a imagem', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'subscribers');
    $member = rxMember(); // sem Círculo → não alcança o Nível 2.

    // Concorda com o serving: os dois negam.
    $this->actingAs($member)->get(route('stories.image', $story->id))->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('stories.react', $story->id), ['reaction' => 'love'])
        ->assertForbidden();

    expect(StoryReaction::count())->toBe(0);
});

it('não reage a story exclusivo NEM para o Black que o alcança (§2.2)', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'exclusive');
    $black = rxMember('black');

    // O Black VÊ o exclusivo…
    $this->actingAs($black)->get(route('stories.image', $story->id))->assertOk();

    // …mas não há superfície de reação nele: seria oráculo de "quem é Black".
    $this->actingAs($black)
        ->postJson(route('stories.react', $story->id), ['reaction' => 'love'])
        ->assertForbidden();

    expect(StoryReaction::count())->toBe(0);
});

it('recusa reação a story vencido (404), pela mesma regra da leitura', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);

    $story->forceFill(['expires_at' => now()->subHour()])->save();

    $this->actingAs($member)
        ->postJson(route('stories.react', $story->id), ['reaction' => 'love'])
        ->assertNotFound();
});

// ─── O que a performer vê é AGREGADO ─────────────────────────────────────────

it('mostra à performer faixa + emojis, nunca quem reagiu', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');

    // Dois membros reagem (fire, love).
    foreach (['fire', 'love'] as $i => $slug) {
        $m = rxMember();
        rxFollow($m, $performer);
        $this->actingAs($m)->postJson(route('stories.react', $story->id), ['reaction' => $slug])->assertOk();
    }

    $summary = StoryPresenter::one($story->fresh(), app(PerformerStoryService::class))['reactions'];

    expect($summary)->not->toBeNull()
        ->and($summary['label'])->toBe('Menos de 5')
        // Ordem do conjunto do config (love antes de fire).
        ->and($summary['kinds'])->toBe(['love', 'fire'])
        // AGREGADO: nada que identifique quem reagiu.
        ->and($summary)->not->toHaveKey('members')
        ->and(array_keys($summary))->toBe(['label', 'kinds']);
});

it('não devolve agregado de reação para story exclusivo (null, como o contador)', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'exclusive');

    // `forceCreate` porque `$fillable` é vazio (autoridade do servidor): planta uma
    // linha para provar que o exclusivo devolve null SEM sequer contar.
    StoryReaction::forceCreate([
        'performer_story_id' => $story->id,
        'member_id' => rxMember('black')->id,
        'reaction' => 'love',
    ]);

    expect(app(StoryReactionService::class)->summaryForOwner($story->fresh()))->toBeNull();
});

it('agregado é null quando ninguém reagiu (nunca zero)', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');

    expect(app(StoryReactionService::class)->summaryForOwner($story))->toBeNull();
});

// ─── Ghost Mode / Modo Discreto: o perk não aparece no agregado (§2.7) ───────

it('não grava reação de membro com Ghost Mode (não entra no agregado)', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember('black');
    rxFollow($member, $performer);
    $member->forceFill(['ghost_mode' => true])->save();

    // A resposta é a mesma em forma (200), mas nada é gravado — o perk compra
    // não deixar rastro, como a view que nunca marca "visto" para ele.
    $this->actingAs($member->fresh())
        ->postJson(route('stories.react', $story->id), ['reaction' => 'love'])
        ->assertOk()
        ->assertJsonPath('reaction', null);

    expect(StoryReaction::count())->toBe(0)
        ->and(app(StoryReactionService::class)->summaryForOwner($story->fresh()))->toBeNull();
});

it('não grava reação de membro em Modo Discreto', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);
    $member->forceFill(['discrete_mode' => true])->save();

    $this->actingAs($member->fresh())
        ->postJson(route('stories.react', $story->id), ['reaction' => 'fire'])
        ->assertOk()
        ->assertJsonPath('reaction', null);

    expect(StoryReaction::count())->toBe(0);
});

// ─── my_reaction no feed do próprio membro ───────────────────────────────────

it('devolve a reação do próprio membro no feed', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);

    $this->actingAs($member)->postJson(route('stories.react', $story->id), ['reaction' => 'wow'])->assertOk();

    $groups = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect($groups[0]['stories'][0]['my_reaction'])->toBe('wow');
});

it('o feed não vaza reação de OUTRO membro', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');

    $reactor = rxMember();
    rxFollow($reactor, $performer);
    $this->actingAs($reactor)->postJson(route('stories.react', $story->id), ['reaction' => 'love'])->assertOk();

    // Um segundo membro que segue e NÃO reagiu vê my_reaction null (é dado dele).
    $other = rxMember();
    rxFollow($other, $performer);

    $groups = $this->actingAs($other->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect($groups[0]['stories'][0]['my_reaction'])->toBeNull();
});

// ─── Retenção: reações morrem com o story ────────────────────────────────────

it('apaga as reações quando o story é destruído', function () {
    $performer = rxPerformer();
    $story = rxStory($performer, 'public');
    $member = rxMember();
    rxFollow($member, $performer);
    $this->actingAs($member)->postJson(route('stories.react', $story->id), ['reaction' => 'love'])->assertOk();

    expect(StoryReaction::count())->toBe(1);

    app(PerformerStoryService::class)->destroy($story->fresh());

    expect(StoryReaction::count())->toBe(0);
});
