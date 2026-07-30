<?php

use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Models\StoryView;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Stories da Performer — tabelas, storage, contador e GC (Sprint 9C, PR 1).
 * Origem das regras: docs/SECURITY_ISSUES.md § 2.1 a § 2.10.
 *
 * O que estes testes protegem, em duas frases: **o story some quando o prazo
 * vence, mesmo que o job nunca rode** — exceto se houver denúncia em aberto, e aí
 * ele fica de propósito. E **nada além de uma FAIXA de membros únicos chega à
 * performer**: nunca lista de viewers, nunca o número cru, nunca nada no Nível 3.
 *
 * As fixtures de imagem são montadas byte a byte pela mesma razão dada em
 * tests/Unit/ImageProcessingServiceTest.php — um JPEG binário versionado é uma
 * asserção que ninguém consegue ler daqui a um ano. Os nomes são distintos dos de
 * lá e dos de MemberEphemeralPhotoTest de propósito: as funções de teste do Pest
 * são globais e colidiriam.
 */
beforeEach(function () {
    // Disco próprio, fora do backup e SEM cifra (§ 2.5). Fake para não sujar
    // storage/app.
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function storyRawJpeg(int $width = 60, int $height = 40): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 30, 120, 200));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/**
 * JPEG com APP1/EXIF carregando GPS. Aqui o dono das coordenadas é a PERFORMER —
 * quem tem documento e selfie na plataforma —, e é por isso que o strip é
 * obrigatório também neste lado (§ 2.5, consequência nº 2 da decisão nº 2).
 */
function storyJpegWithGps(): string
{
    $rational = fn (int $num, int $den) => pack('NN', $num, $den);

    $latData = $rational(23, 1).$rational(33, 1).$rational(0, 1);
    $lonData = $rational(46, 1).$rational(38, 1).$rational(0, 1);

    // Offsets a partir do cabeçalho TIFF: 8 IFD0, 26 GPS IFD, 80/104 os dados.
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

    return "\xFF\xD8".$app1.substr(storyRawJpeg(), 2);
}

function storyUpload(?string $bytes = null, string $name = 'story.jpg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_story_fixture_');
    file_put_contents($path, $bytes ?? storyRawJpeg());

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

function storyMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function storyService(): PerformerStoryService
{
    return app(PerformerStoryService::class);
}

function storyStore(): PerformerStoryStore
{
    return app(PerformerStoryStore::class);
}

/** Vence um story sem passar pelo relógio — o arquivo continua no disco. */
function expireStory(PerformerStory $story, string $ago = '-1 hour'): PerformerStory
{
    $story->forceFill(['expires_at' => Carbon::parse($ago)])->save();

    return $story->refresh();
}

/** Story publicado por uma performer nova, no nível pedido. */
function publishedStory(string $visibility = 'public', ?PerformerProfile $profile = null): PerformerStory
{
    return storyService()->publish($profile ?? chatPerformer(), storyUpload(), $visibility);
}

// ─── Publicação e storage (§ 2.5) ────────────────────────────────────────────

it('publica o story no disco com prazo fixo de 24h', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));

    $profile = chatPerformer();
    $story = storyService()->publish($profile, storyUpload(), 'subscribers');

    expect($story->exists)->toBeTrue()
        ->and($story->visibility_level)->toBe('subscribers')
        ->and($story->performer_profile_id)->toBe($profile->id)
        // Prazo fixo do produto, não menu: `expires_at` está fora do $fillable
        // justamente para o cliente não escolher.
        ->and($story->expires_at->timestamp)->toBe(now()->addHours(24)->timestamp)
        ->and(PerformerStory::TTL_HOURS)->toBe(24);

    expect(storyStore()->exists($story->media_path))->toBeTrue()
        ->and(Storage::disk(PerformerStoryStore::DISK)->allFiles())->toHaveCount(1);

    Carbon::setTestNow();
});

