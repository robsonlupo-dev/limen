<?php

use App\Exceptions\MemberPhotoException;
use App\Models\MemberPhoto;
use App\Models\MemberPhotoAccess;
use App\Models\User;
use App\Services\MemberPhotoService;
use App\Services\MemberPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Foto efêmera do membro — armazenamento, cap e expiração.
 * Origem das regras: docs/SECURITY_ISSUES.md § 1.1 a § 1.11.
 *
 * O que estes testes protegem, em uma frase: **a foto some quando o prazo
 * vence, mesmo que o job nunca rode**, e nada além da faixa de tempo chega à
 * performer.
 *
 * As fixtures de imagem são montadas byte a byte pela mesma razão dada em
 * tests/Unit/ImageProcessingServiceTest.php — um JPEG binário versionado é uma
 * asserção que ninguém consegue ler daqui a um ano. Os nomes são distintos dos
 * de lá de propósito: as funções de teste do Pest são globais e colidiriam.
 */
beforeEach(function () {
    // Disco próprio e fora do backup (§ 1.7). Fake para não sujar storage/app.
    Storage::fake(MemberPhotoStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function ephemeralRawJpeg(int $width = 60, int $height = 40): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 200, 40, 90));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/**
 * JPEG com APP1/EXIF carregando GPS — a foto de celular do § 1.4 reduzida ao
 * essencial: as coordenadas de casa de quem enviou.
 */
function ephemeralJpegWithGps(): string
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

    return "\xFF\xD8".$app1.substr(ephemeralRawJpeg(), 2);
}

function ephemeralUpload(?string $bytes = null, string $name = 'foto.jpg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_photo_fixture_');
    file_put_contents($path, $bytes ?? ephemeralRawJpeg());

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

function ephemeralMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function photoService(): MemberPhotoService
{
    return app(MemberPhotoService::class);
}

function photoStore(): MemberPhotoStore
{
    return app(MemberPhotoStore::class);
}

/** Vence uma foto sem passar pelo relógio — o arquivo continua no disco. */
function expirePhoto(MemberPhoto $photo, string $ago = '-1 hour'): MemberPhoto
{
    $photo->forceFill(['expires_at' => Carbon::parse($ago)])->save();

    return $photo->refresh();
}

// ─── Store: cifrado em repouso ───────────────────────────────────────────────

it('grava a foto cifrada no disco e a devolve decifrada', function () {
    $member = ephemeralMember();

    ['path' => $path] = photoStore()->store(ephemeralUpload(), $member->id);

    $onDisk = Storage::disk(MemberPhotoStore::DISK)->get($path);

    // O que está no disco NÃO é a imagem: nem o magic number do JPEG sobrevive.
    expect(substr($onDisk, 0, 2))->not->toBe("\xFF\xD8")
        ->and($onDisk)->not->toContain("\xFF\xD8\xFF");

    // E o que volta É: o retrieve fecha o ciclo.
    $bytes = photoStore()->retrieve($path);

    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");

    $info = getimagesizefromstring($bytes);
    expect($info[2])->toBe(IMAGETYPE_JPEG);
});

it('remove EXIF/GPS antes de cifrar', function () {
    $member = ephemeralMember();
    $upload = ephemeralUpload(ephemeralJpegWithGps());

    // A fixture PRECISA carregar GPS, senão o teste não prova nada.
    $before = @exif_read_data($upload->getRealPath());
    expect($before)->toBeArray()->toHaveKeys(['GPSLatitude', 'GPSLongitude']);

    $bytes = photoStore()->retrieve(photoStore()->store($upload, $member->id)['path']);

    // Higienizar ANTES de cifrar: cifrar primeiro só embrulharia as coordenadas.
    $tmp = tempnam(sys_get_temp_dir(), 'limen_photo_check_');
    file_put_contents($tmp, $bytes);
    $after = @exif_read_data($tmp);
    @unlink($tmp);

    $gpsTags = array_filter(
        array_keys($after ?: []),
        fn (string $tag) => str_starts_with($tag, 'GPS'),
    );

    expect($gpsTags)->toBeEmpty();
});

