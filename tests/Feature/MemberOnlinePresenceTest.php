<?php

use App\Http\Middleware\TrackMemberActivity;
use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Support\FanAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Online agora" do membro (feat/member-online-presence, etapa 2b): o batimento
 * (TrackMemberActivity), a janela binária (User::isOnlineNow) e a exposição
 * booleana no perfil e no card. Trava os invariantes: supressão na ESCRITA sob
 * invisível/ghost, throttle, e nunca um horário — só true/false.
 *
 * Helpers `mopresence*` para o arquivo rodar isolado.
 */
beforeEach(function () {
    Storage::fake('local');
});

function mopresenceRun(User $user): void
{
    $request = Request::create('/qualquer', 'GET');
    $request->setUserResolver(fn () => $user);
    (new TrackMemberActivity)->handle($request, fn () => new Response('ok'));
}

function mopresencePerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function mopresenceMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs))->fresh();
}

// ═══ Batimento: carimba, com throttle ═══════════════════════════════════════

it('carimba last_active_at do membro num request', function () {
    $member = mopresenceMember(['last_active_at' => null]);

    mopresenceRun($member);

    expect($member->fresh()->last_active_at)->not->toBeNull();
});

it('respeita o throttle: dentro da janela nao re-carimba, fora sim', function () {
    // Dentro da janela de 2 min: NÃO reescreve.
    $recent = now()->subMinute();
    $member = mopresenceMember(['last_active_at' => $recent]);
    mopresenceRun($member);
    expect($member->fresh()->last_active_at->timestamp)->toBe($recent->timestamp);

    // Fora da janela: reescreve.
    $stale = now()->subMinutes(3);
    $member2 = mopresenceMember(['last_active_at' => $stale]);
    mopresenceRun($member2);
    expect($member2->fresh()->last_active_at->greaterThan(now()->subMinute()))->toBeTrue();
});

// ═══ Supressão na ESCRITA: invisível / ghost / papel ════════════════════════

it('NAO carimba quem tem Status Invisivel ou Ghost Mode', function () {
    $invisible = mopresenceMember(['invisible_status' => true, 'last_active_at' => null]);
    mopresenceRun($invisible);
    expect($invisible->fresh()->last_active_at)->toBeNull();

    $ghost = mopresenceMember(['ghost_mode' => true, 'last_active_at' => null]);
    mopresenceRun($ghost);
    expect($ghost->fresh()->last_active_at)->toBeNull();
});

it('NAO carimba performer (o batimento do membro e so do consumer)', function () {
    $performer = mopresencePerformer();
    $performer->forceFill(['last_active_at' => null])->saveQuietly();

    mopresenceRun($performer);

    expect($performer->fresh()->last_active_at)->toBeNull();
});

// ═══ Janela binária: isOnlineNow ════════════════════════════════════════════

it('isOnlineNow: ativo dentro da janela = true; velho ou invisivel = false', function () {
    $online = mopresenceMember(['last_active_at' => now()->subMinutes(2)]);
    expect($online->isOnlineNow())->toBeTrue();

    $stale = mopresenceMember(['last_active_at' => now()->subMinutes(10)]);
    expect($stale->isOnlineNow())->toBeFalse();

    $never = mopresenceMember(['last_active_at' => null]);
    expect($never->isOnlineNow())->toBeFalse();

    // Invisível: false mesmo com carimbo fresco (cinto-e-suspensório na leitura).
    $invisible = mopresenceMember(['last_active_at' => now(), 'invisible_status' => true]);
    expect($invisible->isOnlineNow())->toBeFalse();
});

// ═══ Payload: booleano no perfil, nunca o horário ═══════════════════════════

it('o perfil expoe is_online (booleano) e nunca o last_active_at', function () {
    $performer = mopresencePerformer();
    $member = mopresenceMember(['profile_visible' => true, 'last_active_at' => now()]);
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'b');
    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $path; $photo->full_path = $path;
    $photo->token = Str::random(48); $photo->full_token = Str::random(48);
    $photo->status = 'approved'; $photo->is_primary = true; $photo->is_private = false; $photo->save();

    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $response = $this->actingAs($performer)->get(route('performer.members.profile', $handle));
    $response->assertInertia(fn (Assert $p) => $p
        ->where('member.is_online', true)
        ->missing('member.last_active_at')
        ->etc());

    // O horário cru nunca aparece no HTML/props.
    expect($response->getContent())->not->toContain($member->last_active_at->toIso8601String());
});

it('membro invisivel aparece offline no perfil (is_online false)', function () {
    $performer = mopresencePerformer();
    $member = mopresenceMember([
        'profile_visible' => true, 'last_active_at' => now(), 'invisible_status' => true,
    ]);
    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $this->actingAs($performer)->get(route('performer.members.profile', $handle))
        ->assertInertia(fn (Assert $p) => $p->where('member.is_online', false)->etc());
});

// ═══ Card do catálogo: is_online ════════════════════════════════════════════

it('o card do catalogo traz is_online', function () {
    $performer = mopresencePerformer();
    $online = mopresenceMember(['profile_visible' => true, 'last_active_at' => now()]);
    $offline = mopresenceMember(['profile_visible' => true, 'last_active_at' => now()->subHour()]);

    $rows = app(\App\Services\MemberCatalogService::class)->page($performer->performerProfile)->getCollection();

    $onlineCard = $rows->firstWhere('member_handle', FanAlias::handle($performer->performerProfile->id, $online->id));
    $offlineCard = $rows->firstWhere('member_handle', FanAlias::handle($performer->performerProfile->id, $offline->id));

    expect($onlineCard['is_online'])->toBeTrue()
        ->and($offlineCard['is_online'])->toBeFalse();
});