it('grava os bytes SEM cifra, ao contrário da foto efêmera do membro', function () {
    $story = publishedStory();

    $onDisk = Storage::disk(PerformerStoryStore::DISK)->get($story->media_path);

    // O contraponto exato do teste da foto do membro, onde nem o magic number
    // sobrevive: aqui o arquivo no disco É a imagem servível (§ 2.5). Cifrar
    // conteúdo 1-para-muitos custaria o arquivo inteiro na RAM por viewer
    // concorrente, sem Range nem seek — DoS auto-infligido. O que substitui a
    // cifra é authz por request sobre um disco `serve => false`.
    expect(substr($onDisk, 0, 2))->toBe("\xFF\xD8");

    $info = getimagesizefromstring(storyStore()->retrieve($story->media_path));
    expect($info[2])->toBe(IMAGETYPE_JPEG);
});

it('remove EXIF/GPS da imagem publicada pela performer', function () {
    $upload = storyUpload(storyJpegWithGps());

    // A fixture PRECISA carregar GPS, senão o teste não prova nada.
    $before = @exif_read_data($upload->getRealPath());
    expect($before)->toBeArray()->toHaveKeys(['GPSLatitude', 'GPSLongitude']);

    $story = storyService()->publish(chatPerformer(), $upload, 'public');

    // Strip OBRIGATÓRIO também deste lado: um story revelaria a localização de
    // quem tem documento e selfie na plataforma. E o arquivo servido deixa de ser
    // o arquivo enviado, o que mata polyglot no mesmo passo — aqui vale mais que
    // na foto do membro, porque estes bytes vão para MUITAS pessoas e não estão
    // atrás de cifra nenhuma.
    $tmp = tempnam(sys_get_temp_dir(), 'limen_story_check_');
    file_put_contents($tmp, storyStore()->retrieve($story->media_path));
    $after = @exif_read_data($tmp);
    @unlink($tmp);

    $gpsTags = array_filter(
        array_keys($after ?: []),
        fn (string $tag) => str_starts_with($tag, 'GPS'),
    );

    expect($gpsTags)->toBeEmpty();
});

it('nunca deriva o caminho do nome enviado pelo cliente', function () {
    $profile = chatPerformer();

    // Disciplina de path copiada verbatim do MemberPhotoStore: nome do chamador,
    // extensão do que o SERVIDOR produziu, diretório do id do perfil. O que NÃO
    // é copiado é o sufixo `.enc` — aqui o objeto no disco é imagem servível, e
    // essa diferença tem de ficar visível no nome.
    $story = storyService()->publish($profile, storyUpload(name: '../../../etc/passwd.jpg'), 'public');

    expect($story->media_path)->toMatch('#^'.$profile->id.'/[A-Za-z0-9]{40}\.jpg$#')
        ->and($story->media_path)->not->toContain('passwd')
        ->and($story->media_path)->not->toEndWith('.enc');
});

it('recusa nível de visibilidade fora dos três e não deixa bytes atrás', function () {
    $profile = chatPerformer();

    expect(fn () => storyService()->publish($profile, storyUpload(), 'everyone'))
        ->toThrow(InvalidArgumentException::class);

    // Um story sem nível é um story sem paywall. A validação no Service existe
    // para que um segundo ponto de entrada não nasça aceitando qualquer string —
    // o Form Request do PR 2 recusa antes, mas não é a última linha.
    expect(PerformerStory::count())->toBe(0)
        ->and(Storage::disk(PerformerStoryStore::DISK)->allFiles())->toBeEmpty();
});

// ─── Expiração na LEITURA, não no job (§ 2.8) ────────────────────────────────

it('marca o story como vencido 24h depois, com o arquivo ainda no disco', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));

    $story = publishedStory();

    expect($story->isExpired())->toBeFalse()
        ->and(PerformerStory::active()->count())->toBe(1);

    // Uma hora antes ainda vale; no instante exato do vencimento, já não.
    Carbon::setTestNow(Carbon::parse('2026-07-31 11:00:00'));
    expect($story->isExpired())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-31 12:00:00'));

    // O ponto do teste: o GC não rodou, os bytes continuam lá — e mesmo assim o
    // story já saiu de toda leitura. Se o corte dependesse do job, job parado
    // viraria acesso indefinido.
    expect($story->isExpired())->toBeTrue()
        ->and(PerformerStory::active()->count())->toBe(0)
        ->and(storyStore()->exists($story->media_path))->toBeTrue();

    Carbon::setTestNow();
});

// ─── Views: DISTINCT por membro e os guards do § 2.7 ─────────────────────────

