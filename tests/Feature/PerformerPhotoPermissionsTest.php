<?php

use App\Models\Follow;
use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Models\PhotoGrant;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerPhotoStore;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Permissões por foto da galeria — Sprint 13.
 *
 * Os eixos: (1) a dona alterna público/privado; (2) foto privada só sai para
 * quem tem grant aprovado — ou a dona —, e o serving 404 (não 403) para os
 * demais; (3) o presenter e o serving CONCORDAM (tile locked ⇔ 404); (4) toda
 * exposição do membro à performer é por FanAlias, nunca por id; (5) o Hard
 * Delete leva os grants nos dois sentidos.
 *
 * Helpers com prefixo `pp` (photo permissions) — as funções do Pest são globais.
 * `chatPerformer`/`chatMember` vêm do Pest.php.
 */
beforeEach(function () {
    Storage::fake(PerformerPhotoStore::DISK);
});

/** JPEG real (o serving re-sniffa o mime e recusa o que não for image/jpeg). */
function ppJpeg(): string
{
    $img = imagecreatetruecolor(40, 30);
    imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 120, 60, 30));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Cria uma linha de foto (com bytes no disco fake), pública ou privada. */
function ppPhoto(PerformerProfile $profile, bool $private = false, int $position = 0): PerformerPhoto
{
    $path = $profile->id.'/'.Str::random(40).'.jpg';
    Storage::disk(PerformerPhotoStore::DISK)->put($path, ppJpeg());

    $photo = $profile->photos()->create(['path' => $path, 'position' => $position]);

    if ($private) {
        // is_private está fora do $fillable — set direto, como o service faz.
        $photo->is_private = true;
        $photo->save();
    }

    return $photo->fresh();
}

/** A galeria como o perfil público a devolve para ESTE espectador (ou guest). */
function ppPublicPhotos(PerformerProfile $profile, ?User $viewer = null): array
{
    $req = $viewer ? test()->actingAs($viewer) : test();

    return $req->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->viewData('page')['props']['photos'];
}

// ─── Toggle público/privado ──────────────────────────────────────────────────

it('deixa a dona marcar a foto como privada e voltar a pública', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer);

    test()->actingAs($performer->user)
        ->patchJson(route('performer.gallery.visibility', $photo->id), ['is_private' => true])
        ->assertOk();

    expect($photo->fresh()->is_private)->toBeTrue();

    test()->actingAs($performer->user)
        ->patchJson(route('performer.gallery.visibility', $photo->id), ['is_private' => false])
        ->assertOk();

    expect($photo->fresh()->is_private)->toBeFalse();
});

it('recusa alterar a visibilidade da foto de outra performer', function () {
    $owner = chatPerformer();
    $photo = ppPhoto($owner, private: false);
    $other = chatPerformer();

    test()->actingAs($other->user)
        ->patchJson(route('performer.gallery.visibility', $photo->id), ['is_private' => true])
        ->assertForbidden();

    expect($photo->fresh()->is_private)->toBeFalse();
});

// ─── Serving: o paywall por foto ─────────────────────────────────────────────

it('serve a foto pública para qualquer um', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer);

    test()->get(route('performer.gallery.image', $photo->id))->assertOk();
    test()->actingAs(chatMember())->get(route('performer.gallery.image', $photo->id))->assertOk();
});

it('404 na foto privada para visitante e para membro sem grant', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);

    // 404, não 403: o serving não confirma a existência de conteúdo privado.
    test()->get(route('performer.gallery.image', $photo->id))->assertNotFound();
    test()->actingAs(chatMember())->get(route('performer.gallery.image', $photo->id))->assertNotFound();
});

it('a dona SEMPRE vê a própria foto privada', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);

    test()->actingAs($performer->user)
        ->get(route('performer.gallery.image', $photo->id))
        ->assertOk();
});

it('o membro com grant aprovado vê a foto privada', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    (new PhotoGrant)->forceFill([
        'performer_photo_id' => $photo->id,
        'user_id' => $member->id,
        'granted_at' => now(),
    ])->save();

    test()->actingAs($member)
        ->get(route('performer.gallery.image', $photo->id))
        ->assertOk();
});

it('o membro com pedido PENDENTE (não aprovado) ainda recebe 404', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    (new PhotoGrant)->forceFill([
        'performer_photo_id' => $photo->id,
        'user_id' => $member->id,
        'granted_at' => null,
    ])->save();

    test()->actingAs($member)
        ->get(route('performer.gallery.image', $photo->id))
        ->assertNotFound();
});

// ─── Presenter e serving concordam ───────────────────────────────────────────

