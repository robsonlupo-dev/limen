<?php

use App\Models\ChatAccess;
use App\Models\MemberPhoto;
use App\Models\MemberPhotoAccess;
use App\Models\User;
use App\Services\MemberPhotoStore;
use App\Support\FanAlias;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints da foto efêmera do membro — envio, compartilhamento, revogação e as
 * duas portas de leitura. Ver docs/SECURITY_ISSUES.md § 1.1 a § 1.11.
 *
 * O eixo destes testes não é "a rota responde 200". É que **a resposta não
 * carrega nada que a tela não possa mostrar**: nem o id do membro, nem relógio,
 * nem o TTL escolhido — e que o gate de chat ativo é do servidor, não do botão.
 *
 * Helpers com prefixo `ep` (endpoint) para o arquivo rodar isolado ou na suíte:
 * as funções do Pest são globais e colidiriam com as de MemberEphemeralPhotoTest.
 */
beforeEach(function () {
    Storage::fake(MemberPhotoStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function epJpeg(int $width = 60, int $height = 40): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 180, 60, 90));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** JPEG com APP1/EXIF carregando GPS — a foto de celular do § 1.4. */
function epJpegWithGps(): string
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

    return "\xFF\xD8".$app1.substr(epJpeg(), 2);
}

function epUpload(?string $bytes = null, string $name = 'foto.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_ep_');
    file_put_contents($path, $bytes ?? epJpeg());

    return new UploadedFile($path, $name, $mime, null, true);
}

/** Membro com chat ATIVO com a performer — o pré-requisito do compartilhamento. */
function epPairWithActiveChat(): array
{
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 100);
    grantChatAccess($member, $conversation);

    return [$member->fresh(), $performer, $conversation];
}

/** Sobe uma foto pelo endpoint e devolve o model. */
function epStorePhoto(User $member, int $ttl = 24): MemberPhoto
{
    test()->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => $ttl])
        ->assertCreated();

    return MemberPhoto::where('user_id', $member->id)->latest('id')->firstOrFail();
}

// ─── Upload ──────────────────────────────────────────────────────────────────

it('guarda a foto cifrada e devolve só id e faixa', function () {
    $member = chatMember();

    $response = $this->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 24])
        ->assertCreated();

    $photo = MemberPhoto::where('user_id', $member->id)->sole();

    // A resposta é o apresentador do model: nem `expires_at`, nem o TTL de
    // volta, nem o caminho no disco (§ 1.2).
    expect(array_keys($response->json('photo')))
        ->toBe(['id', 'expires_slot', 'shared_with'])
        ->and($response->json('photo.expires_slot'))->toBeString()
        ->and($response->json('photo.shared_with'))->toBe(0);

    // E o que está no disco não é a imagem.
    $onDisk = Storage::disk(MemberPhotoStore::DISK)->get($photo->path_encrypted);
    expect(substr($onDisk, 0, 2))->not->toBe("\xFF\xD8");
});

it('remove EXIF/GPS da foto enviada pelo endpoint', function () {
    $member = chatMember();
    $upload = epUpload(epJpegWithGps());

    // A fixture PRECISA carregar GPS, senão o teste não prova nada.
    expect(@exif_read_data($upload->getRealPath()))->toBeArray()->toHaveKeys(['GPSLatitude', 'GPSLongitude']);

    $this->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => $upload, 'ttl_horas' => 24])
        ->assertCreated();

    $photo = MemberPhoto::where('user_id', $member->id)->sole();

    // Lê pelo próprio endpoint de serving: é o caminho que a performer usa.
    $bytes = $this->actingAs($member)
        ->get(route('member.photos.image', $photo->id))
        ->assertOk()
        ->getContent();

    $tmp = tempnam(sys_get_temp_dir(), 'limen_ep_check_');
    file_put_contents($tmp, $bytes);
    $after = @exif_read_data($tmp);
    @unlink($tmp);

    $gps = array_filter(array_keys($after ?: []), fn (string $t) => str_starts_with($t, 'GPS'));
    expect($gps)->toBeEmpty();
});