it('conta cada membro uma vez, não cada abertura', function () {
    $story = publishedStory();
    $member = storyMember();

    storyService()->viewStory($story, $member);
    storyService()->viewStory($story, $member);
    storyService()->viewStory($story, $member);

    // O índice único do par é a regra "cada membro conta 1 vez" (decisão nº 1 do
    // PO). Sem ela, um membro que reabre 5 vezes empurraria sozinho a faixa de
    // `Menos de 5` para `5+` — devolvendo comportamento dele em vez de audiência.
    expect(StoryView::where('performer_story_id', $story->id)->count())->toBe(1);
});

it('não reescreve viewed_at quando o membro reabre', function () {
    $story = publishedStory();
    $member = storyMember();

    Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00'));
    storyService()->viewStory($story, $member);
    $first = StoryView::where('performer_story_id', $story->id)->sole()->viewed_at;

    Carbon::setTestNow(Carbon::parse('2026-07-30 18:00:00'));
    storyService()->viewStory($story, $member);

    // A coluna viraria um log de quando o membro volta a olhar — comportamento
    // dele, que nenhuma tela pode mostrar. Mesma decisão do `viewed_at` de
    // MemberPhotoAccess.
    expect(StoryView::where('performer_story_id', $story->id)->sole()->viewed_at->timestamp)
        ->toBe($first->timestamp);

    Carbon::setTestNow();
});

it('não grava view de membro com Ghost Mode', function () {
    $story = publishedStory();
    $member = storyMember();
    // O perk explícito (o Black nasce assim por padrão; aqui a escolha é direta
    // porque o que se testa é o guard, não a elegibilidade).
    $member->forceFill(['ghost_mode' => true])->save();

    expect(storyService()->viewStory($story, $member->refresh()))->toBeNull();

    // A AUSÊNCIA de linha é o produto (§ 2.7 e item 9 do CLAUDE.md): não existe
    // coluna `hidden` para filtrar depois. Guardar a view marcada como oculta
    // deixaria o dado a um JOIN de distância, e um bug de query viraria o
    // vazamento exato que o perk vende.
    expect(StoryView::count())->toBe(0);
});

it('não grava view de membro em Modo Discreto', function () {
    $story = publishedStory();
    $member = storyMember();
    $member->forceFill(['discrete_mode' => true])->save();

    expect(storyService()->viewStory($story, $member->refresh()))->toBeNull()
        ->and(StoryView::count())->toBe(0);

    // Fura JUSTO no caso que a regra 3 do CLAUDE.md protege: quem ativou Discreto
    // como Black e depois lapsou o tier continua discreto, mas deixa de ser
    // elegível ao Ghost Mode — sem esta checagem voltaria a ser contado por causa
    // do lapso de pagamento.
    expect($member->refresh()->hasGhostMode())->toBeFalse();
});

it('não conta a própria performer nem quem não é membro', function () {
    $profile = chatPerformer();
    $story = publishedStory('public', $profile);
    $outra = chatPerformer();

    storyService()->viewStory($story, $profile->user);
    storyService()->viewStory($story, $outra->user);
    storyService()->viewStory($story, User::factory()->create(['role' => 'admin', 'status' => 'active']));

    // Sem este corte a performer infla a própria faixa abrindo o próprio story —
    // e a faixa é a única métrica de audiência que a feature entrega.
    expect(StoryView::count())->toBe(0);
});

it('não grava view de story já vencido', function () {
    $story = expireStory(publishedStory());

    expect(storyService()->viewStory($story, storyMember()))->toBeNull()
        ->and(StoryView::count())->toBe(0);
});

it('não cria Follow ao ver o story', function () {
    $profile = chatPerformer();
    $story = publishedStory('public', $profile);
    $member = storyMember();

    storyService()->viewStory($story, $member);

    // Decisão do PO (§ 2.10): a intenção ("ver um story") ≠ a consequência
    // ("entrar numa lista que a performer vê e que me habilita a RECEBER
    // Interesse Controlado"). Seguir continua sendo clique explícito, e não há
    // `follows.source` para registrar de onde veio (§ 2.9).
    expect($profile->follows()->count())->toBe(0)
        ->and(StoryView::count())->toBe(1);
});

