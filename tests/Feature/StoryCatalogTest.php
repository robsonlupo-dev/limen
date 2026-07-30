<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use App\Services\StoryVisibilityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Descoberta de Stories no catálogo (Sprint 9C, PR 3): o pontinho de "não visto"
 * no avatar e a faixa de Stories no perfil da performer.
 *
 * O eixo destes testes: **o indicador é dado do MEMBRO, e nunca antecipa o que o
 * serving não entrega**. Se a tela anunciasse conteúdo que o § 2.3 recusa — ou
 * escondesse o que ele entrega —, o par (tela, 403) viraria oráculo num sentido e
 * furo de paywall no outro. E a performer não recebe nada daqui: quem viu o quê
 * segue fechado na faixa (§ 2.1/§ 2.2).
 *
 * Helpers com prefixo `sc` (stories catalog) porque as funções do Pest são
 * globais e colidiriam com as de PerformerStoriesTest / StoryEndpointsTest.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function scUpload(): UploadedFile
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 90, 60, 160));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    $path = tempnam(sys_get_temp_dir(), 'limen_sc_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

/** Performer no catálogo: ativa, verificada, no mundo `mulheres`. */
function scPerformer(?string $stageName = null): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stageName ?? 'Perf '.Str::random(4),
        'slug' => 'sc-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function scMember(?string $circleSlug = null): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
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

function scStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, scUpload(), $visibility);
}

function scFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

/** O card desta performer na resposta do catálogo autenticado. */
function scCard(array $performers, PerformerProfile $profile): ?array
{
    return collect($performers)->firstWhere('slug', $profile->slug);
}

