<?php

use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Services\MemberCatalogService;
use App\Services\MemberGalleryService;
use App\Support\FanAlias;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Visibilidade POR FOTO da galeria do membro (feat/member-gallery-per-photo-privacy,
 * Etapa 1). Espelha o is_private da galeria da performer: foto ABERTA é vista por
 * quem já vê o perfil; PRIVADA sai borrada e só o dono vê (o pedir→liberar por
 * performer vem na Etapa 2). Trava: foto nova nasce privada; o toggle é do dono; o
 * serving e o presenter derivam do MESMO predicado (sem oráculo); a privada nunca
 * vira thumbnail do catálogo. Helpers `pp*` para o arquivo rodar isolado.
 */
beforeEach(function () {
    Storage::fake('local');
});

function mgppMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs));
}

function mgppPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function mgppPhoto(User $member, bool $isPrivate, string $status = 'approved', bool $primary = false): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    $full = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'cropped');
    Storage::disk('local')->put($full, 'full');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $status === 'rejected' ? '' : $path;
    $photo->full_path = $status === 'rejected' ? '' : $full;
    $photo->token = Str::random(48);
    $photo->full_token = Str::random(48);
    $photo->status = $status;
    $photo->is_primary = $primary;
    $photo->is_private = $isPrivate;
    $photo->save();

    return $photo;
}

// ─── Default privado + toggle do dono ────────────────────────────────────────

it('foto nova da galeria nasce PRIVADA', function () {
    $member = mgppMember();

    $this->actingAs($member)->post(route('consumer.gallery.store'), [
        'file' => UploadedFile::fake()->image('me.jpg', 800, 1000),
    ]);

    expect(MemberGalleryPhoto::where('user_id', $member->id)->firstOrFail()->is_private)->toBeTrue();
});

it('o dono tranca e destranca a própria foto', function () {
    $member = mgppMember();
    $photo = mgppPhoto($member, isPrivate: true);

    $this->actingAs($member)
        ->patch(route('consumer.gallery.photo-visibility', $photo->id), ['is_private' => false])
        ->assertRedirect();
    expect($photo->fresh()->is_private)->toBeFalse();

    $this->actingAs($member)
        ->patch(route('consumer.gallery.photo-visibility', $photo->id), ['is_private' => true])
        ->assertRedirect();
    expect($photo->fresh()->is_private)->toBeTrue();
});

it('foto de outro membro não é trancável (404 indistinguível)', function () {
    $owner = mgppMember();
    $other = mgppMember();
    $photo = mgppPhoto($owner, isPrivate: true);

    $this->actingAs($other)
        ->patch(route('consumer.gallery.photo-visibility', $photo->id), ['is_private' => false])
        ->assertNotFound();

    expect($photo->fresh()->is_private)->toBeTrue();
});

it('foto recusada não tem o que trancar (404)', function () {
    $member = mgppMember();
    $photo = mgppPhoto($member, isPrivate: true, status: 'rejected');

    $this->actingAs($member)
        ->patch(route('consumer.gallery.photo-visibility', $photo->id), ['is_private' => false])
        ->assertNotFound();
});

// ─── Serving: as duas pontas concordam ───────────────────────────────────────

it('foto PRIVADA aprovada não vaza para terceiro; a PÚBLICA serve', function () {
    $member = mgppMember(['profile_visible' => true]);
    $priv = mgppPhoto($member, isPrivate: true);
    $pub = mgppPhoto($member, isPrivate: false);

    // Terceiro (sem sessão do dono): privada 404, pública 200.
    $this->get($priv->mediaUrl())->assertNotFound();
    $this->get($pub->mediaUrl())->assertOk();

    // O DONO vê a própria privada (preview da gestão).
    $this->actingAs($member)->get($priv->mediaUrl())->assertOk();
});

it('destrancar libera o serving e trancar revoga na hora (mesma URL assinada)', function () {
    $member = mgppMember(['profile_visible' => true]);
    $photo = mgppPhoto($member, isPrivate: true);
    $url = $photo->mediaUrl();

    $this->get($url)->assertNotFound(); // privada

    app(MemberGalleryService::class)->setPhotoVisibility($member, $photo, false);
    $this->get($url)->assertOk(); // aberta

    app(MemberGalleryService::class)->setPhotoVisibility($member, $photo->fresh(), true);
    $this->get($url)->assertNotFound(); // trancada de novo, na hora
});

// ─── Presenter + catálogo derivam do mesmo predicado ─────────────────────────

it('approvedFor marca a privada como locked (sem url) e a pública com url', function () {
    $member = mgppMember(['profile_visible' => true]);
    $pub = mgppPhoto($member, isPrivate: false, primary: true);
    $priv = mgppPhoto($member, isPrivate: true);

    $rows = app(MemberGalleryService::class)->approvedFor($member->fresh())->keyBy('id');

    expect($rows[$pub->id]['locked'])->toBeFalse()
        ->and($rows[$pub->id]['url'])->not->toBeNull()
        ->and($rows[$priv->id]['locked'])->toBeTrue()
        ->and($rows[$priv->id]['url'])->toBeNull()
        ->and($rows[$priv->id]['full_url'])->toBeNull();
});

it('a foto privada não vira thumbnail nem entra na contagem do card do catálogo', function () {
    // Exercita a superfície VIVA (MemberCatalogService::page → primaryPhotoUrls /
    // approvedPhotoCounts): a privada não pode virar avatar_url (URL que daria 404
    // no fetch = oráculo) nem contar no badge. Reproduz o cenário do achado HIGH:
    // primeira foto aprovada é auto-principal e nasce privada.
    $performer = mgppPerformer();
    $member = mgppMember(['profile_visible' => true]); // sem avatar
    $priv = mgppPhoto($member, isPrivate: true, primary: true);
    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $card = app(MemberCatalogService::class)->page($performer->performerProfile)
        ->getCollection()->firstWhere('member_handle', $handle);

    expect($card['avatar_url'])->toBeNull()
        ->and($card['photo_count'])->toBe(0);

    // Abrindo, ela passa a ser a cara pública e a contar.
    app(MemberGalleryService::class)->setPhotoVisibility($member, $priv, false);

    $card = app(MemberCatalogService::class)->page($performer->performerProfile)
        ->getCollection()->firstWhere('member_handle', $handle);

    expect($card['avatar_url'])->toContain($priv->token)
        ->and($card['photo_count'])->toBe(1);
});

it('a performer vê a foto privada como locked no payload (sem bytes)', function () {
    $performer = mgppPerformer();
    $member = mgppMember(['profile_visible' => true]);
    mgppPhoto($member, isPrivate: true, primary: true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', FanAlias::handle($performer->performerProfile->id, $member->id)))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('member.photos.0.locked', true)
            ->where('member.photos.0.url', null)
            ->where('member.photos.0.is_private', true)
            ->etc());
});