// ─── Contador: faixa nos níveis 1/2, null no 3 (§ 2.2, decisão nº 3) ─────────

it('devolve a audiência em faixa nos níveis público e assinantes', function (string $visibility) {
    $story = publishedStory($visibility);

    // Um membro só: a faixa mais baixa da tabela de seguidores, que é a dona
    // (decisão nº 1) — não criar uma segunda tabela de faixas.
    storyService()->viewStory($story, storyMember());
    expect(storyService()->viewCount($story))->toBe('Menos de 5');

    foreach (range(1, 4) as $i) {
        storyService()->viewStory($story, storyMember());
    }

    expect(storyService()->viewCount($story))->toBe('5+')
        // Faixa, nunca número: regra 5 do CLAUDE.md, inclusive para a própria
        // performer. Um `int` daqui obrigaria a tela a rotular, e o número exato
        // trafegaria nas props do Inertia — bastaria abrir o DevTools.
        ->and(storyService()->viewCount($story))->toBeString();
})->with(['public', 'subscribers']);

it('não devolve contador nenhum no story exclusivo — null, não zero', function () {
    $story = publishedStory('exclusive');

    // Sem viewer: `null`, e não `Menos de 5`, e não `0`.
    expect(storyService()->viewCount($story))->toBeNull();

    foreach (range(1, 5) as $i) {
        storyService()->viewStory($story, storyMember());
    }

    // Com 5 viewers continua `null`. Qualquer sinal de audiência aqui provaria
    // que aqueles viewers são Black/FC: postar um Nível 1 e um Nível 3 com
    // minutos de diferença faz a DIFERENÇA entre os conjuntos entregar o tier, e
    // o FanAlias é estável — "Fã #0042 viu meu exclusivo" carimbaria um alvo de
    // alto valor. Sem o segundo operando, a subtração não existe.
    expect(storyService()->viewCount($story))->toBeNull();

    // As views SÃO gravadas (a tabela alimenta expurgo e retenção); o que não
    // existe é a resposta. O guard vive no Service, não no controller nem no Vue.
    expect(StoryView::where('performer_story_id', $story->id)->count())->toBe(5);
});

it('aplica o DISTINCT antes da faixa, não depois', function () {
    $story = publishedStory();
    $insistente = storyMember();

    foreach (range(1, 6) as $i) {
        storyService()->viewStory($story, $insistente);
    }

    // Seis aberturas de UMA pessoa não podem virar `5+`: faixar o total de
    // aberturas devolveria quantas vezes abriram, que é comportamento do membro.
    expect(storyService()->viewCount($story))->toBe('Menos de 5');
});

// ─── Mass assignment e serialização ──────────────────────────────────────────

it('não aceita FK, caminho nem prazo do story por mass assignment', function () {
    $profile = chatPerformer();

    $story = new PerformerStory([
        'performer_profile_id' => $profile->id,
        'media_path' => 'qualquer/coisa.jpg',
        'expires_at' => now()->addYear(),
        'visibility_level' => 'public',
    ]);

    // A dona vem do request autenticado, o caminho vem do Store e o prazo é fixo
    // do produto (24h) — aceito por payload, o cliente escolheria o prazo que o
    // produto fixou. Mesma regra de `discrete_mode`, do segredo de 2FA e do
    // `user_id` de member_photos. Só o nível vem de formulário.
    expect($story->performer_profile_id)->toBeNull()
        ->and($story->media_path)->toBeNull()
        ->and($story->expires_at)->toBeNull()
        ->and($story->visibility_level)->toBe('public');
});

it('não aceita nada da view por mass assignment', function () {
    $story = publishedStory();
    $member = storyMember();

    // `$fillable` vazio com o `$guarded = ['*']` do padrão é a forma mais forte
    // da regra: o model está TOTALMENTE guardado, então a tentativa não é
    // ignorada em silêncio — ela estoura. O mais perigoso é o `user_id`: aceito
    // por payload, gravaria view no nome de outro membro, adulterando o DISTINCT
    // que sustenta a faixa e plantando justamente a linha que o Ghost Mode existe
    // para não ter.
    expect(fn () => new StoryView([
        'performer_story_id' => $story->id,
        'user_id' => $member->id,
        'viewed_at' => now(),
    ]))->toThrow(MassAssignmentException::class);

    // E o caminho legítimo (escrita explícita no Service) segue funcionando.
    storyService()->viewStory($story, $member);

    expect(StoryView::sole()->user_id)->toBe($member->id);
});

