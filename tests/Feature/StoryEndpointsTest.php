<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryView;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Endpoints dos Stories — publicar, apagar, servir e o feed do membro.
 * Ver docs/SECURITY_ISSUES.md § 2.1 a § 2.10, em especial § 2.3 e § 2.8.
 *
 * O eixo destes testes não é "a rota responde 200". São três coisas:
 *
 *  1. **O paywall é reavaliado a cada request** (§ 2.3). Nada de URL assinada:
 *     follow e tier são resolvidos na hora, e a resposta muda no instante em que
 *     o tier muda — não quando uma assinatura expira.
 *  2. **O prazo corta na LEITURA** (§ 2.8): story vencido com os bytes ainda no
 *     disco não é servido, então job parado não vira acesso indefinido.
 *  3. **Nada de audiência atravessa a resposta** além da faixa: nem lista de
 *     viewers, nem `user_id`, nem contador no nível exclusivo.
 *
 * Helpers com prefixo `st` (story) para o arquivo rodar isolado ou na suíte: as
 * funções do Pest são globais e colidiriam com as de PerformerStoriesTest.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function stJpeg(int $width = 60, int $height = 40): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 40, 90, 180));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** JPEG com APP1/EXIF carregando GPS — aqui as coordenadas são da PERFORMER. */
function stJpegWithGps(): string
{
    $rational = fn (int $num, int $den) => pack('NN', $num, $den);

    $latData = $rational(23, 1).$rational(33, 1).$rational(0, 1);
    $lonData = $rational(46, 1).$rational(38, 1).$rational(0, 1);

    $tiff = "MM\x00\x2A".pack('N', 8);
    $tiff .= pack('n', 1);
    $tiff .= pack('nnN', 0x8825, 4, 1).pack('N', 26);
    $tiff .= pack('N', 0);
    $tiff .= pack('n', 4);
    $tiff .= pack('nnN', 0x0001, 2, 2)."S\x00\x00\x00";
    $tiff .= pack('nnN', 0x0002, 5, 3).pack('N', 80);
    $tiff .= pack('nnN', 0x0003, 2, 2)."W\x00\x00\x00";
    $tiff .= pack('nnN', 0x0004, 5, 3).pack('N', 104);
    $tiff .= pack('N', 0);
    $tiff .= $latData.$lonData;

    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return "\xFF\xD8".$app1.substr(stJpeg(), 2);
}

function stUpload(?string $bytes = null, string $name = 'story.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_st_');
    file_put_contents($path, $bytes ?? stJpeg());

    return new UploadedFile($path, $name, $mime, null, true);
}

/** Membro ativo, com conta madura; opcionalmente com Círculo vivo. */
function stMember(?string $circleSlug = null): User
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

/** Publica pelo SERVICE — atalho de setup para os testes que não testam o POST. */
function stStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, stUpload(), $visibility);
}

function stFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

/** Vence um story sem passar pelo relógio — o arquivo continua no disco. */
function stExpire(PerformerStory $story): PerformerStory
{
    $story->forceFill(['expires_at' => now()->subHour()])->save();

    return $story->refresh();
}

// ─── Performer: publicação ───────────────────────────────────────────────────

it('publica o story pelo endpoint com prazo de 24h', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));

    $performer = chatPerformer();

    $response = $this->actingAs($performer->user)
        ->post(route('performer.stories.store'), [
            'imagem' => stUpload(),
            'visibility_level' => 'subscribers',
        ])
        ->assertCreated();

    $story = PerformerStory::sole();

    // A resposta é o StoryPresenter, e o que ela NÃO tem é a parte que importa:
    // nem `media_path`, nem qualquer coisa vinda de `story_views` além da faixa.
    expect(array_keys($response->json('story')))
        ->toBe(['id', 'visibility_level', 'view_count', 'expires_in_hours', 'image_url', 'is_invite'])
        ->and($response->json('story.visibility_level'))->toBe('subscribers')
        ->and($response->json('story.view_count'))->toBe('Menos de 5')
        ->and($response->json('story.expires_in_hours'))->toBe(24)
        // Sem a caixinha marcada, é Story normal — o default.
        ->and($response->json('story.is_invite'))->toBeFalse();

    expect($story->expires_at->timestamp)->toBe(now()->addHours(24)->timestamp)
        ->and($story->performer_profile_id)->toBe($performer->id)
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeTrue();

    Carbon::setTestNow();
});