it('recusa a sexta foto ativa pelo endpoint', function () {
    $member = chatMember();

    foreach (range(1, MemberPhoto::ACTIVE_LIMIT) as $i) {
        epStorePhoto($member);
    }

    $this->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 24])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'active_limit_reached');

    expect(MemberPhoto::where('user_id', $member->id)->count())->toBe(MemberPhoto::ACTIVE_LIMIT);
});

it('recusa TTL fora do menu e arquivo que não é imagem', function () {
    $member = chatMember();

    $this->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 720])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ttl_horas');

    // PDF renomeado para .jpg: o `mimes` resolve pelo CONTEÚDO, não pelo nome.
    $this->actingAs($member)
        ->postJson(route('member.photos.store'), [
            'foto' => epUpload('%PDF-1.4 not an image', 'foto.jpg'),
            'ttl_horas' => 24,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('foto');

    expect(MemberPhoto::count())->toBe(0)
        ->and(Storage::disk(MemberPhotoStore::DISK)->allFiles())->toBeEmpty();
});

it('limita o upload a 10 por minuto', function () {
    $member = chatMember();

    // O 11º bate no throttle da rota. As 5 primeiras criam foto e as demais
    // caem no cap — o que importa aqui é o 429, não o corpo das anteriores.
    foreach (range(1, 10) as $i) {
        $status = $this->actingAs($member)
            ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 24])
            ->status();

        // 201 nas cinco primeiras, 422 (cap) nas seguintes — nunca 429 ainda.
        expect($status)->toBeIn([201, 422]);
    }

    $this->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 24])
        ->assertStatus(429);
});

// ─── Compartilhamento: o gate de chat ativo ──────────────────────────────────

it('compartilha com a performer quando o chat está ativo', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk()
        ->assertJsonPath('photo.shared_with', 1);

    expect(MemberPhotoAccess::where('member_photo_id', $photo->id)
        ->where('performer_profile_id', $performer->id)
        ->exists())->toBeTrue();
});

it('recusa o compartilhamento sem chat ativo com aquela performer', function () {
    $member = chatMember();
    $performer = chatPerformer();
    $photo = epStorePhoto($member);

    // Nunca houve conversa: sem isso a foto seria uma superfície
    // membro→performer paralela ao Interesse Controlado, e qualquer membro
    // empurraria o próprio rosto para qualquer perfil do catálogo.
    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_active_chat');

    expect(MemberPhotoAccess::count())->toBe(0);
});

it('recusa o compartilhamento quando a janela do chat venceu', function () {
    [$member, $performer, $conversation] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    // Janela vencida (dentro da carência): quem não pode nem responder no chat
    // não recebe rosto novo.
    ChatAccess::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->update(['expires_at' => now()->subDay(), 'grace_ends_at' => now()->addDays(10)]);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_active_chat');
});

it('não deixa um membro compartilhar a foto de outro', function () {
    [$owner, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($owner);

    [$intruder] = epPairWithActiveChat();

    $this->actingAs($intruder)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertStatus(403)
        ->assertJsonPath('reason', 'forbidden');

    expect(MemberPhotoAccess::count())->toBe(0);
});

// ─── Revogação ───────────────────────────────────────────────────────────────

it('revoga a foto: bytes, acessos e linha', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $this->actingAs($member)
        ->deleteJson(route('member.photos.destroy', $photo->id))
        ->assertOk()
        ->assertJsonPath('status', 'revoked');

    expect(Storage::disk(MemberPhotoStore::DISK)->exists($photo->path_encrypted))->toBeFalse()
        ->and(MemberPhoto::withTrashed()->find($photo->id))->toBeNull()
        ->and(MemberPhotoAccess::where('member_photo_id', $photo->id)->count())->toBe(0);
});