it('não expõe o caminho no disco nem o id do membro em serialização', function () {
    $story = publishedStory();
    $member = storyMember();
    storyService()->viewStory($story, $member);

    // O caminho não é cifrado no banco (§ 2.5), então esta linha é a única
    // barreira entre ele e as props do Inertia: vazá-lo entregaria o layout do
    // disco e o alvo de qualquer traversal futuro na camada de serving.
    expect($story->toArray())->not->toHaveKey('media_path');

    // `user_id` é o `member_id` cru — o id que o FanAlias existe para tirar de
    // circulação. A performer nunca recebe linha desta tabela (§ 2.1), e o
    // $hidden é a rede para o dia em que alguém serializar o model num payload
    // de admin ou de métricas.
    expect(StoryView::sole()->toArray())->not->toHaveKey('user_id');

    // E esconder da serialização não pode ter quebrado o código que decide:
    expect($story->isExpired())->toBeFalse()
        ->and(storyStore()->exists($story->media_path))->toBeTrue();
});

// ─── Garbage collection (§ 2.4, § 2.6, § 2.8) ────────────────────────────────

it('apaga do disco os stories vencidos e as views deles', function () {
    $profile = chatPerformer();

    $expired = publishedStory('public', $profile);
    storyService()->viewStory($expired, storyMember());
    expireStory($expired);

    $alive = publishedStory('public', $profile);
    storyService()->viewStory($alive, storyMember());

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0 stale=0 failed=0')
        ->assertSuccessful();

    // Bytes fora do disco; a linha fica soft-deletada, e essa é a diferença
    // deliberada com member_photos: lá a linha morta guardaria "o membro X mandou
    // N fotos, nestes horários"; aqui ela é da própria performer sobre o próprio
    // conteúdo, e é o que dá lastro ao § 2.4 (o hash entra no PR 5).
    expect(storyStore()->exists($expired->media_path))->toBeFalse()
        ->and(PerformerStory::find($expired->id))->toBeNull()
        ->and(PerformerStory::withTrashed()->find($expired->id))->not->toBeNull();

    // As views morrem JUNTO (§ 2.6): são o mapa membro→performer, estruturalmente
    // idêntico a profile_visits. O `cascadeOnDelete` da FK não cobre — a linha do
    // story sai por soft delete (item 11 do CLAUDE.md), então quem apaga é código.
    expect(StoryView::where('performer_story_id', $expired->id)->exists())->toBeFalse();

    // E o story vivo não é tocado.
    expect(storyStore()->exists($alive->media_path))->toBeTrue()
        ->and(PerformerStory::find($alive->id))->not->toBeNull()
        ->and(StoryView::where('performer_story_id', $alive->id)->exists())->toBeTrue();
});

it('congela o story denunciado em vez de apagar a prova', function () {
    $story = publishedStory();
    $member = storyMember();
    storyService()->viewStory($story, $member);
    expireStory($story, '-5 hours');

    Report::open($member, $story, 'underage_content');

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=0 deleted=0 quarantined=1 stale=0 failed=0')
        ->assertSuccessful();

    // O achado mais sério da feature (§ 2.4): o auto-delete de 24h é destruição
    // de prova embutida no produto. O DeletionService preserva `reports` intactos
    // porque "apagá-la daria ao infrator um botão para destruir a prova contra
    // si" — e Stories daria esse botão a toda performer, automático, a cada 24h.
    expect(storyStore()->exists($story->media_path))->toBeTrue()
        ->and(PerformerStory::find($story->id))->not->toBeNull()
        ->and(StoryView::where('performer_story_id', $story->id)->exists())->toBeTrue();

    // E o congelado NÃO entra no `stale` (a saída acima prova: vencido há 5h,
    // ainda no disco, e `stale=0`): ele é vencido-e-presente por design, e um
    // alarme que sempre apita é um alarme desligado.
});