it('remove EXIF/GPS da imagem publicada pelo endpoint', function () {
    $performer = chatPerformer();
    $upload = stUpload(stJpegWithGps());

    // A fixture PRECISA carregar GPS, senão o teste não prova nada.
    expect(@exif_read_data($upload->getRealPath()))->toBeArray()->toHaveKeys(['GPSLatitude', 'GPSLongitude']);

    $this->actingAs($performer->user)
        ->post(route('performer.stories.store'), ['imagem' => $upload, 'visibility_level' => 'public'])
        ->assertCreated();

    $story = PerformerStory::sole();

    // Lê pelo próprio endpoint de serving — é o caminho que o membro usa.
    $bytes = $this->actingAs($performer->user)
        ->get(route('performer.stories.image', $story->id))
        ->assertOk()
        ->getContent();

    $tmp = tempnam(sys_get_temp_dir(), 'limen_st_check_');
    file_put_contents($tmp, $bytes);
    $after = @exif_read_data($tmp);
    @unlink($tmp);

    // Um story revelaria a localização de quem tem documento e selfie na
    // plataforma (§ 2.5, consequência da decisão nº 2). Strip é obrigatório.
    expect(array_filter(array_keys($after ?: []), fn (string $t) => str_starts_with($t, 'GPS')))->toBeEmpty();
});