it('nunca deriva o caminho do nome enviado pelo cliente', function () {
    $member = ephemeralMember();

    // Disciplina copiada do KycDocumentStore (§ 1.10): nome do chamador,
    // extensão do que o SERVIDOR produziu, sufixo .enc.
    ['path' => $path] = photoStore()->store(ephemeralUpload(name: '../../../etc/passwd.jpg'), $member->id);

    expect($path)->toMatch('#^'.$member->id.'/[A-Za-z0-9]{40}\.jpg\.enc$#')
        ->and($path)->not->toContain('passwd');
});

// ─── Cap de 5 ativas (§ 1.6) ─────────────────────────────────────────────────

it('recusa a sexta foto ativa', function () {
    $member = ephemeralMember();

    foreach (range(1, MemberPhoto::ACTIVE_LIMIT) as $i) {
        photoService()->create($member, ephemeralUpload(), 24);
    }

    expect(MemberPhoto::activeForUser($member->id)->count())->toBe(MemberPhoto::ACTIVE_LIMIT);

    try {
        photoService()->create($member, ephemeralUpload(), 24);
        $this->fail('A sexta foto deveria ter sido recusada.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::ACTIVE_LIMIT_REACHED);
    }

    expect(MemberPhoto::activeForUser($member->id)->count())->toBe(MemberPhoto::ACTIVE_LIMIT);

    // Compensação: os bytes da tentativa recusada são gravados antes da
    // transação (para não segurar o lock durante o re-encode) e têm de sair
    // junto com ela. Sem isto, um laço de upload encheria o disco batendo no cap.
    expect(Storage::disk(MemberPhotoStore::DISK)->allFiles())
        ->toHaveCount(MemberPhoto::ACTIVE_LIMIT);
});

it('não conta fotos vencidas no cap', function () {
    $member = ephemeralMember();

    foreach (range(1, MemberPhoto::ACTIVE_LIMIT) as $i) {
        expirePhoto(photoService()->create($member, ephemeralUpload(), 24));
    }

    // "Ativas" = não vencidas e não removidas, o MESMO critério do serving. Se o
    // cap contasse linhas ainda no disco mas já mortas, o membro ficaria travado
    // em 5 fotos vencidas esperando o GC — cap viraria bug de produto.
    $photo = photoService()->create($member, ephemeralUpload(), 24);

    expect($photo->exists)->toBeTrue()
        ->and(MemberPhoto::activeForUser($member->id)->count())->toBe(1);
});

it('conta as ativas sob lock de linha, dentro da transação do submit', function () {
    $member = ephemeralMember();

    // Concorrência de verdade não é reproduzível aqui: o RefreshDatabase mantém
    // a transação do teste aberta numa conexão só, e uma segunda conexão não
    // enxergaria nem o membro. O que dá para provar — e é o que falha se alguém
    // trocar o lock por um count() solto — é que a contagem acontece sob
    // `for update`: sem isso, dois submits leem 4 e gravam os dois, e 5 vira 6.
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    photoService()->create($member, ephemeralUpload(), 24);

    $locks = array_filter($statements, fn (string $sql) => str_contains($sql, 'for update'));

    expect($locks)->not->toBeEmpty();

    // O lock é na linha do TITULAR, não nas fotos: as linhas que fariam o cap
    // estourar são justamente as que ainda não existem.
    expect(implode(' | ', $locks))->toContain('from `users`');
});

it('recusa TTL fora do menu de 24h/72h/7 dias', function () {
    $member = ephemeralMember();

    try {
        photoService()->create($member, ephemeralUpload(), 720);
        $this->fail('TTL arbitrário deveria ter sido recusado.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::INVALID_TTL);
    }

    expect(MemberPhoto::count())->toBe(0)
        ->and(Storage::disk(MemberPhotoStore::DISK)->allFiles())->toBeEmpty();
});

// ─── Expiração na LEITURA, não no job (§ 1.3) ────────────────────────────────

it('não serve a foto vencida mesmo com o arquivo ainda no disco', function () {
    $member = ephemeralMember();
    $photo = expirePhoto(photoService()->create($member, ephemeralUpload(), 24));

    // O ponto do teste: o GC não rodou, os bytes continuam lá — e mesmo assim
    // ninguém os lê. Se o corte dependesse do job, job parado viraria acesso
    // indefinido.
    expect(photoStore()->exists($photo->path_encrypted))->toBeTrue();

    try {
        photoService()->readForMember($member, $photo);
        $this->fail('Foto vencida não deveria ser servida.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::EXPIRED);
    }
});

it('não serve à performer quando a foto venceu', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    expirePhoto($photo);

    try {
        photoService()->readForPerformer($performer->user, $access->refresh());
        $this->fail('Foto vencida não deveria ser servida à performer.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::EXPIRED);
    }

    // E a leitura recusada não vira visualização.
    expect($access->refresh()->viewed_at)->toBeNull();
});

it('não serve quando o acesso venceu antes da foto', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 168);
    $access = photoService()->grantTo($member, $photo, $performer);
    $access->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($photo->isExpired())->toBeFalse();

    try {
        photoService()->readForPerformer($performer->user, $access);
        $this->fail('Acesso vencido não deveria servir a foto.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::EXPIRED);
    }
});

it('entrega a foto viva à performer e marca a visualização uma vez', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    $bytes = photoService()->readForPerformer($performer->user, $access);

    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");

    $firstView = $access->refresh()->viewed_at;
    expect($firstView)->not->toBeNull();

    Carbon::setTestNow(now()->addHour());
    photoService()->readForPerformer($performer->user, $access->refresh());

    // Reabrir não reescreve: a coluna viraria um log de quando a performer volta
    // a olhar a foto — dado que nenhuma tela pode mostrar.
    expect($access->refresh()->viewed_at->timestamp)->toBe($firstView->timestamp);

    Carbon::setTestNow();
});

it('nunca deixa o acesso durar mais que a foto', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);

    // Grant de 7 dias sobre foto de 24h: sem o clamp a linha ficaria "ativa"
    // apontando para bytes que o GC já levou, e a tela mostraria "Expira nesta
    // semana" para uma foto morta.
    $access = photoService()->grantTo($member, $photo, $performer, 168);

    expect($access->expires_at->timestamp)->toBe($photo->expires_at->timestamp);
});

