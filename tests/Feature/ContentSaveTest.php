<?php

use App\Models\ContentSave;
use App\Models\ContentUnlock;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ContentSaveService;
use App\Services\ContentVisibilityService;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * "Salvos" do membro — coleções privadas de conteúdo, v1 (roadmap social, Onda 3
 * §3.1). Eixo: (1) salvar exige PODER VER a peça (não se salva cadeado); (2) a
 * performer NUNCA vê os salvos (sem superfície, sem contador); (3) idempotência do
 * toggle; (4) o mapa de salvos some no Hard Delete do membro. Prefixo `sv`.
 */
function svPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function svMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function svPiece(PerformerProfile $profile, string $level = 'open', string $status = 'ready'): PerformerContent
{
    return PerformerContent::forceCreate([
        'performer_profile_id' => $profile->id,
        'kind' => PerformerContent::KIND_PHOTO,
        'status' => $status,
        'access_level' => $level,
        'price_tokens' => 20,
        'path' => $profile->id.'/'.Str::random(16).'.jpg',
        'content_hash' => str_repeat('a', 64),
    ]);
}

/** Desbloqueio permanente do membro → canView passa a valer para a peça. */
function svUnlock(User $member, PerformerContent $piece): void
{
    ContentUnlock::forceCreate([
        'performer_content_id' => $piece->id,
        'user_id' => $member->id,
        'tokens_paid' => 20,
        'unlocked_at' => now(),
    ]);
}

// ─── Salvar exige poder VER ──────────────────────────────────────────────────

it('recusa salvar uma peça que o membro não pode ver (403)', function () {
    $performer = svPerformer();
    $piece = svPiece($performer, 'exclusive'); // sem alcance para o membro
    $member = svMember();

    $this->actingAs($member)
        ->postJson(route('content.save.toggle', $piece->id), ['on' => true])
        ->assertStatus(403)->assertJsonPath('reason', 'forbidden');

    expect(ContentSave::count())->toBe(0);
});

it('salva quando o membro já pode ver a peça (desbloqueada)', function () {
    $performer = svPerformer();
    $piece = svPiece($performer, 'premium');
    $member = svMember();
    svUnlock($member, $piece);

    $this->actingAs($member)
        ->postJson(route('content.save.toggle', $piece->id), ['on' => true])
        ->assertOk()->assertJsonPath('saved', true);

    expect(app(ContentSaveService::class)->isSaved($member->fresh(), $piece))->toBeTrue();
});

// ─── Idempotência ────────────────────────────────────────────────────────────

it('salvar e desalvar são idempotentes', function () {
    $performer = svPerformer();
    $piece = svPiece($performer);
    $member = svMember();
    svUnlock($member, $piece);
    $svc = app(ContentSaveService::class);

    expect($svc->setSaved($member, $piece, true))->toBeTrue();
    expect($svc->setSaved($member, $piece, true))->toBeTrue(); // de novo: no-op
    expect(ContentSave::where('user_id', $member->id)->count())->toBe(1);

    expect($svc->setSaved($member, $piece, false))->toBeFalse();
    expect($svc->setSaved($member, $piece, false))->toBeFalse(); // de novo: no-op
    expect(ContentSave::where('user_id', $member->id)->count())->toBe(0);
});

// ─── Só consumidor ───────────────────────────────────────────────────────────

it('performer não acessa o toggle de salvar (role:consumer)', function () {
    $performer = svPerformer();
    $piece = svPiece($performer);

    $this->actingAs($performer->user)
        ->postJson(route('content.save.toggle', $piece->id), ['on' => true])
        ->assertForbidden();

    expect(ContentSave::count())->toBe(0);
});

// ─── A performer NUNCA vê os salvos ──────────────────────────────────────────

it('não expõe "saved" para a performer que vê a própria vitrine', function () {
    $performer = svPerformer();
    $piece = svPiece($performer, 'premium');
    $member = svMember();
    svUnlock($member, $piece);
    app(ContentSaveService::class)->setSaved($member, $piece, true);

    // O membro que salvou vê saved=true.
    $forMember = collect(app(ContentVisibilityService::class)->galleryFor($member, $performer))
        ->firstWhere('id', $piece->id);
    expect($forMember['saved'])->toBeTrue();

    // A performer, vendo a própria vitrine, NUNCA recebe saved=true (sem vazamento).
    $forOwner = collect(app(ContentVisibilityService::class)->galleryFor($performer->user, $performer))
        ->firstWhere('id', $piece->id);
    expect($forOwner['saved'])->toBeFalse();
});

// ─── Lista do membro ─────────────────────────────────────────────────────────

it('lista os salvos do membro e some com performer fora do ar', function () {
    $performer = svPerformer();
    $piece = svPiece($performer, 'premium');
    $member = svMember();
    svUnlock($member, $piece);
    app(ContentSaveService::class)->setSaved($member, $piece, true);

    $rows = app(ContentSaveService::class)->paginateFor($member);
    expect($rows->pluck('id')->all())->toBe([$piece->id]);

    // Performer suspensa → some da lista (o salvo em si continua guardado).
    // forceFill: `status` é autoridade do servidor, fora do $fillable — um
    // update() de array seria no-op silencioso e o teste não exercitaria nada.
    $performer->user->forceFill(['status' => 'suspended'])->save();
    $rows = app(ContentSaveService::class)->paginateFor($member->fresh());
    expect($rows->count())->toBe(0);
    expect(ContentSave::where('user_id', $member->id)->count())->toBe(1);
});

// ─── Cascade + Hard Delete ───────────────────────────────────────────────────

it('apagar a peça remove o salvo (cascade)', function () {
    $performer = svPerformer();
    $piece = svPiece($performer);
    $member = svMember();
    svUnlock($member, $piece);
    app(ContentSaveService::class)->setSaved($member, $piece, true);

    $piece->delete();

    expect(ContentSave::count())->toBe(0);
});

it('purga o mapa de salvos do membro no Hard Delete', function () {
    $performer = svPerformer();
    $piece = svPiece($performer, 'premium');
    $member = svMember();
    svUnlock($member, $piece);
    app(ContentSaveService::class)->setSaved($member, $piece, true);
    expect(ContentSave::where('user_id', $member->id)->count())->toBe(1);

    app(DeletionService::class)->executeDeletion($member->fresh());

    expect(ContentSave::where('user_id', $member->id)->count())->toBe(0);
});