it('recusa o que não é JPEG nem PNG — inclusive vídeo', function () {
    $performer = chatPerformer();

    // v1 é só imagem (decisão nº 2 do PO), e vídeo não é "somar um mime": depende
    // da estratégia de serving sem cifra em memória do § 2.5.
    $this->actingAs($performer->user)
        ->postJson(route('performer.stories.store'), [
            'imagem' => stUpload('nao-sou-imagem', 'clipe.mp4', 'video/mp4'),
            'visibility_level' => 'public',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.imagem.0', 'O story precisa ser JPEG ou PNG. Vídeo ainda não é suportado.');

    expect(PerformerStory::count())->toBe(0)
        ->and(Storage::disk(PerformerStoryStore::DISK)->allFiles())->toBeEmpty();
});

it('recusa nível de visibilidade fora dos três', function () {
    $performer = chatPerformer();

    // 422 em JSON numa rota WEB só porque o Form Request usa o
    // FailsValidationAsJson: sem o trait o `fetch` do painel receberia um 302
    // seguido de HTML (convenção das duas portas de auth, CLAUDE.md).
    $this->actingAs($performer->user)
        ->postJson(route('performer.stories.store'), [
            'imagem' => stUpload(),
            'visibility_level' => 'todo-mundo',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['visibility_level']]);

    expect(PerformerStory::count())->toBe(0);
});

// ─── Performer: lista, thumbnail e delete ────────────────────────────────────

it('lista os stories dela com a faixa, e sem contador no exclusivo', function () {
    $performer = chatPerformer();
    $publico = stStory($performer, 'public');
    $exclusivo = stStory($performer, 'exclusive');

    // Cinco membros sem tier veem o público: a faixa sobe.
    foreach (range(1, 5) as $i) {
        app(PerformerStoryService::class)->viewStory($publico, stMember());
    }

    // E cinco Black veem o exclusivo. O contador dele não existe de duas formas
    // independentes, e é bom que sejam duas: o nível não responde (§ 2.2, decisão
    // nº 3) E o Black nasce com Ghost Mode ligado, então nem linha de view existe.
    foreach (range(1, 5) as $i) {
        app(PerformerStoryService::class)->viewStory($exclusivo, stMember('black'));
    }

    expect(StoryView::where('performer_story_id', $exclusivo->id)->count())->toBe(0);

    $response = $this->actingAs($performer->user)
        ->getJson(route('performer.stories.index'))
        ->assertOk();

    $byId = collect($response->json('stories'))->keyBy('id');

    expect($byId[$publico->id]['view_count'])->toBe('5+')
        // `null`, e não `0` nem `'Menos de 5'`: zero é um valor no mesmo domínio
        // da faixa e afirmaria algo falso sobre a audiência.
        ->and($byId[$exclusivo->id]['view_count'])->toBeNull();

    // E o payload não carrega NADA de quem viu — não existe lista de viewers em
    // endpoint nenhum (§ 2.1).
    expect($response->getContent())
        ->not->toContain('user_id')
        ->not->toContain('viewers')
        ->not->toContain('media_path');
});

it('leva os stories para o painel dela, com a faixa e sem contador no exclusivo', function () {
    $performer = chatPerformer();
    stStory($performer, 'exclusive');

    // O painel e o `GET /performer/stories` montam o card pelo MESMO
    // StoryPresenter: duas montagens divergiriam, e a que divergisse seria a que
    // devolve número em vez de faixa.
    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Dashboard')
            ->has('stories', 1)
            ->where('stories.0.view_count', null)
            ->where('canPublishStories', true)
        );
});

it('não oferece publicação à performer que ainda está em KYC', function () {
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $user->performerProfile()->create([
        'stage_name' => 'Pendente '.Str::random(8),
        'slug' => 'pend-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    // Ela alcança o painel de propósito (Sprint 7), mas as rotas de story exigem
    // `can('performer-active')`. A tela não é o guard — só não oferece um botão
    // que responderia 403.
    $this->actingAs($user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canPublishStories', false));

    $this->actingAs($user)
        ->postJson(route('performer.stories.store'), [
            'imagem' => stUpload(),
            'visibility_level' => 'public',
        ])
        ->assertForbidden();
});

it('serve o thumbnail para a dona e recusa para outra performer', function () {
    $performer = chatPerformer();
    $outra = chatPerformer();
    $story = stStory($performer);

    $response = $this->actingAs($performer->user)
        ->get(route('performer.stories.image', $story->id))
        ->assertOk();

    // Content-Type de re-sniff no servidor, `inline`, `nosniff` e `no-store` —
    // cache é retenção, e um story de 24h num proxy sobreviveria ao TTL.
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="story.jpg"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr($response->getContent(), 0, 2))->toBe("\xFF\xD8");

    // Id EXISTENTE: com um inexistente o 404 do binding chegaria antes do guard.
    $this->actingAs($outra->user)
        ->get(route('performer.stories.image', $story->id))
        ->assertForbidden();
});

it('não deixa uma performer apagar o story de outra', function () {
    $performer = chatPerformer();
    $outra = chatPerformer();
    $story = stStory($performer);
    app(PerformerStoryService::class)->viewStory($story, stMember());

    $this->actingAs($outra->user)
        ->deleteJson(route('performer.stories.destroy', $story->id))
        ->assertForbidden();

    expect(PerformerStory::find($story->id))->not->toBeNull()
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeTrue();

    // A dona apaga: bytes, views e linha (soft delete) num passo só.
    $this->actingAs($performer->user)
        ->deleteJson(route('performer.stories.destroy', $story->id))
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    expect(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeFalse()
        ->and(PerformerStory::find($story->id))->toBeNull()
        ->and(StoryView::where('performer_story_id', $story->id)->exists())->toBeFalse();
});

it('não serve o thumbnail do story vencido', function () {
    $performer = chatPerformer();
    $story = stExpire(stStory($performer));

    // Os bytes continuam no disco — o GC não rodou. Quem nega é a leitura (§ 2.8).
    expect(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeTrue();

    $this->actingAs($performer->user)
        ->get(route('performer.stories.image', $story->id))
        ->assertNotFound();
});

// ─── Membro: autorização por nível, resolvida no request (§ 2.3) ─────────────

it('exige sessão nas rotas de story do membro', function () {
    // Redirect para o login, não 401: é o comportamento da porta WEB. A porta API
    // (Sanctum) é a que responde 401 — ver a convenção no CLAUDE.md.
    $this->get(route('stories.feed'))->assertRedirect(route('login'));
    $this->get(route('stories.image', 1))->assertRedirect(route('login'));
});

it('serve o story público a quem segue e recusa a quem não segue', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();

    // Sem follow: 403 — e o 403 é o upsell do Modelo C, não um vazamento (§ 2.3).
    $this->actingAs($member)
        ->get(route('stories.image', $story->id))
        ->assertForbidden();

    expect(StoryView::count())->toBe(0);

    stFollow($member, $performer);

    // O MESMO membro, o MESMO story, sem nada além do follow: a autorização é
    // reavaliada no request. Com URL assinada, o primeiro 403 e o segundo 200
    // teriam vindo do token, não do estado.
    $response = $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and(StoryView::where('performer_story_id', $story->id)->where('user_id', $member->id)->count())->toBe(1);
});

it('serve o story de assinantes a quem tem Círculo ativo, de qualquer tier', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'subscribers');

    // Nível 2 não exige follow — quem assina alcança o conteúdo de assinante.
    $this->actingAs(stMember())
        ->get(route('stories.image', $story->id))
        ->assertForbidden();

    $this->actingAs(stMember('explorador'))
        ->get(route('stories.image', $story->id))
        ->assertOk();
});

it('serve o story exclusivo só a Black e Founders Circle', function (string $tier, int $status) {
    $performer = chatPerformer();
    $story = stStory($performer, 'exclusive');
    $member = stMember($tier === 'nenhum' ? null : $tier);

    // Follow não compra o Nível 3, e tier abaixo de Black também não.
    stFollow($member, $performer);

    $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertStatus($status);
})->with([
    ['nenhum', 403],
    ['explorador', 403],
    ['prestige', 403],
    ['black', 200],
    ['founders_circle', 200],
]);

it('deixa Black ver story público de performer que ele não segue', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember('black');

    // A exceção que o PO pediu: é o que faz o Nível 1 funcionar como vitrine
    // para quem já paga.
    $this->actingAs($member)
        ->get(route('stories.image', $story->id))
        ->assertOk();

    // E ver NÃO cria Follow (§ 2.10): a intenção ("ver um story") não é a
    // consequência ("entrar numa lista que a performer vê e que me habilita a
    // receber Interesse Controlado").
    expect(Follow::count())->toBe(0);

    // A view NÃO é gravada, e isto não é falha do serving: o Black nasce com
    // Ghost Mode LIGADO por padrão (`PrivacyPerkService::PRIVATE_DEFAULT` — é o
    // que o salto de preço compra), e o guard do § 2.7 vale para story_views como
    // vale para profile_visits. Consequência de produto que vale registrar: quem
    // paga o tier mais alto não aparece em contador nenhum, nem agregado — o que
    // é exatamente o rationale da decisão nº 3, aqui alcançando também o Nível 1.
    expect(StoryView::count())->toBe(0);
});

it('não serve o story vencido, com os bytes ainda no disco', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);

    stExpire($story);

    expect(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeTrue();

    // 404 e não 403: vencido é indistinguível de inexistente, senão o par de
    // respostas enumeraria o que a performer publicou e quando.
    $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertNotFound();

    expect(StoryView::count())->toBe(0);
});