it('não deixa um membro revogar a foto de outro', function () {
    $owner = chatMember();
    $intruder = chatMember();
    $photo = epStorePhoto($owner);

    $this->actingAs($intruder)
        ->deleteJson(route('member.photos.destroy', $photo->id))
        ->assertStatus(403);

    expect(MemberPhoto::find($photo->id))->not->toBeNull()
        ->and(Storage::disk(MemberPhotoStore::DISK)->exists($photo->path_encrypted))->toBeTrue();
});

// ─── Serving ao membro ───────────────────────────────────────────────────────

it('serve a foto ao titular com os cabeçalhos de conteúdo controlado', function () {
    $member = chatMember();
    $photo = epStorePhoto($member);

    $response = $this->actingAs($member)
        ->get(route('member.photos.image', $photo->id))
        ->assertOk();

    // Content-Type por re-sniff no SERVIDOR, nunca do upload; nosniff porque é
    // a única resposta com bytes controlados pelo usuário; inline porque a foto
    // é para ser vista; no-store porque cache é retenção e sobreviveria ao TTL.
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // E o nome original NÃO viaja no cabeçalho — seria vazar por header o que o
    // $hidden do model tira do JSON.
    expect($response->headers->get('Content-Disposition'))->not->toContain('selfie');

    expect(substr($response->getContent(), 0, 2))->toBe("\xFF\xD8");
});

it('devolve 404 na foto de outro membro e na foto vencida — a mesma resposta', function () {
    $owner = chatMember();
    $intruder = chatMember();
    $photo = epStorePhoto($owner);

    // Distinguir os dois casos diria a um terceiro que aquele id existe e está
    // vivo. São a mesma resposta de propósito.
    $this->actingAs($intruder)
        ->get(route('member.photos.image', $photo->id))
        ->assertNotFound();

    $photo->forceFill(['expires_at' => now()->subHour()])->save();

    $this->actingAs($owner)
        ->get(route('member.photos.image', $photo->id))
        ->assertNotFound();
});

// ─── Serving à performer ─────────────────────────────────────────────────────

it('serve a foto à performer destinatária e marca a visualização', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)->sole();

    $response = $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($access->refresh()->viewed_at)->not->toBeNull();
});

it('devolve 403 quando o acesso é de outra performer', function () {
    [$member, $performer] = epPairWithActiveChat();
    $outra = chatPerformer();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)->sole();

    // O IDOR concreto: `/performer/fotos-recebidas/{access}/imagem` com o id
    // incrementado. Sem o guard, ela recebe os bytes E carimba viewed_at na
    // conta errada.
    $this->actingAs($outra->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertStatus(403);

    expect($access->refresh()->viewed_at)->toBeNull();
});

it('devolve 404 à destinatária quando o prazo venceu', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)->sole();
    $photo->forceFill(['expires_at' => now()->subHour()])->save();

    $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertNotFound();
});

// ─── Privacidade das props ───────────────────────────────────────────────────

it('mostra à performer o FanAlias e a faixa, nunca o id nem o relógio', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member, 168);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $response = $this->actingAs($performer->user)->get(route('performer.dashboard'))->assertOk();

    $received = $response->viewData('page')['props']['receivedPhotos'];

    expect($received)->toHaveCount(1)
        ->and(array_keys($received[0]))->toBe(['access_id', 'fan', 'expires_slot'])
        ->and($received[0]['fan'])->toBe(FanAlias::label($performer->id, $member->id));

    // O payload inteiro não pode conter o id do membro nem timestamp de
    // expiração — é a checagem que pega o campo somado sem querer.
    $encoded = json_encode($received);

    expect($encoded)->not->toContain('member_id')
        ->and($encoded)->not->toContain('user_id')
        ->and($encoded)->not->toContain('expires_at')
        ->and($encoded)->not->toContain('viewed_at')
        ->and($encoded)->not->toContain('ttl');
});

