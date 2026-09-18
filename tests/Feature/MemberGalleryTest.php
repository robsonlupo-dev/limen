<?php

use App\Models\CsamHash;
use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\MemberGalleryService;
use App\Services\PerceptualHashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Galeria de perfil do MEMBRO (feat/member-gallery-and-profile, Estágio 1).
 *
 * Feature sensível (rosto de usuário em site adulto). Estes testes travam os
 * invariantes de privacidade: opt-in default OFF, moderação humana obrigatória
 * (pending → approved), serving por token OPACO (nunca o member_id), teto de 4
 * fotos, revogação imediata (bytes somem do disco na hora), strip de metadados.
 *
 * Helpers `mg*` para o arquivo rodar isolado (funções do Pest são globais).
 */
beforeEach(function () {
    Storage::fake('local');
});

function mgMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ], $overrides));
}

function mgUpload(User $member, string $name = 'me.jpg', int $w = 800, int $h = 1000): void
{
    test()->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->image($name, $w, $h)])
        ->assertRedirect();
}

// ─── Upload: entra PENDING, moderação humana obrigatória ─────────────────────

it('a foto entra como PENDING e existe no disco — nunca approved sozinha', function () {
    $member = mgMember();
    mgUpload($member);

    $photo = MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail();
    expect($photo->status)->toBe(MemberGalleryPhoto::STATUS_PENDING)
        ->and($photo->is_primary)->toBeFalse()
        ->and($photo->token)->not->toBeNull();
    Storage::disk('local')->assertExists($photo->path);
});

it('foto PENDING NÃO aparece para a performer (approvedFor vazio até aprovar)', function () {
    $member = mgMember();
    mgUpload($member);

    $gallery = app(MemberGalleryService::class);
    expect($gallery->approvedFor($member))->toHaveCount(0)
        ->and($gallery->primaryApprovedUrlFor($member))->toBeNull();
});

// ─── Teto de 4 fotos ATIVAS ──────────────────────────────────────────────────

it('recusa a 5ª foto: no máximo 4 ativas (pending+approved)', function () {
    $member = mgMember();
    foreach (range(1, 4) as $i) {
        mgUpload($member, "p{$i}.jpg");
    }

    $this->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->image('fifth.jpg', 600, 600)])
        ->assertSessionHasErrors('file');

    expect(MemberGalleryPhoto::where('user_id', $member->id)->count())->toBe(4);
});

it('uma foto RECUSADA não conta no teto — o membro pode reenviar', function () {
    $member = mgMember();
    foreach (range(1, 4) as $i) {
        mgUpload($member, "p{$i}.jpg");
    }
    $victim = MemberGalleryPhoto::where('user_id', $member->id)->first();

    // Recusa uma → libera um slot.
    app(MemberGalleryService::class)->reject($victim, mgModerator(), 'motivo');

    // A 5ª agora passa (só 3 ativas + 1 rejeitada que não conta).
    $this->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->image('new.jpg', 600, 600)])
        ->assertRedirect();

    expect(MemberGalleryPhoto::where('user_id', $member->id)->active()->count())->toBe(4);
});

// ─── Validação e anti-CSAM (mesmo pipeline do avatar) ────────────────────────

it('recusa arquivo não-imagem (regra de mime do UploadMediaRequest)', function () {
    $member = mgMember();

    $this->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->create('payload.php', 10)])
        ->assertSessionHasErrors('file');

    expect(MemberGalleryPhoto::where('user_id', $member->id)->count())->toBe(0);
});