it('para de servir o story quando a conta da performer sai do ar', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);

    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    // Suspensão por moderação: as 24h de TTL são exatamente a janela em que o
    // conteúdo precisa parar. 404, para não devolver ao membro o estado da conta
    // dela.
    $performer->user->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertNotFound();
});

it('conta o membro uma vez por story, mesmo reabrindo', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);

    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();
    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();
    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    // Faixar aberturas devolveria comportamento do membro em vez de audiência
    // (decisão nº 1: DISTINCT antes da faixa).
    expect(StoryView::where('performer_story_id', $story->id)->count())->toBe(1)
        ->and(app(PerformerStoryService::class)->viewCount($story->fresh()))->toBe('Menos de 5');
});

it('serve normalmente sem registrar view de quem tem Ghost Mode ou Modo Discreto', function (string $flag) {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);
    $member->forceFill([$flag => true])->save();

    // A resposta é IDÊNTICA à de quem é contado: se diferisse, o perk seria
    // detectável de fora (mesmo cuidado do ProfileVisitService::record()).
    $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertOk();

    // E a AUSÊNCIA de linha é o produto (§ 2.7): não existe view marcada como
    // oculta para filtrar depois.
    expect(StoryView::count())->toBe(0)
        ->and(app(PerformerStoryService::class)->viewCount($story->fresh()))->toBe('Menos de 5');
})->with(['ghost_mode', 'discrete_mode']);