it('volta a recolher o story depois que a denúncia é concluída', function () {
    $story = publishedStory();
    $member = storyMember();
    expireStory($story);

    $report = Report::open($member, $story, 'spam');

    $this->artisan('stories:purge')->expectsOutputToContain('quarantined=1')->assertSuccessful();
    expect(storyStore()->exists($story->media_path))->toBeTrue();

    // `resolved`/`dismissed` são conclusão: o humano olhou. `pending`/`reviewed`
    // são os estados em aberto que congelam.
    $report->forceFill(['status' => 'dismissed'])->save();

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0')
        ->assertSuccessful();

    expect(storyStore()->exists($story->media_path))->toBeFalse();
});

it('reporta como alarme os stories vencidos há mais de um ciclo e ainda no disco', function () {
    // Vencido dentro do ciclo: é o trabalho normal do GC, não é alarme.
    expireStory(publishedStory(), '-30 minutes');

    $this->artisan('stories:purge')
        ->expectsOutputToContain('stale=0')
        ->assertSuccessful();

    // Vencido há mais de um ciclo com o arquivo ainda lá: a rodada anterior não o
    // levou. É o único sinal que hoje existe de que o GC parou — persistente e
    // diferente de zero é o alarme.
    expireStory(publishedStory(), '-3 hours');

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0 stale=1 failed=0')
        ->assertSuccessful();
});

it('recolhe os bytes órfãos de um story cuja linha morreu antes do arquivo', function () {
    $story = expireStory(publishedStory());

    // O estado intermediário: linha soft-deletada, bytes de pé. `destroy()` não o
    // produz (apaga o arquivo, confirma e só então solta a linha), mas ele é
    // alcançável por outro caminho — um `$story->delete()` cru num PR futuro — e
    // sem rede aqueles bytes ficariam no volume invisíveis a qualquer rodada,
    // porque a linha saiu do escopo padrão.
    $story->delete();

    expect(PerformerStory::find($story->id))->toBeNull()
        ->and(storyStore()->exists($story->media_path))->toBeTrue();

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0 stale=1 failed=0')
        ->assertSuccessful();

    expect(storyStore()->exists($story->media_path))->toBeFalse();
});

it('não reprocessa para sempre o story já recolhido', function () {
    expireStory(publishedStory());

    $this->artisan('stories:purge')->assertSuccessful();

    // A rodada seguinte não olha de novo o disco de tudo que já venceu: a linha
    // soft-deletada FICA (ao contrário de member_photos, que termina em hard
    // delete), e uma varredura ampla faria o custo por rodada crescer com o
    // histórico do produto para sempre. A rede de bytes órfãos é limitada a
    // RESCUE_WINDOW_HOURS por isso.
    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=0 deleted=0 quarantined=0 stale=0 failed=0')
        ->assertSuccessful();
});

it('destrói bytes, views e linha num passo só', function () {
    $story = publishedStory();
    storyService()->viewStory($story, storyMember());

    storyService()->destroy($story);

    expect(storyStore()->exists($story->media_path))->toBeFalse()
        ->and(StoryView::where('performer_story_id', $story->id)->exists())->toBeFalse()
        ->and(PerformerStory::find($story->id))->toBeNull();
});

it('mantém a linha de pé quando o disco recusa apagar os bytes', function () {
    $story = expireStory(publishedStory());

    // Reproduz a falha real: permissão errada no diretório depois de um deploy. O
    // disco roda com `throw => false`, então sem a checagem de retorno no Store o
    // `delete()` devolveria `false` em silêncio, `destroy()` seguiria em frente e
    // a linha soft-deletada esconderia do GC um arquivo que continua no disco —
    // com o alarme `stale` marcando zero, que é o pior dos dois mundos.
    $dir = dirname(Storage::disk(PerformerStoryStore::DISK)->path($story->media_path));
    chmod($dir, 0o500);

    try {
        expect(fn () => storyService()->destroy($story))->toThrow(RuntimeException::class);

        // A linha NÃO foi soft-deletada: é o que deixa a rodada seguinte tentar de
        // novo, e é o que faz o alarme continuar enxergando o story.
        expect(PerformerStory::find($story->id))->not->toBeNull()
            ->and(storyStore()->exists($story->media_path))->toBeTrue();
    } finally {
        chmod($dir, 0o700);
    }
});
