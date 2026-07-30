<?php

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\MemberPhoto;
use App\Models\MemberPhotoAccess;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\InterestService;
use App\Services\MemberPhotoService;
use App\Services\MemberPhotoStore;
use App\Services\TokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Os 4 bloqueadores de go-live da Foto Efêmera do Membro (Sprint 9B).
 *
 * 1. denúncia — a foto recebida é denunciável pela performer, pela porta que já
 *    existe, com o `access_id` como handle;
 * 2. quarentena — denúncia em aberto congela o revoke do titular E o GC;
 * 3. audit — share/view/revoke deixam trilha, e é a única que sobrevive ao TTL;
 * 4. `canMemberSendTo` — uma dona só para "o membro pode falar com esta
 *    performer", lida pelo chat e pela foto.
 *
 * O eixo aqui não é "a rota responde". É que a prova sobrevive ao botão de
 * apagar, que a trilha não vira uma segunda cópia do mapa de quem-mostrou-para-
 * quem, e que fechar a porta em UM lugar fecha os dois caminhos.
 *
 * Helpers com prefixo `mpq` (member photo quarantine) — as funções do Pest são
 * GLOBAIS e a suíte inteira compartilha o mesmo namespace: `mod*` colidiria com
 * StoryModerationTest, e `ep*`/`ephemeral*` já são de MemberPhotoEndpointsTest e
 * MemberEphemeralPhotoTest. A colisão não aparece rodando o arquivo sozinho —
 * ela derruba a suíte inteira com "cannot redeclare".
 */