// ─── Membro: feed ────────────────────────────────────────────────────────────

it('monta o feed só com o que o membro pode ver, agrupado por performer', function () {
    $seguida = chatPerformer();
    $naoSeguida = chatPerformer();

    $publico = stStory($seguida, 'public');
    stStory($seguida, 'subscribers');
    stStory($seguida, 'exclusive');
    stStory($naoSeguida, 'public');

    $member = stMember();
    stFollow($member, $seguida);

    $response = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk();

    $groups = $response->json('performers');

    // Uma performer (a seguida) e um story (o público): sem Círculo ele não
    // alcança o Nível 2 nem o 3, e sem tier não vê o público de quem não segue.
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['performer']['stage_name'])->toBe($seguida->stage_name)
        ->and($groups[0]['stories'])->toHaveCount(1)
        ->and($groups[0]['stories'][0]['id'])->toBe($publico->id)
        ->and($groups[0]['stories'][0]['seen'])->toBeFalse()
        ->and($groups[0]['has_unseen'])->toBeTrue();

    // O feed e o serving concordam por construção — os dois consultam o mesmo
    // canView(). Se discordassem, o par (aparece no feed / 403 na imagem) viraria
    // oráculo, e o contrário seria furo de paywall.
    $this->actingAs($member->fresh())->get(route('stories.image', $publico->id))->assertOk();
});

it('marca como visto o story que o membro já abriu', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);

    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    $groups = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    // Dado do PRÓPRIO membro: sai de story_views filtrada pelo id dele, então não
    // expõe ninguém.
    expect($groups[0]['stories'][0]['seen'])->toBeTrue()
        ->and($groups[0]['has_unseen'])->toBeFalse();
});

it('mostra ao Black os stories públicos de quem ele não segue', function () {
    $naoSeguida = chatPerformer();
    stStory($naoSeguida, 'public');
    stStory($naoSeguida, 'exclusive');

    $groups = $this->actingAs(stMember('black'))
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    // Sem follow, o Nível 3 dela NÃO entra: a exceção do PO é sobre o público.
    // O exclusivo de performer não seguida não é candidato do feed.
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['stories'])->toHaveCount(1)
        ->and($groups[0]['stories'][0]['visibility_level'])->toBe('public');
});

it('não lista no feed story vencido', function () {
    $performer = chatPerformer();
    $vivo = stStory($performer, 'public');
    stExpire(stStory($performer, 'public'));

    $member = stMember();
    stFollow($member, $performer);

    $groups = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect($groups[0]['stories'])->toHaveCount(1)
        ->and($groups[0]['stories'][0]['id'])->toBe($vivo->id);
});

