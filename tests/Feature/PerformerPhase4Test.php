<?php

use Illuminate\Support\Str;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ─── Helpers ────────────────────────────────────────────────────────────────

function makePerformer(array $userAttrs = [], array $profileAttrs = []): array
{
    $user = User::factory()->create(array_merge([
        'role'   => 'performer',
        'status' => 'active',
    ], $userAttrs));

    $profile = $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana Lima ' . Str::random(4),
        'slug'        => 'ana-lima-' . strtolower(\Illuminate\Support\Str::random(4)),
        'category'    => 'mulheres',
        'is_verified' => true,
    ], $profileAttrs));

    $token = $user->createToken('api')->plainTextToken;

    return [$user, $profile, $token];
}

function makeConsumer(): array
{
    $user  = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $token = $user->createToken('api')->plainTextToken;

    return [$user, $token];
}

// ─── 1. Performer updates own profile ───────────────────────────────────────

it('performer updates own profile and receives updated data', function () {
    [, $profile, $token] = makePerformer();

    $response = $this->putJson('/api/v1/performer/profile', [
        'bio'         => 'New bio text',
        'rate_public' => 80,
        'category'    => 'casais',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertOk()
        ->assertJsonPath('data.bio', 'New bio text')
        ->assertJsonPath('data.rate_public', 80)
        ->assertJsonPath('data.category', 'casais');

    $this->assertDatabaseHas('performer_profiles', [
        'id'          => $profile->id,
        'bio'         => 'New bio text',
        'rate_public' => 80,
    ]);
});

// ─── 2. Consumer cannot update performer profile (403) ───────────────────────

it('returns 403 when consumer tries to update performer profile', function () {
    [, $token] = makeConsumer();

    $this->putJson('/api/v1/performer/profile', ['bio' => 'hacked'], [
        'Authorization' => "Bearer $token",
    ])->assertStatus(403);
});

// ─── 3. Catalog shows only active+verified performers ───────────────────────

it('catalog returns only active and verified performers', function () {
    // active + verified → should appear
    [, $visible] = makePerformer();

    // pending user → should NOT appear
    [, $pendingProfile] = makePerformer(['status' => 'pending']);

    // suspended user → should NOT appear
    [, $suspendedProfile] = makePerformer(['status' => 'suspended']);

    // active but not verified → should NOT appear
    [, $unverified] = makePerformer([], ['is_verified' => false]);

    $response = $this->getJson('/api/v1/performers');

    $response->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain($visible->slug);
    expect($slugs)->not->toContain($pendingProfile->slug);
    expect($slugs)->not->toContain($suspendedProfile->slug);
    expect($slugs)->not->toContain($unverified->slug);
});

// ─── 4. Category filter works ────────────────────────────────────────────────

it('catalog category filter returns only matching performers', function () {
    [, $mulheres] = makePerformer([], ['category' => 'mulheres']);
    [, $homens]   = makePerformer([], ['category' => 'homens']);

    $response = $this->getJson('/api/v1/performers?category=mulheres');

    $response->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain($mulheres->slug);
    expect($slugs)->not->toContain($homens->slug);
});

// ─── 5. Stage-name search works ──────────────────────────────────────────────

it('catalog search by stage_name returns matching performers', function () {
    [, $match]   = makePerformer([], ['stage_name' => 'Unique Name XYZ']);
    [, $nomatch] = makePerformer([], ['stage_name' => 'Completely Different']);

    $response = $this->getJson('/api/v1/performers?search=Unique+Name');

    $response->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain($match->slug);
    expect($slugs)->not->toContain($nomatch->slug);
});

// ─── 6. Public profile does not expose internal fields ───────────────────────

it('public profile response does not expose user_id, email, CPF, or rates', function () {
    [, $profile] = makePerformer();

    $response = $this->getJson("/api/v1/performers/{$profile->slug}");

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('user_id');
    expect($data)->not->toHaveKey('email');
    expect($data)->not->toHaveKey('cpf');
    expect($data)->not->toHaveKey('rate_public');
    expect($data)->not->toHaveKey('rate_private');
    expect($data)->not->toHaveKey('rate_camera');
    expect($data)->not->toHaveKey('split_pct');
    expect($data)->not->toHaveKey('level');
    expect($data)->toHaveKey('slug');
    expect($data)->toHaveKey('stage_name');
});

// ─── 7. Follow increments followers_count; idempotent ───────────────────────

it('follow increments followers_count and is idempotent on second call', function () {
    [, $profile] = makePerformer();
    [, $token]   = makeConsumer();

    // First follow
    $this->postJson("/api/v1/performers/{$profile->slug}/follow", [], [
        'Authorization' => "Bearer $token",
    ])->assertOk()->assertJsonPath('data.following', true)
        // A API devolve a FAIXA, não o número: o contador exato de um perfil
        // pequeno identifica quem seguiu e quando. O incremento real continua
        // sendo verificado no banco, logo abaixo.
        ->assertJsonPath('data.followers_label', 'Menos de 5');

    expect(Follow::count())->toBe(1);

    // Second follow (idempotent)
    $this->postJson("/api/v1/performers/{$profile->slug}/follow", [], [
        'Authorization' => "Bearer $token",
    ])->assertOk()->assertJsonPath('data.followers_label', 'Menos de 5');

    expect(Follow::count())->toBe(1);

    $profile->refresh();
    expect($profile->followers_count)->toBe(1);
});

// ─── 8. Unfollow decrements followers_count ──────────────────────────────────

it('unfollow decrements followers_count by 1', function () {
    [, $profile] = makePerformer();
    [, $token]   = makeConsumer();

    $this->postJson("/api/v1/performers/{$profile->slug}/follow", [], [
        'Authorization' => "Bearer $token",
    ])->assertOk();

    $profile->refresh();
    expect($profile->followers_count)->toBe(1);

    $this->deleteJson("/api/v1/performers/{$profile->slug}/follow", [], [
        'Authorization' => "Bearer $token",
    ])->assertOk()->assertJsonPath('data.following', false)
        ->assertJsonPath('data.followers_label', 'Menos de 5');

    $profile->refresh();
    expect($profile->followers_count)->toBe(0);
    expect(Follow::count())->toBe(0);
});

// ─── 9. GET /following returns true/false correctly ─────────────────────────

it('GET following returns correct boolean for followed and unfollowed states', function () {
    [, $profile] = makePerformer();
    [, $token]   = makeConsumer();

    // Not yet following
    $this->getJson("/api/v1/performers/{$profile->slug}/following", [
        'Authorization' => "Bearer $token",
    ])->assertOk()->assertJsonPath('data.following', false);

    // Follow
    $this->postJson("/api/v1/performers/{$profile->slug}/follow", [], [
        'Authorization' => "Bearer $token",
    ]);

    // Now following
    $this->getJson("/api/v1/performers/{$profile->slug}/following", [
        'Authorization' => "Bearer $token",
    ])->assertOk()->assertJsonPath('data.following', true);
});

// ─── 10. Avatar upload stored in private storage, URL returned ───────────────

it('avatar upload stores file in private storage and returns a temporary URL', function () {
    Storage::fake('local');

    [$user, $profile, $token] = makePerformer();

    $file = UploadedFile::fake()->create('avatar.jpg', 500, 'image/jpeg');

    $response = $this->postJson('/api/v1/performer/profile/avatar',
        ['file' => $file],
        ['Authorization' => "Bearer $token"]
    );

    $response->assertOk()->assertJsonStructure(['avatar_url']);

    $profile->refresh();
    expect($profile->avatar_path)->not->toBeNull();

    Storage::disk('local')->assertExists($profile->avatar_path);

    // Verify the URL is NOT a public storage URL
    expect($response->json('avatar_url'))->not->toContain('storage/');
    expect($response->json('avatar_url'))->not->toBeNull();
});

// ─── 11. Invalid upload returns 422 ──────────────────────────────────────────

it('rejects invalid file type and file exceeding 5MB with 422', function () {
    Storage::fake('local');

    [, , $token] = makePerformer();

    // Invalid mime type (pdf)
    $this->postJson('/api/v1/performer/profile/avatar',
        ['file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')],
        ['Authorization' => "Bearer $token"]
    )->assertStatus(422)->assertJsonValidationErrors('file');

    // Too large (> 5MB)
    $this->postJson('/api/v1/performer/profile/avatar',
        ['file' => UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg')],
        ['Authorization' => "Bearer $token"]
    )->assertStatus(422)->assertJsonValidationErrors('file');
});

// ─── 12. Pending performer not in catalog and returns 404 on slug ────────────

it('pending performer does not appear in catalog and returns 404 on GET by slug', function () {
    [, $pendingProfile] = makePerformer(
        ['status' => 'pending'],
        ['stage_name' => 'Hidden Performer']
    );

    // Not in catalog
    $response = $this->getJson('/api/v1/performers');
    $slugs    = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->not->toContain($pendingProfile->slug);

    // Direct slug returns 404
    $this->getJson("/api/v1/performers/{$pendingProfile->slug}")
        ->assertStatus(404);
});
