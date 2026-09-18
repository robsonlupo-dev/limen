<?php

use App\Jobs\SendMemberPhotoRejectedEmail;
use App\Models\MemberGalleryPhoto;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\MemberGalleryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fila de moderação das fotos de galeria de membro
 * (feat/member-gallery-and-profile, Estágio 1). Mesma porta `/moderacao/*`
 * (moderator OU admin) da intro de voz. Trava: só moderador/admin acessa; aprovar
 * publica; recusar purga os bytes e avisa o membro; a foto pending só vira
 * visível DEPOIS do approved.
 */
beforeEach(function () {
    Storage::fake('local');
});

function mgmMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ], $overrides));
}

function mgmPendingPhoto(User $member): MemberGalleryPhoto
{
    test()->actingAs($member)
        ->post(route('consumer.gallery.store'), ['file' => UploadedFile::fake()->image('me.jpg', 800, 900)]);

    return MemberGalleryPhoto::where('user_id', $member->id)->latest('id')->firstOrFail();
}

function mgmPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);
}

// ─── Gate: só moderador/admin ────────────────────────────────────────────────

it('a fila de fotos de membro é fechada a membro e performer (só moderador/admin)', function () {
    $consumer = mgmMember();
    $performer = mgmPerformer();
    $moderator = User::factory()->create(['role' => 'moderator', 'status' => 'active']);

    $this->actingAs($consumer)->get(route('moderacao.member-photos.index'))->assertForbidden();
    $this->actingAs($performer->user)->get(route('moderacao.member-photos.index'))->assertForbidden();
    $this->actingAs($moderator)->get(route('moderacao.member-photos.index'))->assertOk();
});

// ─── Aprovar / recusar ───────────────────────────────────────────────────────

it('aprovar publica a foto (approved + moderated_by) e ela passa a aparecer para a performer', function () {
    $member = mgmMember();
    $photo = mgmPendingPhoto($member);
    $mod = User::factory()->create(['role' => 'moderator', 'status' => 'active']);

    $this->actingAs($mod)
        ->patch(route('moderacao.member-photos.update', $photo->id), ['status' => 'approved'])
        ->assertRedirect();

    $photo->refresh();
    expect($photo->status)->toBe('approved')
        ->and($photo->moderated_by)->toBe($mod->id)
        ->and($photo->is_primary)->toBeTrue(); // primeira aprovada vira principal

    expect(app(MemberGalleryService::class)->approvedFor($member->fresh()))->toHaveCount(1);
});

it('recusar sem motivo é barrado; com motivo grava e purga os bytes + avisa o membro', function () {
    Queue::fake();
    $member = mgmMember();
    $photo = mgmPendingPhoto($member);
    $path = $photo->path;
    $mod = User::factory()->create(['role' => 'moderator', 'status' => 'active']);

    // Sem motivo → erro de validação.
    $this->actingAs($mod)->from(route('moderacao.member-photos.index'))
        ->patch(route('moderacao.member-photos.update', $photo->id), ['status' => 'rejected'])
        ->assertSessionHasErrors('reject_reason');

    // Com motivo → grava, PURGA os bytes na hora, mantém a linha com o motivo.
    $this->actingAs($mod)
        ->patch(route('moderacao.member-photos.update', $photo->id), [
            'status' => 'rejected', 'reject_reason' => 'Não parece ser você.',
        ])->assertRedirect();

    $photo->refresh();
    expect($photo->status)->toBe('rejected')
        ->and($photo->reject_reason)->toBe('Não parece ser você.')
        ->and($photo->path)->toBe(''); // bytes purgados
    Storage::disk('local')->assertMissing($path);

    // O membro é avisado (mesmo padrão da intro de voz).
    Queue::assertPushed(SendMemberPhotoRejectedEmail::class);
});

it('aprovar/recusar é no-op fora de PENDING (não regride uma já aprovada)', function () {
    $member = mgmMember();
    $photo = mgmPendingPhoto($member);
    $mod = User::factory()->create(['role' => 'moderator', 'status' => 'active']);
    app(MemberGalleryService::class)->approve($photo, $mod);

    // Tentar recusar uma já aprovada não a derruba.
    app(MemberGalleryService::class)->reject($photo->fresh(), $mod, 'tarde demais');
    expect($photo->fresh()->status)->toBe('approved');
});

// ─── Serving da imagem para o moderador ──────────────────────────────────────

it('o serving da imagem na fila é fechado a não-moderador', function () {
    $member = mgmMember();
    $photo = mgmPendingPhoto($member);
    $consumer = mgmMember();

    $this->actingAs($consumer)->get(route('moderacao.member-photos.image', $photo->id))->assertForbidden();

    $mod = User::factory()->create(['role' => 'moderator', 'status' => 'active']);
    $this->actingAs($mod)->get(route('moderacao.member-photos.image', $photo->id))->assertOk();
});