it('devolve a faixa correta, e não um relógio', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-29 15:00:00', 'America/Sao_Paulo'));

    [$member, $performer] = epPairWithActiveChat();

    // 24h e 7 dias caem em faixas diferentes; nenhuma das duas é um horário.
    $curta = epStorePhoto($member, 24);
    $longa = epStorePhoto($member, 168);

    foreach ([$curta, $longa] as $photo) {
        $this->actingAs($member)
            ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
            ->assertOk();
    }

    $received = $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->viewData('page')['props']['receivedPhotos'];

    $slots = array_column($received, 'expires_slot');

    expect($slots)->toContain(MemberPhotoAccess::SLOT_SOME_DAYS)
        ->and($slots)->toContain(MemberPhotoAccess::SLOT_THIS_WEEK);

    Carbon::setTestNow();
});

it('oferece o compartilhamento no chat só com janela ativa e foto ativa', function () {
    [$member, $performer, $conversation] = epPairWithActiveChat();

    // Sem foto: o botão não aparece, mesmo com chat ativo.
    $props = $this->actingAs($member)->get(route('chat.show', $conversation->id))
        ->viewData('page')['props'];

    expect($props['photoSharing']['can_share'])->toBeTrue()
        ->and($props['photoSharing']['photos'])->toBeEmpty();

    epStorePhoto($member);

    $props = $this->actingAs($member)->get(route('chat.show', $conversation->id))
        ->viewData('page')['props'];

    expect($props['photoSharing']['photos'])->toHaveCount(1)
        ->and(array_keys($props['photoSharing']['photos'][0]))->toBe(['id', 'expires_slot', 'shared_with']);

    // A tela da PERFORMER nunca insinua nada sobre as fotos do outro lado.
    $props = $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))
        ->viewData('page')['props'];

    expect($props['photoSharing']['can_share'])->toBeFalse()
        ->and($props['photoSharing']['photos'])->toBeEmpty();
});

// ─── Gates das rotas ─────────────────────────────────────────────────────────

it('exige autenticação em todas as rotas de foto', function () {
    // Porta WEB: visitante é REDIRECIONADO para o login, não recebe 401 — o
    // 401 é o comportamento da porta API (Sanctum). Ver a convenção das duas
    // portas de auth no CLAUDE.md.
    $this->post(route('member.photos.store'), [])->assertRedirect(route('login'));
    $this->post(route('member.photos.share', 1), [])->assertRedirect(route('login'));
    $this->delete(route('member.photos.destroy', 1))->assertRedirect(route('login'));
    $this->get(route('member.photos.image', 1))->assertRedirect(route('login'));
    $this->get(route('performer.photos.image', 1))->assertRedirect(route('login'));
});

it('barra a performer nas rotas de membro e o membro na rota da performer', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)->sole();

    $this->actingAs($performer->user)
        ->postJson(route('member.photos.store'), ['foto' => epUpload(), 'ttl_horas' => 24])
        ->assertForbidden();

    // Com um id de acesso QUE EXISTE: o `SubstituteBindings` do grupo `web`
    // roda ANTES do middleware de rota, então um id inexistente daria 404 e o
    // teste passaria sem nunca exercitar o `role:performer`.
    $this->actingAs($member)
        ->get(route('performer.photos.image', $access->id))
        ->assertForbidden();
});

it('manda ao desafio de 2FA a performer que ainda não provou o fator', function () {
    [$member, $performer] = epPairWithActiveChat();
    $photo = epStorePhoto($member);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)->sole();

    // A rota de leitura é nova e entra no gate como todas as outras da área da
    // performer — a lição do `documents.accepted`: gate que fecha uma porta só
    // não é gate.
    $performer->user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Id existente pelo mesmo motivo do teste acima: com um inexistente o 404
    // do binding chegaria primeiro e o gate não seria exercitado.
    $this->actingAs($performer->user->fresh())
        ->get(route('performer.photos.image', $access->id))
        ->assertRedirect(route('performer.2fa.challenge'));
});