it('renova o acesso do mesmo par em vez de empilhar linhas', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);

    photoService()->grantTo($member, $photo, $performer);
    photoService()->grantTo($member, $photo, $performer);

    // O agregado que o membro vê é "com quantas PERFORMERS você compartilhou"
    // (§ 1.1), não quantos grants ele emitiu.
    expect(MemberPhotoAccess::where('member_photo_id', $photo->id)->count())->toBe(1);
});

// ─── Autorização: o guard vive no Service (item 9 do CLAUDE.md) ──────────────

it('não serve ao membro a foto de outro membro', function () {
    $owner = ephemeralMember();
    $intruder = ephemeralMember();

    $photo = photoService()->create($owner, ephemeralUpload(), 24);

    try {
        photoService()->readForMember($intruder, $photo);
        $this->fail('Foto de outro membro não deveria ser servida.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::FORBIDDEN);
    }
});

it('não deixa um membro compartilhar a foto de outro', function () {
    $owner = ephemeralMember();
    $intruder = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($owner, ephemeralUpload(), 24);

    try {
        photoService()->grantTo($intruder, $photo, $performer);
        $this->fail('Grant sobre foto alheia deveria ter sido recusado.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::FORBIDDEN);
    }

    expect(MemberPhotoAccess::where('member_photo_id', $photo->id)->exists())->toBeFalse();
});

it('não serve à performer o acesso que foi concedido a outra', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();
    $outra = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    // O IDOR concreto: com route-model binding em `/fotos/{access}`, basta
    // incrementar o id. Sem o guard, a outra performer recebe os bytes E o
    // `viewed_at` é carimbado na conta errada.
    try {
        photoService()->readForPerformer($outra->user, $access);
        $this->fail('Acesso de outra performer não deveria ser servido.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::FORBIDDEN);
    }

    expect($access->refresh()->viewed_at)->toBeNull();
});

it('não serve a quem não tem perfil de performer', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    // Sem perfil não há com o que comparar, e "sem com o que comparar" tem de
    // ser NÃO — o contrário aceitaria qualquer conta sem perfil.
    try {
        photoService()->readForPerformer(ephemeralMember(), $access);
        $this->fail('Conta sem perfil de performer não deveria ler o acesso.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::FORBIDDEN);
    }
});

it('recusa o grant sobre foto já vencida', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = expirePhoto(photoService()->create($member, ephemeralUpload(), 24));

    try {
        photoService()->grantTo($member, $photo, $performer);
        $this->fail('Grant sobre foto morta deveria ter sido recusado.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::EXPIRED);
    }
});

it('não aceita as FKs nem o prazo do acesso por mass assignment', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);

    $access = new MemberPhotoAccess([
        'member_photo_id' => $photo->id,
        'performer_profile_id' => $performer->id,
        'expires_at' => now()->addYear(),
    ]);

    // `expires_at` é DERIVADO do clamp (o menor entre o pedido e o prazo da
    // foto). Aceito por payload, o cliente teria um acesso que sobrevive ao
    // conteúdo — que é a regra § 1.8 inteira. As FKs, pelo mesmo raciocínio do
    // `user_id` da foto: quem escolhe é o Service, a partir do autenticado.
    expect($access->member_photo_id)->toBeNull()
        ->and($access->performer_profile_id)->toBeNull()
        ->and($access->expires_at)->toBeNull();
});