beforeEach(function () {
    Storage::fake(MemberPhotoStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function mpqJpeg(): string
{
    $img = imagecreatetruecolor(48, 32);
    imagefilledrectangle($img, 0, 0, 47, 31, imagecolorallocate($img, 20, 140, 90));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function mpqUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_mpq_');
    file_put_contents($path, mpqJpeg());

    return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
}

/**
 * Cenário completo: membro com chat ativo, foto enviada e compartilhada.
 *
 * Devolve [$member, $performerProfile, $photo, $access] — tudo pelas portas de
 * produção (endpoint de upload e de share), para o teste não montar um estado
 * que o produto não consegue produzir.
 *
 * @return array{0:User,1:PerformerProfile,2:MemberPhoto,3:MemberPhotoAccess}
 */
function mpqSharedPhoto(): array
{
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 100);
    grantChatAccess($member, $conversation);
    $member = $member->fresh();

    test()->actingAs($member)
        ->postJson(route('member.photos.store'), ['foto' => mpqUpload(), 'ttl_horas' => 24])
        ->assertCreated();

    $photo = MemberPhoto::where('user_id', $member->id)->sole();

    test()->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();

    $access = MemberPhotoAccess::where('member_photo_id', $photo->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    return [$member, $performer, $photo->fresh(), $access];
}

/**
 * Abre chat ativo entre um membro JÁ existente e outra performer.
 *
 * `chatUnlockedPair()` cria um membro novo; aqui o membro é o mesmo de
 * propósito — é o cenário em que a MESMA foto vai para duas performers, que é
 * onde mora o risco de correlação entre perfis.
 */
function mpqOpenChat(User $member, PerformerProfile $performer): Conversation
{
    app(TokenService::class)->credit($member, 100, 'purchase');
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);

    $interest = app(InterestService::class)->send($performer, $member);
    app(InterestService::class)->unlock($member->fresh(), $interest);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    grantChatAccess($member->fresh(), $conversation);

    return $conversation;
}

/** A denúncia como a performer a envia: handle = access_id. */
function mpqReport(User $performer, int $accessId, string $reason = 'non_consensual')
{
    return test()->actingAs($performer)->postJson(route('report.store'), [
        'reportable_type' => 'member_photo',
        'reportable_id' => $accessId,
        'reason' => $reason,
    ]);
}

// ─── 1. Denúncia ─────────────────────────────────────────────────────────────

it('deixa a performer denunciar a foto recebida, pelo access_id', function () {
    [$member, $performer, $photo, $access] = mpqSharedPhoto();

    mpqReport($performer->user, $access->id)->assertOk();

    $report = Report::sole();

    // A denúncia aponta para a FOTO, não para o acesso: o acesso é o handle de
    // entrada, o alvo moderado é o conteúdo.
    expect($report->reportable_type)->toBe(MemberPhoto::class)
        ->and($report->reportable_id)->toBe($photo->id)
        ->and($report->reporter_id)->toBe($performer->user->id)
        ->and($report->status)->toBe('pending');
});

it('resolve o handle no espaço de ACESSOS, nunca no de fotos', function () {
    [, $performer, $photo, $access] = mpqSharedPhoto();

    // Uma foto de outro membro que NUNCA foi compartilhada: o id dela é um id de
    // foto perfeitamente válido e não é o id de acesso nenhum. É o par que
    // separa os dois espaços de numeração.
    $outsider = chatMember();

    do {
        $unshared = new MemberPhoto(['expires_at' => now()->addDay(), 'size_bytes' => 10]);
        $unshared->user_id = $outsider->id;
        $unshared->path_encrypted = 'nunca-compartilhada.enc';
        $unshared->save();
    } while (MemberPhotoAccess::whereKey($unshared->id)->exists());

    // O handle É o acesso: resolve para a foto por trás dele.
    expect(Report::resolveFromHandle('member_photo', $access->id, $performer->user)?->id)->toBe($photo->id);

    // E o id de FOTO não é handle de nada. Se alguém trocar o resolver por um
    // `MemberPhoto::find($handle)` cru, esta linha devolve a foto do outro
    // membro — e a denúncia passaria a cair sobre quem não tem nada a ver com
    // ela, com a recusa por visibilidade escondendo o bug para sempre.
    expect(Report::resolveFromHandle('member_photo', $unshared->id, $performer->user))->toBeNull();

    // Pela ponta: o endpoint não alcança foto nenhuma por id de foto.
    mpqReport($performer->user, $unshared->id)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    mpqReport($performer->user, $access->id)->assertOk();

    expect(Report::sole()->reportable_id)->toBe($photo->id);
});

it('não deixa outra performer denunciar a foto que não recebeu', function () {
    [, , , $access] = mpqSharedPhoto();

    $other = chatPerformer();

    // Mesma resposta de "não existe": distinguir diria que aquele handle é de
    // uma foto viva de outra pessoa.
    mpqReport($other->user, $access->id)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    expect(Report::count())->toBe(0);
});

it('não deixa uma performer denunciar pelo handle de acesso de OUTRA', function () {
    // As duas receberam a MESMA foto. É o caso que o `visibleTo()` sozinho não
    // fecha: ele procuraria o acesso de B àquela foto, encontraria, e diria sim
    // para um handle que é de A. O 200 provaria a B que aquele mesmo rosto foi
    // mostrado a mais alguém — a correlação entre perfis que o FanAlias existe
    // para impedir. Achado da revisão de segurança de 30/07.
    [$member, $performerA, $photo, $accessA] = mpqSharedPhoto();

    $performerB = chatPerformer();

    // B precisa de chat ativo com o MESMO membro para receber a mesma foto.
    mpqOpenChat($member, $performerB);

    $this->actingAs($member->fresh())
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performerB->id])
        ->assertOk();

    $accessB = MemberPhotoAccess::where('member_photo_id', $photo->id)
        ->where('performer_profile_id', $performerB->id)
        ->sole();

    // B com o handle DELA: passa.
    mpqReport($performerB->user, $accessB->id, 'spam')->assertOk();

    // B com o handle de A: mesma resposta de "não existe".
    mpqReport($performerB->user, $accessA->id, 'coercion')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    expect(Report::count())->toBe(1);
});

