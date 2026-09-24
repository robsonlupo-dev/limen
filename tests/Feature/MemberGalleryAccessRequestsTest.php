<?php

use App\Models\MemberGalleryAccess;
use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Services\MemberGalleryService;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Liberação das fotos PRIVADAS do membro para uma performer
 * (feat/member-gallery-access-requests, Etapa 2). Granularidade POR PAR: liberar
 * abre TODAS as privadas do membro para aquela performer; revogar re-tranca.
 * Trava: pedir é idempotente e 404 quando não há o que pedir; liberar só sobre um
 * pedido existente; serving e presenter concordam (sem oráculo); o acesso é por
 * par (uma performer liberada não abre para outra). Helpers `mgar*`.
 */
beforeEach(function () {
    Storage::fake('local');
});

function mgarPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function mgarMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs));
}

function mgarPhoto(User $member, bool $isPrivate): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    $full = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'cropped');
    Storage::disk('local')->put($full, 'full');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $path;
    $photo->full_path = $full;
    $photo->token = Str::random(48);
    $photo->full_token = Str::random(48);
    $photo->status = 'approved';
    $photo->is_private = $isPrivate;
    $photo->save();

    return $photo;
}

function mgarHandle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ─── Pedir ───────────────────────────────────────────────────────────────────

it('a performer solicita acesso e vira pending (idempotente)', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    mgarPhoto($member, isPrivate: true);

    $this->actingAs($performer)
        ->postJson(route('performer.members.gallery-access-request'), ['member_handle' => mgarHandle($performer, $member)])
        ->assertOk()
        ->assertJson(['state' => 'pending']);

    // Reenviar é no-op: continua um pedido só.
    $this->actingAs($performer)
        ->postJson(route('performer.members.gallery-access-request'), ['member_handle' => mgarHandle($performer, $member)])
        ->assertOk()
        ->assertJson(['state' => 'pending']);

    expect(MemberGalleryAccess::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->performerProfile->id)->count())->toBe(1);
});

it('pedir 404 quando o membro não tem foto privada (nada a solicitar)', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    mgarPhoto($member, isPrivate: false); // só pública

    $this->actingAs($performer)
        ->postJson(route('performer.members.gallery-access-request'), ['member_handle' => mgarHandle($performer, $member)])
        ->assertNotFound();
});

it('pedir 404 quando o perfil do membro não está visível', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => false]);
    mgarPhoto($member, isPrivate: true);

    $this->actingAs($performer)
        ->postJson(route('performer.members.gallery-access-request'), ['member_handle' => mgarHandle($performer, $member)])
        ->assertNotFound();
});

// ─── Liberar / revogar ───────────────────────────────────────────────────────

it('liberar abre TODAS as privadas para aquela performer (serving + presenter)', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    $priv1 = mgarPhoto($member, isPrivate: true);
    $priv2 = mgarPhoto($member, isPrivate: true);

    // Antes: a performer não vê os bytes das privadas.
    $this->actingAs($performer)->get($priv1->mediaUrl())->assertNotFound();

    // A performer pede; o membro libera.
    app(App\Services\MemberGalleryAccessService::class)->request($performer->performerProfile, $member);
    $this->actingAs($member)
        ->post(route('consumer.gallery.access.grant', $performer->performerProfile->id))
        ->assertRedirect();

    // Agora as DUAS privadas servem para essa performer.
    $this->actingAs($performer)->get($priv1->fresh()->mediaUrl())->assertOk();
    $this->actingAs($performer)->get($priv2->fresh()->mediaUrl())->assertOk();

    // E o presenter emite url (locked false) para ela.
    $rows = app(MemberGalleryService::class)->approvedFor($member->fresh(), $performer->fresh());
    expect($rows->every(fn ($p) => $p['locked'] === false))->toBeTrue()
        ->and($rows->every(fn ($p) => $p['url'] !== null))->toBeTrue();
});

it('revogar re-tranca na hora', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    $priv = mgarPhoto($member, isPrivate: true);

    app(App\Services\MemberGalleryAccessService::class)->request($performer->performerProfile, $member);
    app(App\Services\MemberGalleryAccessService::class)->grant($member, $performer->performerProfile);
    $this->actingAs($performer)->get($priv->fresh()->mediaUrl())->assertOk();

    // Revoga → a MESMA URL assinada para de servir.
    $this->actingAs($member)
        ->delete(route('consumer.gallery.access.revoke', $performer->performerProfile->id))
        ->assertRedirect();

    $this->actingAs($performer)->get($priv->fresh()->mediaUrl())->assertNotFound();
});

it('o acesso é POR PAR: liberar a performer A não abre para a performer B', function () {
    $a = mgarPerformer();
    $b = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    $priv = mgarPhoto($member, isPrivate: true);

    app(App\Services\MemberGalleryAccessService::class)->request($a->performerProfile, $member);
    app(App\Services\MemberGalleryAccessService::class)->grant($member, $a->performerProfile);

    // A vê; B (sem liberação) não.
    $this->actingAs($a)->get($priv->fresh()->mediaUrl())->assertOk();
    $this->actingAs($b)->get($priv->fresh()->mediaUrl())->assertNotFound();
});

it('o membro não pode liberar quem não pediu (404)', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    mgarPhoto($member, isPrivate: true);

    // Sem pedido pendente → não há o que aprovar.
    $this->actingAs($member)
        ->post(route('consumer.gallery.access.grant', $performer->performerProfile->id))
        ->assertNotFound();
});

// ─── Payload do perfil (estado do botão) ─────────────────────────────────────

it('o perfil expõe o estado do acesso e destranca as privadas quando liberado', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    mgarPhoto($member, isPrivate: true);

    // Sem pedido: privada aparece locked e o estado é 'none'.
    $this->actingAs($performer)
        ->get(route('performer.members.profile', mgarHandle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p
            ->where('member.private_access.has_private', true)
            ->where('member.private_access.state', 'none')
            ->where('member.photos.0.locked', true)
            ->etc());

    // Pede e libera → estado 'granted' e a privada vem destrancada (com url).
    app(App\Services\MemberGalleryAccessService::class)->request($performer->performerProfile, $member);
    app(App\Services\MemberGalleryAccessService::class)->grant($member, $performer->performerProfile);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mgarHandle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p
            ->where('member.private_access.state', 'granted')
            ->where('member.photos.0.locked', false)
            ->where('member.photos.0.url', fn ($u) => is_string($u) && $u !== '')
            ->etc());
});

it('a caixa de pedidos do membro lista quem pediu e some após liberar', function () {
    $performer = mgarPerformer();
    $member = mgarMember(['profile_visible' => true]);
    mgarPhoto($member, isPrivate: true);
    app(App\Services\MemberGalleryAccessService::class)->request($performer->performerProfile, $member);

    // Pendente aparece em access_requests.
    $this->actingAs($member)
        ->get(route('consumer.profile.edit'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('access_requests', fn ($r) => count($r) === 1 && $r[0]['performer_profile_id'] === $performer->performerProfile->id)
            ->where('access_granted', fn ($g) => count($g) === 0)
            ->etc());

    // Após liberar, migra para access_granted.
    app(App\Services\MemberGalleryAccessService::class)->grant($member, $performer->performerProfile);
    $this->actingAs($member)
        ->get(route('consumer.profile.edit'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('access_requests', fn ($r) => count($r) === 0)
            ->where('access_granted', fn ($g) => count($g) === 1)
            ->etc());
});