// ─── Faixa de tempo, nunca relógio (§ 1.2) ───────────────────────────────────

it('devolve o tempo restante em faixa', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    // Meio da tarde em São Paulo: as faixas são lidas por gente no Brasil, e a
    // app roda em UTC — derivar de config('app.timezone') rotularia errado.
    Carbon::setTestNow(Carbon::parse('2026-07-29 15:00:00', 'America/Sao_Paulo'));

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    $slotFor = function (string $expiresAt) use ($access) {
        $access->forceFill(['expires_at' => Carbon::parse($expiresAt, 'America/Sao_Paulo')])->save();

        return $access->timeRemainingSlot();
    };

    expect($slotFor('2026-07-29 23:00:00'))->toBe(MemberPhotoAccess::SLOT_TODAY)
        ->and($slotFor('2026-07-31 10:00:00'))->toBe(MemberPhotoAccess::SLOT_SOME_DAYS)
        ->and($slotFor('2026-08-04 10:00:00'))->toBe(MemberPhotoAccess::SLOT_THIS_WEEK)
        ->and($slotFor('2026-07-29 14:00:00'))->toBe(MemberPhotoAccess::SLOT_EXPIRED);

    Carbon::setTestNow();
});

it('não expõe relógio, TTL nem viewed_at para a performer', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 168);
    $access = photoService()->grantTo($member, $photo, $performer);
    $access->markViewed();

    $payload = $access->refresh()->presentForPerformer();

    // `viewed_at` é a ação DELA e não volta em hipótese alguma (§ 1.8); o TTL
    // escolhido é postura do membro e é sinal por si só (§ 1.2); e um countdown
    // devolveria `granted_at` ao minuto — o oráculo que o painel de visitantes
    // já pagou para fechar.
    expect(array_keys($payload))->toBe(['expires_slot'])
        ->and($payload['expires_slot'])->toBeString();

    // Nem por serialização, que é o caminho para as props do Inertia.
    expect($access->toArray())->not->toHaveKey('viewed_at');

    // O titular sabe que foi aberta — em booleano, nunca em relógio.
    expect($access->presentForMember())->toBe([
        'expires_slot' => $payload['expires_slot'],
        'viewed' => true,
    ]);
});

it('não expõe o caminho no disco nem o nome original em serialização', function () {
    $member = ephemeralMember();
    $photo = photoService()->create($member, ephemeralUpload(name: 'selfie-do-quarto.jpg'), 24);

    expect($photo->toArray())
        ->not->toHaveKey('path_encrypted')
        ->not->toHaveKey('original_filename')
        // `user_id` é o `member_id` cru — o id que o FanAlias existe para tirar
        // de circulação, e a serialização é o caminho para as props do Inertia.
        ->not->toHaveKey('user_id')
        // `expires_at` é relógio: com o TTL vindo de um menu de três opções, o
        // timestamp devolve `granted_at` ao minuto (§ 1.2). O que a tela mostra
        // é a faixa de timeRemainingSlot().
        ->not->toHaveKey('expires_at');

    // E esconder da serialização não pode ter quebrado o código que decide:
    expect($photo->isExpired())->toBeFalse()
        ->and(MemberPhoto::activeForUser($member->id)->count())->toBe(1);

    // E as colunas estão cifradas em repouso: um dump do banco não entrega nem
    // o caminho (que carrega o id do titular) nem o nome (que costuma ser tão
    // descritivo quanto a foto).
    $raw = DB::table('member_photos')->where('id', $photo->id)->first();

    expect($raw->original_filename)->not->toContain('selfie')
        ->and($raw->path_encrypted)->not->toContain('.enc')
        ->and($photo->fresh()->original_filename)->toBe('selfie-do-quarto.jpg');
});