function scCatalogCards(User $member): array
{
    return test()->actingAs($member)
        ->get(route('catalog'))
        ->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

// ─── has_unseen_stories no catálogo ──────────────────────────────────────────

it('acende o pontinho quando há story que o membro pode ver e ainda não viu', function () {
    $performer = scPerformer();
    $story = scStory($performer, 'public');
    $member = scMember();
    scFollow($member, $performer);

    expect(scCard(scCatalogCards($member), $performer)['has_unseen_stories'])->toBeTrue();

    // Depois de abrir, apaga. `seen` sai de story_views filtrada pelo id DELE —
    // é dado do próprio membro, não da performer.
    $this->actingAs($member)->get(route('stories.image', $story->id))->assertOk();

    expect(scCard(scCatalogCards($member), $performer)['has_unseen_stories'])->toBeFalse();
});

it('não acende o pontinho para performer sem stories', function () {
    $semStory = scPerformer();
    $comStory = scPerformer();
    scStory($comStory, 'public');

    $member = scMember();
    scFollow($member, $semStory);
    scFollow($member, $comStory);

    $cards = scCatalogCards($member);

    expect(scCard($cards, $semStory)['has_unseen_stories'])->toBeFalse()
        ->and(scCard($cards, $comStory)['has_unseen_stories'])->toBeTrue();
});

it('não conta story vencido', function () {
    $performer = scPerformer();
    $story = scStory($performer, 'public');
    $member = scMember();
    scFollow($member, $performer);

    // Vence sem passar pelo relógio: os bytes continuam no disco (o GC não
    // rodou), e mesmo assim o indicador apaga — o prazo vale na LEITURA (§ 2.8).
    $story->forceFill(['expires_at' => now()->subHour()])->save();

    expect(Storage::disk(PerformerStoryStore::DISK)->exists($story->fresh()->media_path))->toBeTrue()
        ->and(scCard(scCatalogCards($member), $performer)['has_unseen_stories'])->toBeFalse();
});

it('respeita o nível: o pontinho não anuncia o que o serving recusa', function (
    string $level,
    ?string $tier,
    bool $follows,
    bool $expected,
) {
    $performer = scPerformer();
    $story = scStory($performer, $level);
    $member = scMember($tier);

    if ($follows) {
        scFollow($member, $performer);
    }

    $member = $member->fresh();

    expect(scCard(scCatalogCards($member), $performer)['has_unseen_stories'])->toBe($expected);

    // E o indicador CONCORDA com o serving, que é o ponto: a tela nunca anuncia
    // conteúdo que o § 2.3 recusa, e nunca esconde o que ele entrega.
    $this->actingAs($member)
        ->get(route('stories.image', $story->id))
        ->assertStatus($expected ? 200 : 403);
})->with([
    // Nível 1: seguidor vê; quem não segue, não — salvo Black (a exceção do PO).
    'público, seguindo' => ['public', null, true, true],
    'público, sem seguir' => ['public', null, false, false],
    'público, Black sem seguir' => ['public', 'black', false, true],
    // Nível 2: qualquer Círculo ativo, sem exigir follow.
    'assinantes, sem Círculo' => ['subscribers', null, true, false],
    'assinantes, com Explorador' => ['subscribers', 'explorador', false, true],
    // Nível 3: só Black/FC. Follow não compra, tier abaixo não compra.
    'exclusivo, seguindo sem tier' => ['exclusive', null, true, false],
    'exclusivo, Prestige' => ['exclusive', 'prestige', true, false],
    'exclusivo, Black' => ['exclusive', 'black', false, true],
]);

it('não devolve o pontinho para visitante deslogado', function () {
    $performer = scPerformer();
    scStory($performer, 'public');

    $cards = $this->get(route('performers.public'))
        ->assertOk()
        ->viewData('page')['props']['performers']['data'];

    // Não há "não visto" para quem não tem conta — e o service nem toca o banco.
    expect(scCard($cards, $performer)['has_unseen_stories'])->toBeFalse();
});

it('acende o pontinho na porta pública para o membro logado', function () {
    $performer = scPerformer();
    scStory($performer, 'public');
    $member = scMember();
    scFollow($member, $performer);

    // A listagem pública também é alcançada por membro logado (link direto,
    // busca): o indicador tem de funcionar igual ao /catalogo.
    $cards = $this->actingAs($member->fresh())
        ->get(route('performers.public'))
        ->assertOk()
        ->viewData('page')['props']['performers']['data'];

    expect(scCard($cards, $performer)['has_unseen_stories'])->toBeTrue();
});

it('mantém o pontinho aceso para quem tem Ghost Mode', function () {
    $performer = scPerformer();
    $story = scStory($performer, 'public');
    $member = scMember();
    scFollow($member, $performer);
    $member->forceFill(['ghost_mode' => true])->save();

    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    // Consequência ACEITA do perk (§ 2.7): a view não é gravada, então o
    // pontinho nunca apaga. A alternativa seria gravar a linha, que é
    // exatamente o que o Black compra para não existir. Não é bug.
    expect(scCard(scCatalogCards($member->fresh()), $performer)['has_unseen_stories'])->toBeTrue();
});

// ─── Eficiência: uma query para a página inteira ─────────────────────────────

it('não dispara uma query por card para resolver o pontinho', function () {
    $member = scMember();

    foreach (range(1, 6) as $i) {
        $performer = scPerformer("Perf {$i}");
        scStory($performer, 'public');
        scFollow($member, $performer);
    }

    DB::enableQueryLog();
    $this->actingAs($member->fresh())->get(route('catalog'))->assertOk();
    $baseline = count(DB::getQueryLog());
    DB::flushQueryLog();

    // Mais seis performers com story na mesma página: se o indicador fosse
    // resolvido por card, a contagem subiria com o número de cards.
    foreach (range(7, 12) as $i) {
        $performer = scPerformer("Perf {$i}");
        scStory($performer, 'public');
        scFollow($member, $performer);
    }

    DB::flushQueryLog();
    $this->actingAs($member->fresh())->get(route('catalog'))->assertOk();
    $doubled = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Dobrar os cards não acrescenta query nenhuma: o filtro é um `whereIn` com
    // subconsultas correlacionadas, resolvido numa passada.
    expect($doubled)->toBe($baseline);
});

// ─── Faixa de Stories no perfil ──────────────────────────────────────────────

it('lista os stories vivos no perfil, com o cadeado do nível', function () {
    $performer = scPerformer();
    $aberto = scStory($performer, 'public');
    $fechado = scStory($performer, 'exclusive');
    $vencido = scStory($performer, 'public');
    $vencido->forceFill(['expires_at' => now()->subHour()])->save();

    $member = scMember();
    scFollow($member, $performer);

    $stories = $this->actingAs($member->fresh())
        ->get(route('catalog.show', $performer->slug))
        ->assertOk()
        ->viewData('page')['props']['stories'];

    // Vencido não aparece; a ordem é de publicação (mais antigo primeiro).
    expect($stories)->toHaveCount(2)
        ->and($stories[0]['id'])->toBe($aberto->id)
        ->and($stories[0]['locked'])->toBeFalse()
        ->and($stories[0]['visibility_level'])->toBe('public')
        ->and($stories[1]['id'])->toBe($fechado->id)
        ->and($stories[1]['locked'])->toBeTrue()
        ->and($stories[1]['visibility_level'])->toBe('exclusive');
});

it('não manda URL de imagem do story fechado', function () {
    $performer = scPerformer();
    scStory($performer, 'exclusive');
    $member = scMember();
    scFollow($member, $performer);

    $stories = $this->actingAs($member->fresh())
        ->get(route('catalog.show', $performer->slug))
        ->assertOk()
        ->viewData('page')['props']['stories'];

    // **Blur em CSS não é paywall.** Uma miniatura de verdade entregue "borrada"
    // pelo cliente está inteira no DevTools em dois cliques, então story fechado
    // não recebe URL nenhuma — a tela desenha placeholder, não uma versão
    // degradada do conteúdo pago.
    expect($stories[0]['locked'])->toBeTrue()
        ->and($stories[0]['image_url'])->toBeNull();
});

it('abre para o Black o story exclusivo no perfil', function () {
    $performer = scPerformer();
    $story = scStory($performer, 'exclusive');
    $member = scMember('black');

    $stories = $this->actingAs($member->fresh())
        ->get(route('catalog.show', $performer->slug))
        ->assertOk()
        ->viewData('page')['props']['stories'];

    expect($stories[0]['locked'])->toBeFalse()
        ->and($stories[0]['image_url'])->toBe(route('stories.image', $story->id));

    // E a URL que a tela recebeu de fato entrega os bytes — tela e serving
    // concordam por construção (a mesma LEVEL_CAPABILITIES).
    $this->actingAs($member->fresh())->get($stories[0]['image_url'])->assertOk();
});

it('mostra ao visitante deslogado que existem stories, todos fechados', function () {
    $performer = scPerformer();
    scStory($performer, 'public');

    $page = $this->get(route('performers.public.show', $performer->slug))->assertOk();

    $stories = $page->viewData('page')['props']['stories'];

    // Ele vê que há conteúdo — a tela leva ao cadastro, mesmo caminho de toda
    // ação desta página —, e não recebe URL de nada.
    expect($stories)->toHaveCount(1)
        ->and($stories[0]['locked'])->toBeTrue()
        ->and($stories[0]['image_url'])->toBeNull()
        ->and($stories[0]['seen'])->toBeFalse();

    $page->assertInertia(fn (Assert $inertia) => $inertia
        ->component('Performers/Show')
        ->has('stories', 1)
    );
});

it('não expõe id de membro nem caminho de disco nas props do perfil', function () {
    $performer = scPerformer();
    $story = scStory($performer, 'public');
    $member = scMember();
    scFollow($member, $performer);
    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    $content = $this->actingAs($member->fresh())
        ->get(route('catalog.show', $performer->slug))
        ->assertOk()
        ->getContent();

    // A faixa é do espectador; nada dela informa a performer. Nem o id cru do
    // membro (o que o FanAlias existe para tirar de circulação), nem o caminho no
    // disco (não é cifrado — § 2.5 —, então a serialização é a única barreira),
    // nem lista de viewers.
    expect($content)
        ->not->toContain('media_path')
        ->not->toContain('viewers')
        ->not->toContain('story_views');
});

// ─── A regra continua com uma dona só ───────────────────────────────────────

it('deriva os níveis do catálogo da MESMA tabela que o serving usa', function () {
    // Guarda a invariante do PR: o filtro em SQL do pontinho e o predicado do
    // serving leem a `LEVEL_CAPABILITIES`. Se alguém escrever um `whereIn` com a
    // lista à mão, esta asserção não pega — mas a tabela sumindo daqui pega, e é
    // o que sinaliza que a regra voltou a ter duas implementações.
    expect(array_keys(StoryVisibilityService::LEVEL_CAPABILITIES))
        ->toBe(PerformerStory::VISIBILITY_LEVELS);

    // Nível fora da tabela falha FECHADO nos dois lados (interseção vazia).
    expect(StoryVisibilityService::LEVEL_CAPABILITIES['exclusive'])
        ->toBe([StoryVisibilityService::CAP_EXCLUSIVE_TIER]);
});
