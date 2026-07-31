<?php

use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Galeria de fotos do perfil da performer — Sprint 10.
 *
 * Os eixos: (1) o cap de 6 é enforçado no servidor; (2) só a dona apaga/reordena;
 * (3) o serving é PÚBLICO mas passa pela camada de bytes (re-sniff + nosniff);
 * (4) EXIF/GPS morrem na ingestão; (5) o Hard Delete leva linhas E bytes.
 *
 * Helpers com prefixo `pg` (photo gallery) — as funções do Pest são globais.
 */
beforeEach(function () {
    Storage::fake(PerformerPhotoStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function pgJpeg(int $width = 60, int $height = 40): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 180, 90, 40));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** JPEG com APP1/EXIF carregando GPS — as coordenadas são da PERFORMER. */
function pgJpegWithGps(): string
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

    return "\xFF\xD8".$app1.substr(pgJpeg(), 2);
}

function pgUpload(?string $bytes = null, string $name = 'foto.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_pg_');
    file_put_contents($path, $bytes ?? pgJpeg());

    return new UploadedFile($path, $name, $mime, null, true);
}

/** Cria N linhas de foto diretamente (sem passar pela ingestão), na ordem. */
function pgSeed(PerformerProfile $profile, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $path = $profile->id.'/'.Str::random(40).'.jpg';
        Storage::disk(PerformerPhotoStore::DISK)->put($path, pgJpeg());
        $profile->photos()->create(['path' => $path, 'position' => $i]);
    }
}

// ─── Upload ──────────────────────────────────────────────────────────────────