// ─── Garbage collection (§ 1.3 e § 1.8) ──────────────────────────────────────

it('apaga do disco as fotos vencidas e os acessos delas', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $expired = photoService()->create($member, ephemeralUpload(), 24);
    photoService()->grantTo($member, $expired, $performer);
    expirePhoto($expired);

    $alive = photoService()->create($member, ephemeralUpload(), 24);
    $aliveAccess = photoService()->grantTo($member, $alive, $performer);

    $this->artisan('member-photos:purge')
        ->expectsOutputToContain('expired=1 deleted=1')
        ->assertSuccessful();

    // Bytes fora do disco e linha apagada DE VEZ: a linha morta guardaria
    // `user_id`, tamanho e horário do envio — "o membro X mandou N fotos, nestes
    // horários" —, que é a retenção que a foto efêmera existe para não ter.
    expect(photoStore()->exists($expired->path_encrypted))->toBeFalse()
        ->and(MemberPhoto::withTrashed()->find($expired->id))->toBeNull();

    // O acesso morre JUNTO com a foto (§ 1.8): a tabela é um mapa persistente de
    // quem mostrou o rosto para quem, e sobreviver aos bytes é o risco inteiro.
    // O `cascadeOnDelete` da FK não cobre — a foto sai por soft delete (item 11
    // do CLAUDE.md), então quem apaga é código.
    expect(MemberPhotoAccess::where('member_photo_id', $expired->id)->exists())->toBeFalse();

    // E a foto viva não é tocada.
    expect(photoStore()->exists($alive->path_encrypted))->toBeTrue()
        ->and(MemberPhoto::find($alive->id))->not->toBeNull()
        ->and(MemberPhotoAccess::whereKey($aliveAccess->id)->exists())->toBeTrue();
});

it('reporta como alarme as fotos vencidas há mais de um ciclo e ainda no disco', function () {
    $member = ephemeralMember();

    // Vencida dentro do ciclo: é o trabalho normal do GC, não é alarme.
    expirePhoto(photoService()->create($member, ephemeralUpload(), 24), '-30 minutes');

    $this->artisan('member-photos:purge')
        ->expectsOutputToContain('stale=0')
        ->assertSuccessful();

    // Vencida há mais de um ciclo com o arquivo ainda lá: a rodada anterior não
    // a levou. É o único sinal que hoje existe de que o GC parou (§ 1.3) —
    // persistente e diferente de zero é o alarme.
    expirePhoto(photoService()->create($member, ephemeralUpload(), 24), '-3 hours');

    $this->artisan('member-photos:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0 stale=1 failed=0')
        ->assertSuccessful();
});

it('destrói bytes, acessos e linha num passo só', function () {
    $member = ephemeralMember();
    $performer = chatPerformer();

    $photo = photoService()->create($member, ephemeralUpload(), 24);
    $access = photoService()->grantTo($member, $photo, $performer);

    photoService()->destroy($photo);

    expect(photoStore()->exists($photo->path_encrypted))->toBeFalse()
        ->and(MemberPhotoAccess::whereKey($access->id)->exists())->toBeFalse()
        ->and(MemberPhoto::withTrashed()->find($photo->id))->toBeNull();
});

it('deixa o titular destruir a própria foto e mais ninguém', function () {
    $owner = ephemeralMember();
    $intruder = ephemeralMember();

    $photo = photoService()->create($owner, ephemeralUpload(), 24);

    // O endpoint de revoke (PR 3) chama destroyForMember, não destroy: com
    // route-model binding em `DELETE /fotos/{photo}` e um controller que só
    // delega, o primitivo sem ator deixaria qualquer membro apagar a foto de
    // outro — bytes, acessos e linha.
    try {
        photoService()->destroyForMember($intruder, $photo);
        $this->fail('Destruir foto alheia deveria ter sido recusado.');
    } catch (MemberPhotoException $e) {
        expect($e->reason)->toBe(MemberPhotoException::FORBIDDEN);
    }

    expect(photoStore()->exists($photo->path_encrypted))->toBeTrue()
        ->and(MemberPhoto::find($photo->id))->not->toBeNull();

    photoService()->destroyForMember($owner, $photo);

    expect(photoStore()->exists($photo->path_encrypted))->toBeFalse()
        ->and(MemberPhoto::withTrashed()->find($photo->id))->toBeNull();
});