it('nunca devolve id de membro nem caminho de disco no feed', function () {
    $performer = chatPerformer();
    $story = stStory($performer, 'public');
    $member = stMember();
    stFollow($member, $performer);
    $this->actingAs($member->fresh())->get(route('stories.image', $story->id))->assertOk();

    $content = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->getContent();

    // Nem o id cru do membro (é o que o FanAlias existe para tirar de
    // circulação), nem o caminho no disco (não é cifrado — § 2.5 —, então a
    // serialização é a única barreira), nem lista de viewers.
    expect($content)
        ->not->toContain('user_id')
        ->not->toContain('media_path')
        ->not->toContain('viewers');
});

// ─── Gates: as duas portas, e o § 2.3 ───────────────────────────────────────

it('barra o membro nas rotas da performer e a performer nas do membro', function () {
    $performer = chatPerformer();
    $story = stStory($performer);
    $member = stMember();

    // Ids EXISTENTES onde há binding: o `SubstituteBindings` do grupo `web` roda
    // ANTES do middleware de rota, então um id inexistente daria 404 e o teste
    // passaria sem exercitar o `role`.
    $this->actingAs($member)->postJson(route('performer.stories.store'), [])->assertForbidden();
    $this->actingAs($member)->get(route('performer.stories.image', $story->id))->assertForbidden();
    $this->actingAs($performer->user)->get(route('stories.image', $story->id))->assertForbidden();
    $this->actingAs($performer->user)->getJson(route('stories.feed'))->assertForbidden();
});

it('manda a performer sem aceite de documentos para a tela de aceite', function () {
    $performer = chatPerformer();

    // A UserFactory aceita os documentos por padrão, então o teste tem de
    // DESFAZER o aceite. Foi a lição do `documents.accepted`: gate que fecha uma
    // porta só não é gate — e publicar conteúdo é exatamente o que a Política de
    // Conteúdo Proibido governa.
    $performer->user->documentAcceptances()->delete();

    $this->actingAs($performer->user->fresh())
        ->get(route('performer.stories.index'))
        ->assertRedirect(route('performer.documents'));
});

it('manda ao desafio de 2FA a performer que ainda não provou o fator', function () {
    $performer = chatPerformer();

    $performer->user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Rota autenticada nova entra no gate — CLAUDE.md, e vale nas duas portas.
    $this->actingAs($performer->user->fresh())
        ->get(route('performer.stories.index'))
        ->assertRedirect(route('performer.2fa.challenge'));
});

it('exige a verificação do membro nas rotas de story', function () {
    // `member.verified` cobre o grupo INTEIRO da área do membro. Rota SEM
    // parâmetro de propósito: com binding, o 404 chegaria antes do middleware.
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'pending_kyc']);

    $this->actingAs($member)
        ->get(route('stories.feed'))
        ->assertRedirect(route('consumer.kyc.index'));
});

it('não serve story por URL assinada — a autorização é de sessão', function () {
    $story = stStory(chatPerformer());

    // § 2.3, o achado que a feature herdou do `performer.media`: assinatura não
    // amarra viewer nenhum, então a URL do membro Black seria um bearer token
    // colável no WhatsApp, e qualquer pessoa deslogada baixaria o arquivo até
    // expirar. Este teste é o que impede o conserto errado.
    $middleware = Route::getRoutes()->getByName('stories.image')->gatherMiddleware();

    expect($middleware)->toContain('auth')
        ->and($middleware)->not->toContain('signed')
        ->and(route('stories.image', $story->id))->not->toContain('signature');

    // E o `signed` também não pode aparecer na porta da performer.
    expect(Route::getRoutes()->getByName('performer.stories.image')->gatherMiddleware())
        ->not->toContain('signed');
});