it('a foto da galeria passa pelo anti-CSAM: um match bloqueia e nada é gravado', function () {
    config(['csam.enabled' => true]);
    $member = mgMember();

    // Semeia o phash do que o pipeline vai produzir (scan roda nos bytes JÁ
    // processados, sem crop — a galeria preserva a proporção).
    $processed = app(ImageProcessingService::class)
        ->process(UploadedFile::fake()->image('bad.jpg', 800, 800), MemberGalleryService::MAX_DIMENSION, MemberGalleryService::MAX_DIMENSION, crop: false);
    $hash = app(PerceptualHashService::class)->hash(file_get_contents($processed));
    @unlink($processed);
    CsamHash::create(['hash' => $hash, 'source' => 'test', 'added_at' => now()]);

    $this->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->image('bad.jpg', 800, 800)])
        ->assertSessionHasErrors('file');

    expect(MemberGalleryPhoto::where('user_id', $member->id)->count())->toBe(0)
        ->and($member->fresh()->csam_flagged_at)->not->toBeNull();
});

// ─── Metadados removidos (re-encode do pipeline compartilhado) ───────────────

it('a imagem servida é re-encodada para JPEG (EXIF/GPS morrem no re-encode)', function () {
    $member = mgMember();
    // Entrada PNG; a saída do pipeline é sempre JPEG (o re-encode mata metadado).
    mgUpload($member, 'source.png');

    $photo = MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail();
    $bytes = Storage::disk('local')->get($photo->path);
    $info = getimagesizefromstring($bytes);

    expect($info)->not->toBeFalse()
        ->and($info['mime'])->toBe('image/jpeg');
});

// ─── Serving por token OPACO + revogação imediata ────────────────────────────

it('a URL da foto é chaveada no token OPACO, NUNCA no id/user_id', function () {
    $member = mgMember();
    mgUpload($member);
    $photo = MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail();

    $url = $photo->mediaUrl();
    expect($url)->toContain($photo->token)
        ->and($url)->not->toContain('user_id')
        ->and($url)->not->toContain("/{$photo->id}");

    // A rota assinada entrega os bytes ao DONO (preview da gestão, qualquer status).
    $this->actingAs($member)->get($url)->assertOk();
});

it('uma foto PENDING não é servida a terceiros (só approved de perfil visível)', function () {
    // Foto criada direto (sem autenticar o dono) → o GET abaixo é um TERCEIRO.
    $member = mgMember();
    $photo = mgMakePhoto($member, 'pending');

    // Terceiro (sem sessão do dono) e foto pending → 404 (o gate não serve pending).
    $this->get($photo->mediaUrl())->assertNotFound();

    // Aprova e liga o perfil visível → agora a URL assinada serve a qualquer um.
    app(MemberGalleryService::class)->approve($photo, mgModerator());
    app(MemberGalleryService::class)->setVisibility($member, true);
    $this->get($photo->fresh()->mediaUrl())->assertOk();
});

it('desligar o perfil visível REVOGA na hora a foto aprovada já servível', function () {
    $member = mgMember();
    $photo = mgMakePhoto($member, 'pending');
    app(MemberGalleryService::class)->approve($photo, mgModerator());
    app(MemberGalleryService::class)->setVisibility($member, true);
    $url = $photo->fresh()->mediaUrl();

    // Terceiro consegue ver enquanto aprovada + perfil visível.
    $this->get($url)->assertOk();

    // Desligou → a MESMA URL assinada (ainda válida) para de servir na hora.
    app(MemberGalleryService::class)->setVisibility($member, false);
    $this->get($url)->assertNotFound();
});

it('member.gallery.media exige assinatura válida (sem ela, 403)', function () {
    $member = mgMember();
    mgUpload($member);
    $token = MemberGalleryPhoto::where('user_id', $member->id)->value('token');

    $this->get('/membro/galeria/midia?token='.$token)->assertStatus(403);
});

