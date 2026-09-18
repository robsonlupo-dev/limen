<?php

use App\Models\MemberGalleryPhoto;
use App\Models\MemberProfileVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MemberCatalogService;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Página de PERFIL do membro vista pela performer (feat/member-gallery-and-profile,
 * Opção B / Estágio 2). Trava os invariantes: só performer; só membro que optou
 * (profile_visible) tem página; só fotos APROVADAS aparecem; ZERO PII no payload;
 * abrir registra visita (Fase 13) RESPEITANDO o Ghost Mode.
 *
 * Helpers `mpp*` para o arquivo rodar isolado.
 */
beforeEach(function () {
    Storage::fake('local');
});

function mppPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function mppMember(array $attrs = [], ?string $circleSlug = null): User
{
    $member = User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs));

    if ($circleSlug !== null) {
        Subscription::factory()->circle($circleSlug)->create([
            'user_id' => $member->id, 'status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);
    }

    return $member->fresh();
}

function mppPhoto(User $member, string $status = 'approved', bool $primary = false): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'bytes');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $status === 'rejected' ? '' : $path;
    $photo->token = Str::random(48);
    $photo->status = $status;
    $photo->is_primary = $primary;
    $photo->save();

    return $photo;
}

function mppHandle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ─── Gate: só performer ──────────────────────────────────────────────────────

it('a página de perfil de membro exige role:performer (membro → 403)', function () {
    $member = mppMember(['profile_visible' => true]);
    $other = mppMember();

    $this->actingAs($other)
        ->get(route('performer.members.profile', 'deadbeefdeadbeef'))
        ->assertForbidden();
});

it('anônimo é redirecionado para login', function () {
    $this->get(route('performer.members.profile', 'deadbeefdeadbeef'))
        ->assertRedirect();
});

// ─── Opt-in mestre: sem profile_visible não há página ─────────────────────────

it('membro SEM opt-in (profile_visible=false) não tem perfil acessível (404)', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => false]);
    mppPhoto($member, 'approved', true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)))
        ->assertNotFound();
});

it('membro COM opt-in tem a página (component + fotos aprovadas)', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => true]);
    mppPhoto($member, 'approved', true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Performer/MemberProfile')->has('member.photos', 1));
});

// ─── Só APROVADAS aparecem ────────────────────────────────────────────────────

it('foto PENDING não aparece para a performer até ser aprovada', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => true]);
    mppPhoto($member, 'pending');       // não deve aparecer
    mppPhoto($member, 'approved', true); // deve aparecer

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p->has('member.photos', 1));
});

// ─── ZERO PII no payload ──────────────────────────────────────────────────────

it('o perfil não vaza nome/e-mail/tier/saldo em nenhum payload', function () {
    $performer = mppPerformer();
    $member = mppMember([
        'profile_visible' => true,
        'name' => 'Fulano Verdadeiro',
        'email' => 'fulano-secreto@example.com',
    ], 'black');
    // Black precisa de visibilidade explícita para aparecer no catálogo.
    $member->forceFill(['visible_to_performers' => true])->save();
    mppPhoto($member, 'approved', true);

    $response = $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)));

    $response->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Performer/MemberProfile')
            ->missing('member.name')
            ->missing('member.email')
            ->missing('member.tier')
            ->missing('member.lifestyle_tier')
            ->missing('member.balance'));

    // Defesa dupla: o HTML/JSON cru não contém o nome real nem o e-mail.
    expect($response->getContent())->not->toContain('Fulano Verdadeiro')
        ->and($response->getContent())->not->toContain('fulano-secreto@example.com')
        ->and($response->getContent())->not->toContain('"black"');
});

// ─── Visita (Fase 13) respeitando Ghost Mode ──────────────────────────────────

it('abrir o perfil de um membro comum REGISTRA a visita (Fase 13)', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => true]);
    mppPhoto($member, 'approved', true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)))
        ->assertOk();

    expect(MemberProfileVisit::where('performer_profile_id', $performer->performerProfile->id)
        ->where('member_id', $member->id)->count())->toBe(1);
});

it('abrir o perfil de um membro Black com Ghost Mode NÃO registra visita', function () {
    $performer = mppPerformer();
    // Black → Ghost Mode ligado por padrão; visível por escolha explícita + opt-in.
    $member = mppMember(['profile_visible' => true], 'black');
    $member->forceFill(['visible_to_performers' => true])->save();
    mppPhoto($member, 'approved', true);

    expect($member->fresh()->hasGhostMode())->toBeTrue(); // pré-condição

    $this->actingAs($performer)
        ->get(route('performer.members.profile', mppHandle($performer, $member)))
        ->assertOk();

    expect(MemberProfileVisit::where('member_id', $member->id)->count())->toBe(0);
});

// ─── Card do catálogo: profile_url + foto da galeria ──────────────────────────

it('o card só ganha profile_url quando o membro ligou o perfil visível', function () {
    $performer = mppPerformer();
    $visible = mppMember(['profile_visible' => true]);
    $hidden = mppMember(['profile_visible' => false]);
    mppPhoto($visible, 'approved', true);

    $rows = app(MemberCatalogService::class)->page($performer->performerProfile)->getCollection();
    $byHandle = $rows->keyBy('member_handle');

    $vh = mppHandle($performer, $visible);
    $hh = mppHandle($performer, $hidden);

    expect($byHandle[$vh]['profile_url'])->not->toBeNull()
        ->and($byHandle[$hh]['profile_url'])->toBeNull();
});

it('o card usa a foto principal aprovada da galeria quando o perfil é visível', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => true]);
    $primary = mppPhoto($member, 'approved', true);

    $rows = app(MemberCatalogService::class)->page($performer->performerProfile)->getCollection();
    $card = $rows->firstWhere('member_handle', mppHandle($performer, $member));

    // A URL do card carrega o token da foto principal da galeria (não o avatar).
    expect($card['avatar_url'])->toContain($primary->token);
});

it('membro com perfil visível mas SEM fotos aprovadas cai no avatar/silhueta no card', function () {
    $performer = mppPerformer();
    $member = mppMember(['profile_visible' => true]);
    mppPhoto($member, 'pending'); // não aprovada → não vira foto do card

    $rows = app(MemberCatalogService::class)->page($performer->performerProfile)->getCollection();
    $card = $rows->firstWhere('member_handle', mppHandle($performer, $member));

    // Sem avatar e sem foto aprovada → silhueta (avatar_url null).
    expect($card['avatar_url'])->toBeNull();
});