it('mantém a linha de pé quando o disco recusa apagar os bytes', function () {
    $member = ephemeralMember();
    $photo = expirePhoto(photoService()->create($member, ephemeralUpload(), 24));

    // Reproduz a falha real: permissão errada no diretório depois de um deploy.
    // O disco roda com `throw => false`, então sem a checagem de retorno no
    // Store o `delete()` devolveria `false` em silêncio, `destroy()` seguiria em
    // frente e a linha soft-deletada esconderia do GC um arquivo que continua
    // no disco — o rosto do membro ficaria lá com o alarme marcando zero.
    $dir = dirname(Storage::disk(MemberPhotoStore::DISK)->path($photo->path_encrypted));
    chmod($dir, 0o500);

    try {
        expect(fn () => photoService()->destroy($photo))->toThrow(RuntimeException::class);

        // A linha NÃO foi soft-deletada: é o que deixa a rodada seguinte tentar
        // de novo, e é o que faz o alarme `stale` continuar enxergando a foto.
        expect(MemberPhoto::find($photo->id))->not->toBeNull()
            ->and(photoStore()->exists($photo->path_encrypted))->toBeTrue();
    } finally {
        chmod($dir, 0o700);
    }
});

it('reexamina a foto cuja linha morreu com o arquivo ainda no disco', function () {
    $member = ephemeralMember();
    $photo = expirePhoto(photoService()->create($member, ephemeralUpload(), 24), '-3 hours');

    // O estado que a varredura sem `withTrashed()` perdia para sempre: linha
    // soft-deletada, bytes de pé. Hoje `destroy()` aborta antes de chegar aqui,
    // mas o estado ainda é alcançável por outro caminho (revoke, um bug novo) —
    // e sem a rede o arquivo ficaria no volume indefinidamente, invisível a
    // qualquer rodada futura, porque a linha saiu do escopo padrão.
    $photo->delete();

    expect(MemberPhoto::find($photo->id))->toBeNull()
        ->and(photoStore()->exists($photo->path_encrypted))->toBeTrue();

    $this->artisan('member-photos:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=0 stale=1 failed=0')
        ->assertSuccessful();

    expect(photoStore()->exists($photo->path_encrypted))->toBeFalse();
});

it('não reprocessa para sempre a foto já recolhida', function () {
    $member = ephemeralMember();
    $photo = expirePhoto(photoService()->create($member, ephemeralUpload(), 24));

    $this->artisan('member-photos:purge')->assertSuccessful();

    // A rodada normal termina em hard delete, então não sobra linha nenhuma. E
    // mesmo que sobrasse (uma soft-deletada vinda de outro caminho), linha morta
    // sem arquivo sai da contagem: sem esse corte, cada rodada horária
    // decifraria `path_encrypted` de toda foto que já existiu só para pular.
    $this->artisan('member-photos:purge')
        ->expectsOutputToContain('expired=0 deleted=0 quarantined=0 stale=0 failed=0')
        ->assertSuccessful();

    expect(MemberPhoto::withTrashed()->count())->toBe(0);
});

// ─── Modo Discreto (§ 1.11) ──────────────────────────────────────────────────

it('deixa o membro em Modo Discreto enviar foto', function () {
    $member = ephemeralMember();
    $member->forceFill(['discrete_mode' => true])->save();

    // Regra ESCRITA, não omissão: `ProfileVisitService::record()` barra o membro
    // discreto porque visita é listagem — a performer o veria sem que ele
    // tivesse feito nada. Mandar a própria foto é auto-listagem voluntária, com
    // aviso na tela de envio. Não "consertar" em nenhum dos dois sentidos sem o PO.
    $photo = photoService()->create($member->refresh(), ephemeralUpload(), 24);

    expect($photo->exists)->toBeTrue()
        ->and(MemberPhoto::activeForUser($member->id)->count())->toBe(1);
});