it('REVOGAR uma foto remove os bytes do serving NA HORA (URL assinada 404)', function () {
    $member = mgMember();
    mgUpload($member);
    $photo = MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail();
    $url = $photo->mediaUrl();
    $path = $photo->path;

    // A URL funcionava para o dono (preview da gestão, pending).
    $this->actingAs($member)->get($url)->assertOk();

    // Remove.
    $this->actingAs($member)
        ->delete(route('consumer.gallery.destroy', $photo->id))
        ->assertRedirect();

    // Bytes somem do disco, linha some, e a URL ASSINADA (ainda válida) 404.
    Storage::disk('local')->assertMissing($path);
    expect(MemberGalleryPhoto::find($photo->id))->toBeNull();
    $this->actingAs($member)->get($url)->assertNotFound();
});

it('um membro NÃO remove a foto de outro (404, posse reconferida)', function () {
    $owner = mgMember();
    mgUpload($owner);
    $photo = MemberGalleryPhoto::where('user_id', $owner->id)->firstOrFail();

    $intruder = mgMember();
    $this->actingAs($intruder)
        ->delete(route('consumer.gallery.destroy', $photo->id))
        ->assertNotFound();

    expect(MemberGalleryPhoto::find($photo->id))->not->toBeNull();
});

// ─── Opt-in mestre: perfil visível default OFF ───────────────────────────────

it('profile_visible nasce FALSE (opt-in, default oculto)', function () {
    $member = mgMember();
    expect((bool) $member->fresh()->profile_visible)->toBeFalse();
});

it('o membro liga e desliga o perfil visível pela rota dedicada', function () {
    $member = mgMember();

    $this->actingAs($member)
        ->patch(route('consumer.gallery.visibility'), ['profile_visible' => true])
        ->assertRedirect();
    expect((bool) $member->fresh()->profile_visible)->toBeTrue();

    $this->actingAs($member)
        ->patch(route('consumer.gallery.visibility'), ['profile_visible' => false])
        ->assertRedirect();
    expect((bool) $member->fresh()->profile_visible)->toBeFalse();
});

// ─── Foto principal ──────────────────────────────────────────────────────────

it('a primeira foto APROVADA vira principal automaticamente', function () {
    $member = mgMember();
    mgUpload($member, 'a.jpg');
    mgUpload($member, 'b.jpg');
    $photos = MemberGalleryPhoto::where('user_id', $member->id)->orderBy('id')->get();

    app(MemberGalleryService::class)->approve($photos[0], mgModerator());
    expect($photos[0]->fresh()->is_primary)->toBeTrue();

    // A segunda aprovada NÃO rouba a principal.
    app(MemberGalleryService::class)->approve($photos[1], mgModerator());
    expect($photos[1]->fresh()->is_primary)->toBeFalse()
        ->and($photos[0]->fresh()->is_primary)->toBeTrue();
});

it('só uma foto APROVADA pode ser designada principal (pending → 422)', function () {
    $member = mgMember();
    mgUpload($member);
    $photo = MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail();

    // Pendente não pode virar principal.
    $this->actingAs($member)
        ->patch(route('consumer.gallery.primary', $photo->id))
        ->assertStatus(422);
});

it('remover a principal reatribui a principal a outra foto aprovada', function () {
    $member = mgMember();
    mgUpload($member, 'a.jpg');
    mgUpload($member, 'b.jpg');
    $photos = MemberGalleryPhoto::where('user_id', $member->id)->orderBy('id')->get();
    $gallery = app(MemberGalleryService::class);
    $gallery->approve($photos[0], mgModerator());
    $gallery->approve($photos[1], mgModerator());

    // Remove a principal (a[0]).
    $gallery->remove($member, $photos[0]->fresh());

    expect($photos[1]->fresh()->is_primary)->toBeTrue();
});

// helper local: moderador de pé
function mgModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active']);
}

// helper local: cria uma foto direto (com arquivo fake), sem passar pela rota
// autenticada — para o serving ser exercitado como TERCEIRO (não o dono).
function mgMakePhoto(User $member, string $status = 'pending'): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'bytes');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $path;
    $photo->token = Str::random(48);
    $photo->status = $status;
    $photo->save();

    return $photo;
}