it('salva a foto: linha, posição e bytes no disco', function () {
    $performer = chatPerformer();

    $response = $this->actingAs($performer->user)
        ->post(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertCreated();

    $photo = PerformerPhoto::sole();

    expect($photo->performer_profile_id)->toBe($performer->id)
        ->and($photo->position)->toBe(1)
        ->and(Storage::disk(PerformerPhotoStore::DISK)->exists($photo->path))->toBeTrue();

    // A resposta devolve a lista atualizada, cada item com id, url e posição —
    // e a url aponta para o serving público, nunca para o caminho no disco.
    expect($response->json('photos'))->toHaveCount(1)
        ->and($response->json('photos.0.id'))->toBe($photo->id)
        ->and($response->json('photos.0.url'))->toContain('/performer/fotos/'.$photo->id.'/imagem')
        ->and($response->json('photos.0.url'))->not->toContain($photo->path);
});

it('remove EXIF/GPS da foto na ingestão', function () {
    $performer = chatPerformer();
    $upload = pgUpload(pgJpegWithGps());

    // A fixture PRECISA carregar GPS, senão o teste não prova nada.
    expect(@exif_read_data($upload->getRealPath()))->toBeArray()->toHaveKeys(['GPSLatitude', 'GPSLongitude']);

    $this->actingAs($performer->user)
        ->post(route('performer.gallery.store'), ['foto' => $upload])
        ->assertCreated();

    $photo = PerformerPhoto::sole();

    // Lê pelo próprio serving público — o caminho que o visitante usa.
    $bytes = $this->get(route('performer.gallery.image', $photo->id))->assertOk()->getContent();

    $tmp = tempnam(sys_get_temp_dir(), 'limen_pg_check_');
    file_put_contents($tmp, $bytes);
    $after = @exif_read_data($tmp);
    @unlink($tmp);

    expect(array_filter(array_keys($after ?: []), fn (string $t) => str_starts_with($t, 'GPS')))->toBeEmpty();
});

it('empurra cada nova foto para o fim do carrossel', function () {
    $performer = chatPerformer();
    pgSeed($performer, 2); // posições 0 e 1

    $this->actingAs($performer->user)
        ->post(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertCreated();

    // A nova entra depois da maior posição existente (1) → 2.
    expect(PerformerPhoto::where('performer_profile_id', $performer->id)->max('position'))->toBe(2);
});

it('recusa a 7ª foto — o cap de 6 é enforçado no servidor', function () {
    $performer = chatPerformer();
    pgSeed($performer, PerformerProfile::MAX_PHOTOS);

    $this->actingAs($performer->user)
        ->postJson(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'cap_reached');

    // Nem a linha nem os bytes órfãos ficam: continua exatamente em 6.
    expect(PerformerPhoto::where('performer_profile_id', $performer->id)->count())->toBe(PerformerProfile::MAX_PHOTOS)
        ->and(Storage::disk(PerformerPhotoStore::DISK)->allFiles())->toHaveCount(PerformerProfile::MAX_PHOTOS);
});

it('recusa o que não é JPEG nem PNG', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->postJson(route('performer.gallery.store'), [
            'foto' => pgUpload('nao-sou-imagem', 'clipe.mp4', 'video/mp4'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.foto.0', 'A foto precisa ser JPEG ou PNG.');
});

// ─── Delete ──────────────────────────────────────────────────────────────────

it('deixa a dona apagar a própria foto — linha e bytes somem', function () {
    $performer = chatPerformer();
    pgSeed($performer, 1);
    $photo = PerformerPhoto::sole();

    $this->actingAs($performer->user)
        ->deleteJson(route('performer.gallery.destroy', $photo->id))
        ->assertOk()
        ->assertJsonPath('photos', []);

    expect(PerformerPhoto::find($photo->id))->toBeNull()
        ->and(Storage::disk(PerformerPhotoStore::DISK)->exists($photo->path))->toBeFalse();
});

it('recusa apagar a foto de outra performer — 403 e a foto fica', function () {
    $owner = chatPerformer();
    pgSeed($owner, 1);
    $photo = PerformerPhoto::sole();

    $intruder = chatPerformer();

    $this->actingAs($intruder->user)
        ->deleteJson(route('performer.gallery.destroy', $photo->id))
        ->assertStatus(403)
        ->assertJsonPath('reason', 'not_owner');

    expect(PerformerPhoto::find($photo->id))->not->toBeNull();
});

// ─── Reorder ─────────────────────────────────────────────────────────────────

it('reordena as fotos — as posições passam a refletir a nova ordem', function () {
    $performer = chatPerformer();
    pgSeed($performer, 3);
    $ids = PerformerPhoto::where('performer_profile_id', $performer->id)->orderBy('position')->pluck('id')->all();

    $reversed = array_reverse($ids);

    $this->actingAs($performer->user)
        ->patchJson(route('performer.gallery.reorder'), ['ids' => $reversed])
        ->assertOk();

    // A posição de cada foto vira o índice dela na lista enviada.
    foreach ($reversed as $index => $id) {
        expect(PerformerPhoto::find($id)->position)->toBe($index);
    }
});

it('recusa reordenar com uma lista que não bate com a galeria', function () {
    $performer = chatPerformer();
    pgSeed($performer, 3);
    $ids = PerformerPhoto::where('performer_profile_id', $performer->id)->pluck('id')->all();

    // Um id de OUTRA performer na lista → conjunto não bate → 422.
    $other = chatPerformer();
    pgSeed($other, 1);
    $alienId = PerformerPhoto::where('performer_profile_id', $other->id)->value('id');

    $this->actingAs($performer->user)
        ->patchJson(route('performer.gallery.reorder'), ['ids' => [...array_slice($ids, 0, 2), $alienId]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_order');
});

// ─── Serving público ─────────────────────────────────────────────────────────

it('serve a foto publicamente com Content-Type de re-sniff e nosniff', function () {
    $performer = chatPerformer();
    pgSeed($performer, 1);
    $photo = PerformerPhoto::sole();

    // SEM actingAs: qualquer visitante vê — é o perfil público.
    $this->get(route('performer.gallery.image', $photo->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

// ─── Contador no card / props do perfil ──────────────────────────────────────

it('expõe photos_count no card e a galeria no perfil público', function () {
    $performer = chatPerformer();
    pgSeed($performer, 2);

    $this->get(route('performers.public.show', $performer->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer.photos_count', 2)
            ->has('photos', 2)
            ->where('photos.0.url', fn ($url) => str_contains($url, '/imagem')));
});

it('não expõe photos_count > 0 para quem não tem foto', function () {
    $performer = chatPerformer();

    $this->get(route('performers.public.show', $performer->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('performer.photos_count', 0)
            ->has('photos', 0));
});

// ─── Hard delete ─────────────────────────────────────────────────────────────

it('o Hard Delete leva as fotos da performer — linhas e bytes', function () {
    $performer = chatPerformer();
    pgSeed($performer, 3);
    $paths = PerformerPhoto::where('performer_profile_id', $performer->id)->pluck('path')->all();

    $log = app(DeletionService::class)->executeDeletion($performer->user);

    expect(PerformerPhoto::where('performer_profile_id', $performer->id)->count())->toBe(0)
        ->and($log->data_summary['performer_photos'])->toBe(3);

    foreach ($paths as $path) {
        expect(Storage::disk(PerformerPhotoStore::DISK)->exists($path))->toBeFalse();
    }
});

// ─── Gates ───────────────────────────────────────────────────────────────────

it('exige autenticação para gerir a galeria', function () {
    $this->post(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertRedirect(route('login'));
});

it('recusa o membro na gestão da galeria — role:performer', function () {
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    // Foto existente para o binding do DELETE não dar 404 antes do gate de role
    // (SubstituteBindings roda antes do middleware — item do CLAUDE.md).
    $performer = chatPerformer();
    pgSeed($performer, 1);
    $photo = PerformerPhoto::sole();

    $this->actingAs($member)
        ->postJson(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertStatus(403);

    $this->actingAs($member)
        ->deleteJson(route('performer.gallery.destroy', $photo->id))
        ->assertStatus(403);
});

it('recusa a performer pendente — can(performer-active)', function () {
    // Status 'pending' (ainda em KYC) é o que o gate performer-active barra —
    // ele checa role+status, não is_verified (ver AppServiceProvider).
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $user->performerProfile()->create([
        'stage_name' => 'Pendente '.Str::random(8),
        'slug' => 'pend-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    $this->actingAs($user)
        ->postJson(route('performer.gallery.store'), ['foto' => pgUpload()])
        ->assertStatus(403);
});