it('não deixa um membro denunciar foto pelo handle de acesso', function () {
    [, , , $access] = mpqSharedPhoto();

    mpqReport(chatMember(), $access->id)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    expect(Report::count())->toBe(0);
});

it('não deixa o titular denunciar a própria foto', function () {
    [$member, , , $access] = mpqSharedPhoto();

    // Cai em "não encontrado" antes mesmo da checagem de autodenúncia: o membro
    // não tem perfil de performer, então não vê a foto por esta porta.
    mpqReport($member, $access->id)->assertStatus(422);

    expect(Report::count())->toBe(0);
});

it('deixa de ser denunciável quando o prazo vence', function () {
    [, $performer, $photo, $access] = mpqSharedPhoto();

    Carbon::setTestNow(now()->addHours(25));

    // Consequência assumida e idêntica à do story: a janela de denúncia é a de
    // exibição. Registrada em teste para não ser redescoberta como bug.
    mpqReport($performer->user, $access->id)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    Carbon::setTestNow();
});

// ─── 2. Quarentena ───────────────────────────────────────────────────────────

it('impede o titular de revogar foto com denúncia em aberto', function () {
    [$member, $performer, $photo, $access] = mpqSharedPhoto();

    mpqReport($performer->user, $access->id)->assertOk();

    $this->actingAs($member)
        ->deleteJson(route('member.photos.destroy', $photo->id))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'under_review');

    // O que importa não é o status: é que os bytes continuam lá para a revisão.
    expect(MemberPhoto::whereKey($photo->id)->exists())->toBeTrue()
        ->and(Storage::disk(MemberPhotoStore::DISK)->exists($photo->path_encrypted))->toBeTrue();
});