it('presenter: foto privada vem locked e SEM url; pública vem com url', function () {
    $performer = chatPerformer();
    $public = ppPhoto($performer, private: false, position: 0);
    $private = ppPhoto($performer, private: true, position: 1);

    $photos = collect(ppPublicPhotos($performer))->keyBy('id');

    expect($photos[$public->id]['locked'])->toBeFalse()
        ->and($photos[$public->id]['url'])->not->toBeNull()
        ->and($photos[$private->id]['locked'])->toBeTrue()
        ->and($photos[$private->id]['url'])->toBeNull()
        ->and($photos[$private->id]['is_private'])->toBeTrue()
        ->and($photos[$private->id]['access_state'])->toBe('none');
});

it('presenter: membro com grant vê a foto privada com url e não locked', function () {
    $performer = chatPerformer();
    $private = ppPhoto($performer, private: true);
    $member = chatMember();

    (new PhotoGrant)->forceFill([
        'performer_photo_id' => $private->id,
        'user_id' => $member->id,
        'granted_at' => now(),
    ])->save();

    $photos = collect(ppPublicPhotos($performer, $member))->keyBy('id');

    expect($photos[$private->id]['locked'])->toBeFalse()
        ->and($photos[$private->id]['url'])->not->toBeNull()
        ->and($photos[$private->id]['access_state'])->toBe('granted');
});

// ─── Solicitar acesso (lado do membro) ───────────────────────────────────────

it('o membro solicita acesso e cria um pedido pendente, idempotente', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    test()->actingAs($member)
        ->postJson(route('photos.access.request', $photo->id))
        ->assertOk()
        ->assertJson(['access_state' => 'pending']);

    // Reenviar não duplica.
    test()->actingAs($member)
        ->postJson(route('photos.access.request', $photo->id))
        ->assertOk()
        ->assertJson(['access_state' => 'pending']);

    expect(PhotoGrant::where('performer_photo_id', $photo->id)->where('user_id', $member->id)->count())->toBe(1);
});

it('solicitar acesso NÃO cria Follow', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    test()->actingAs($member)->postJson(route('photos.access.request', $photo->id))->assertOk();

    expect(Follow::where('user_id', $member->id)->count())->toBe(0);
});

it('recusa solicitar acesso a uma foto PÚBLICA (404, nada a solicitar)', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: false);
    $member = chatMember();

    test()->actingAs($member)
        ->postJson(route('photos.access.request', $photo->id))
        ->assertNotFound();

    expect(PhotoGrant::count())->toBe(0);
});

// ─── Aprovar / revogar via FanAlias ──────────────────────────────────────────

it('a performer aprova o pedido pelo handle do FanAlias e o membro passa a ver', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    test()->actingAs($member)->postJson(route('photos.access.request', $photo->id))->assertOk();

    $handle = FanAlias::handle($performer->id, $member->id);

    test()->actingAs($performer->user)
        ->postJson(route('performer.gallery.grant', [$photo->id, $handle]))
        ->assertOk();

    expect(PhotoGrant::where('performer_photo_id', $photo->id)->where('user_id', $member->id)->first()->granted_at)
        ->not->toBeNull();

    test()->actingAs($member)->get(route('performer.gallery.image', $photo->id))->assertOk();
});

it('a performer revoga o acesso pelo handle e o membro volta a 404', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    (new PhotoGrant)->forceFill([
        'performer_photo_id' => $photo->id,
        'user_id' => $member->id,
        'granted_at' => now(),
    ])->save();

    $handle = FanAlias::handle($performer->id, $member->id);

    test()->actingAs($performer->user)
        ->deleteJson(route('performer.gallery.revoke', [$photo->id, $handle]))
        ->assertOk();

    expect(PhotoGrant::where('performer_photo_id', $photo->id)->where('user_id', $member->id)->exists())->toBeFalse();
    test()->actingAs($member)->get(route('performer.gallery.image', $photo->id))->assertNotFound();
});

it('recusar um pedido pendente é o mesmo verbo (revogar) e some da fila', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();

    test()->actingAs($member)->postJson(route('photos.access.request', $photo->id))->assertOk();
    $handle = FanAlias::handle($performer->id, $member->id);

    test()->actingAs($performer->user)
        ->deleteJson(route('performer.gallery.revoke', [$photo->id, $handle]))
        ->assertOk();

    expect(PhotoGrant::count())->toBe(0);
});

it('aprovar com um handle que não solicitou nada → 404', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $stranger = chatMember(); // nunca pediu

    $handle = FanAlias::handle($performer->id, $stranger->id);

    test()->actingAs($performer->user)
        ->postJson(route('performer.gallery.grant', [$photo->id, $handle]))
        ->assertNotFound();
});