it('deixa o GC pular a foto denunciada e recolher as outras', function () {
    [, $performer, $reported, $reportedAccess] = mpqSharedPhoto();
    [, , $ordinary] = mpqSharedPhoto();

    mpqReport($performer->user, $reportedAccess->id)->assertOk();

    Carbon::setTestNow(now()->addHours(25));

    $counts = app(MemberPhotoService::class)->purgeExpired();

    expect($counts['quarantined'])->toBe(1)
        ->and($counts['deleted'])->toBe(1)
        // A congelada não entra em `stale`: senão o alarme do § 1.3, que existe
        // para detectar GC quebrado, passaria a disparar por funcionamento
        // correto a cada rodada.
        ->and($counts['stale'])->toBe(0);

    expect(MemberPhoto::whereKey($reported->id)->exists())->toBeTrue()
        ->and(Storage::disk(MemberPhotoStore::DISK)->exists($reported->path_encrypted))->toBeTrue()
        ->and(MemberPhoto::whereKey($ordinary->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});

it('congela os bytes sem estender a leitura de ninguém', function () {
    [$member, $performer, $photo, $access] = mpqSharedPhoto();

    mpqReport($performer->user, $access->id)->assertOk();

    Carbon::setTestNow(now()->addHours(25));

    // A quarentena é para a REVISÃO, não para a denunciante: se denunciar
    // esticasse o próprio acesso, a denúncia viraria a forma de contornar o TTL.
    $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertNotFound();

    $this->actingAs($member)
        ->get(route('member.photos.image', $photo->id))
        ->assertNotFound();

    Carbon::setTestNow();
});

it('devolve a foto ao GC quando a denúncia é concluída', function () {
    [, $performer, $photo, $access] = mpqSharedPhoto();

    mpqReport($performer->user, $access->id)->assertOk();

    // `forceFill`: `status` está fora do `$fillable` do Report de propósito (é
    // autoridade do servidor), então um `update(['status' => ...])` passaria
    // silenciosamente sem gravar nada — e o teste "passaria" pelo motivo errado.
    Report::sole()->forceFill(['status' => 'dismissed'])->save();

    Carbon::setTestNow(now()->addHours(25));

    $counts = app(MemberPhotoService::class)->purgeExpired();

    expect($counts['quarantined'])->toBe(0)
        ->and($counts['deleted'])->toBe(1)
        ->and(MemberPhoto::whereKey($photo->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});

// ─── 3. Audit ────────────────────────────────────────────────────────────────

it('registra share, view e revoke no audit — só com o id', function () {
    [$member, $performer, $photo, $access] = mpqSharedPhoto();

    $shared = AuditLog::where('action', 'member_photo.shared')->sole();

    expect($shared->user_id)->toBe($member->id)
        ->and($shared->subject_type)->toBe(MemberPhoto::class)
        ->and($shared->subject_id)->toBe($photo->id)
        ->and($shared->metadata)->toBe(['member_photo_id' => $photo->id]);

    $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertOk();

    $viewed = AuditLog::where('action', 'member_photo.viewed')->sole();
    expect($viewed->user_id)->toBe($performer->user->id)
        // O sujeito é o ACESSO, não a foto — ver o teste do JOIN abaixo.
        ->and($viewed->subject_type)->toBe(MemberPhotoAccess::class)
        ->and($viewed->subject_id)->toBe($access->id)
        ->and($viewed->metadata)->toBe(['member_photo_access_id' => $access->id]);

    $this->actingAs($member)
        ->deleteJson(route('member.photos.destroy', $photo->id))
        ->assertOk();

    $revoked = AuditLog::where('action', 'member_photo.revoked')->sole();
    expect($revoked->user_id)->toBe($member->id)
        ->and($revoked->metadata)->toBe(['member_photo_id' => $photo->id]);
});

it('não guarda no audit o caminho nem o nome do arquivo', function () {
    [, $performer, , $access] = mpqSharedPhoto();

    $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertOk();

    $rows = AuditLog::where('action', 'like', 'member_photo.%')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(array_keys($row->metadata))->toHaveCount(1)
            ->and(json_encode($row->metadata))
            ->not->toContain('.enc')
            ->not->toContain('performer_profile_id')
            ->not->toContain('foto.jpg');
    }
});

it('não deixa o audit reconstruir o par membro↔performer por JOIN', function () {
    // ── O achado da revisão de segurança de 30/07, virado teste ─────────────
    // `Audit::log()` grava o ATOR em `user_id` e o alvo em `subject_id`. O ator
    // do `.shared` é o MEMBRO e o do `.viewed` é a PERFORMER — então bastava os
    // dois apontarem para a mesma foto para o par sair de um
    // `JOIN ... ON subject_id`. E `audit_logs` é a tabela que o DeletionService
    // preserva INTACTA: seria uma cópia permanente do mapa do § 1.8,
    // sobrevivendo ao TTL, ao GC, ao revoke e ao encerramento da conta.
    //
    // Omitir o `performer_profile_id` do metadata não fecha isso — as colunas do
    // próprio audit_logs já entregavam. A versão anterior deste teste olhava só
    // o metadata e por isso passava com o furo aberto.
    [$member, $performer, , $access] = mpqSharedPhoto();

    $this->actingAs($performer->user)
        ->get(route('performer.photos.image', $access->id))
        ->assertOk();

    $subjectsOf = fn (string $action) => AuditLog::where('action', $action)
        ->get()
        ->map(fn (AuditLog $row) => $row->subject_type.'#'.$row->subject_id)
        ->all();

    $memberSide = $subjectsOf('member_photo.shared');
    $performerSide = $subjectsOf('member_photo.viewed');

    expect($memberSide)->not->toBeEmpty()
        ->and($performerSide)->not->toBeEmpty()
        // Nenhum sujeito em comum: o lado do membro aponta para a FOTO, o da
        // performer para o ACESSO — que morre em hard delete junto com ela.
        ->and(array_intersect($memberSide, $performerSide))->toBeEmpty();

    // E os atores são mesmo lados opostos, senão o teste acima não prova nada.
    expect(AuditLog::where('action', 'member_photo.shared')->value('user_id'))->toBe($member->id)
        ->and(AuditLog::where('action', 'member_photo.viewed')->value('user_id'))->toBe($performer->user->id);
});

it('audita a primeira abertura e não cada recarga da imagem', function () {
    [, $performer, , $access] = mpqSharedPhoto();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($performer->user)
            ->get(route('performer.photos.image', $access->id))
            ->assertOk();
    }

    // A tela é uma <img>: recarregar a página repete o GET. Sem a dedup, a
    // trilha — que é a única prova que sobrevive ao TTL — vira ruído.
    expect(AuditLog::where('action', 'member_photo.viewed')->count())->toBe(1);
});

// ─── 4. canMemberSendTo como fonte única ─────────────────────────────────────

it('com acesso pago, chat e foto passam pelas duas portas', function () {
    // Controle positivo do teste seguinte: sem ele, um erro que fechasse tudo
    // faria o teste de recusa passar por acidente.
    [$member, $performer, $photo] = mpqSharedPhoto();

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi'])
        ->assertCreated();

    // O share já passou dentro de mpqSharedPhoto(); repetir aqui prova que a
    // porta continua aberta depois da mensagem.
    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();
});

it('fecha chat e foto de uma vez quando canMemberSendTo recusa', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 100);
    grantChatAccess($member, $conversation);
    $member = $member->fresh();

    // ── O mock entra ANTES de qualquer request, e isso é obrigatório ────────
    // O Laravel memoiza a instância do controller no objeto Route, e a
    // RouteCollection sobrevive entre os requests do mesmo teste: um request
    // feito antes do mock congela o ChatService com o ChatAccessService REAL
    // dentro, e a substituição no container deixa de ter efeito. Não é
    // comportamento de produção (lá cada request tem seu container), mas quem
    // reordenar este teste vai vê-lo "passar" com a regra desligada.
    $this->partialMock(
        ChatAccessService::class,
        fn ($mock) => $mock->shouldReceive('canMemberSendTo')->andReturn(false),
    );

    // Uma única recusa, no único lugar onde a pergunta mora. É o teste do 4º
    // bloqueador — antes desta extração, `shareWith()` tinha uma CÓPIA da regra
    // do chat, e foi assim que o `status === 'active'` passou batido na primeira
    // versão da feature.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'de novo'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'access_required');

    // A foto usa o service direto (o upload não passa pelo ChatAccessService),
    // então aqui o share é a única porta que consulta a regra mockada.
    $photo = new MemberPhoto(['expires_at' => now()->addDay(), 'size_bytes' => 10]);
    $photo->user_id = $member->id;
    $photo->path_encrypted = 'nao-usado.enc';
    $photo->save();

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_active_chat');

    expect(MemberPhotoAccess::count())->toBe(0);
});

it('fecha as duas portas quando a conversa é arquivada', function () {
    [$member, $performer, $photo] = mpqSharedPhoto();

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();
    $conversation->update(['status' => 'archived']);

    // A porta que a primeira versão da foto efêmera não tinha. Hoje ela vem de
    // graça: é `canMemberSendTo` quem responde, para os dois caminhos.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'oi'])
        ->assertStatus(422);

    $this->actingAs($member)
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_active_chat');
});

it('mantém o assinante de Círculo compartilhando sem linha em chat_access', function () {
    // A leitura literal de `chat_access` recusaria justamente quem paga mais —
    // e a extração não pode ter reintroduzido isso.
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);

    expect(app(ChatAccessService::class)->accessFor($conversation, $member))->toBeNull();

    Subscription::factory()->circle('explorador')->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    $this->actingAs($member->fresh())
        ->postJson(route('member.photos.store'), ['foto' => mpqUpload(), 'ttl_horas' => 24])
        ->assertCreated();

    $photo = MemberPhoto::where('user_id', $member->id)->sole();

    $this->actingAs($member->fresh())
        ->postJson(route('member.photos.share', $photo->id), ['performer_profile_id' => $performer->id])
        ->assertOk();
});