it('não deixa uma performer conceder acesso à foto de outra (404 uniforme)', function () {
    $owner = chatPerformer();
    $photo = ppPhoto($owner, private: true);
    $member = chatMember();
    test()->actingAs($member)->postJson(route('photos.access.request', $photo->id))->assertOk();

    $other = chatPerformer();
    // O handle é derivado com o profile da OUTRA — não resolve, e a foto nem é
    // dela: 404, nunca "existe mas não é seu".
    $handle = FanAlias::handle($other->id, $member->id);

    test()->actingAs($other->user)
        ->postJson(route('performer.gallery.grant', [$photo->id, $handle]))
        ->assertNotFound();
});

// ─── FanAlias: o member_id nunca vaza ────────────────────────────────────────

it('a fila de solicitações usa FanAlias e nunca o id do membro', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();
    test()->actingAs($member)->postJson(route('photos.access.request', $photo->id))->assertOk();

    $data = test()->actingAs($performer->user)
        ->getJson(route('performer.gallery.requests'))
        ->assertOk()
        ->json('requests');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toHaveKeys(['photo_id', 'photo_url', 'fan', 'member_handle'])
        ->and($data[0])->not->toHaveKey('user_id')
        ->and($data[0]['fan'])->toBe(FanAlias::label($performer->id, $member->id))
        ->and($data[0]['member_handle'])->toBe(FanAlias::handle($performer->id, $member->id));

    // E o dashboard entrega a mesma fila pseudonimizada.
    test()->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('photoAccessRequests.0.member_handle', FanAlias::handle($performer->id, $member->id))
            ->missing('photoAccessRequests.0.user_id'));
});

it('o model PhotoGrant esconde o user_id na serialização', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();
    $grant = (new PhotoGrant)->forceFill([
        'performer_photo_id' => $photo->id,
        'user_id' => $member->id,
        'granted_at' => now(),
    ]);
    $grant->save();

    expect($grant->fresh()->toArray())->not->toHaveKey('user_id');
});

// ─── Hard Delete nos dois sentidos ───────────────────────────────────────────

it('o Hard Delete do MEMBRO apaga os grants dele (pendentes e aprovados)', function () {
    $performer = chatPerformer();
    $photoA = ppPhoto($performer, private: true, position: 0);
    $photoB = ppPhoto($performer, private: true, position: 1);
    $member = chatMember();

    // Um aprovado, um pendente.
    (new PhotoGrant)->forceFill(['performer_photo_id' => $photoA->id, 'user_id' => $member->id, 'granted_at' => now()])->save();
    (new PhotoGrant)->forceFill(['performer_photo_id' => $photoB->id, 'user_id' => $member->id, 'granted_at' => null])->save();

    app(DeletionService::class)->executeDeletion($member);

    expect(PhotoGrant::where('user_id', $member->id)->count())->toBe(0);
    // As fotos da performer continuam de pé.
    expect(PerformerPhoto::whereIn('id', [$photoA->id, $photoB->id])->count())->toBe(2);
});

it('o Hard Delete da PERFORMER apaga as fotos E os grants apontados para elas', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();
    (new PhotoGrant)->forceFill(['performer_photo_id' => $photo->id, 'user_id' => $member->id, 'granted_at' => now()])->save();

    app(DeletionService::class)->executeDeletion($performer->user);

    expect(PerformerPhoto::where('id', $photo->id)->exists())->toBeFalse()
        ->and(PhotoGrant::where('performer_photo_id', $photo->id)->exists())->toBeFalse();
});

// ─── Gates ───────────────────────────────────────────────────────────────────

it('exige login para solicitar acesso', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);

    // Rota WEB: o `auth` redireciona o visitante para o login (302), não 401.
    test()->postJson(route('photos.access.request', $photo->id))->assertRedirect(route('login'));
});

it('recusa o membro nos endpoints de gestão de permissão — role:performer', function () {
    $performer = chatPerformer();
    $photo = ppPhoto($performer, private: true);
    $member = chatMember();
    $handle = FanAlias::handle($performer->id, $member->id);

    test()->actingAs($member)->patchJson(route('performer.gallery.visibility', $photo->id), ['is_private' => true])->assertForbidden();
    test()->actingAs($member)->getJson(route('performer.gallery.requests'))->assertForbidden();
    test()->actingAs($member)->postJson(route('performer.gallery.grant', [$photo->id, $handle]))->assertForbidden();
    test()->actingAs($member)->deleteJson(route('performer.gallery.revoke', [$photo->id, $handle]))->assertForbidden();
});
