<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBackfillPerformerProfile(array $profileAttrs = [])
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana Lima',
        'slug' => 'ana-lima-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ], $profileAttrs));
}

beforeEach(function () {
    Storage::fake('local');
});

it('regenerates a placeholder for a profile whose avatar file is missing', function () {
    $profile = makeBackfillPerformerProfile(['avatar_path' => 'performer-media/999/avatar.jpg']);

    // File referenced by avatar_path does not exist on disk.
    Storage::disk('local')->assertMissing('performer-media/999/avatar.jpg');

    $this->artisan('performers:backfill-avatars')->assertSuccessful();

    $profile->refresh();
    expect($profile->avatar_path)->toBe("performer-media/{$profile->user_id}/avatar.svg");
    Storage::disk('local')->assertExists($profile->avatar_path);
});

it('backfills a profile with a null avatar path', function () {
    $profile = makeBackfillPerformerProfile(['avatar_path' => null]);

    $this->artisan('performers:backfill-avatars')->assertSuccessful();

    $profile->refresh();
    expect($profile->avatar_path)->toBe("performer-media/{$profile->user_id}/avatar.svg");
    Storage::disk('local')->assertExists($profile->avatar_path);
});

it('leaves an intact avatar untouched', function () {
    $profile = makeBackfillPerformerProfile(['avatar_path' => null]);
    $path = "performer-media/{$profile->user_id}/avatar.jpg";
    Storage::disk('local')->put($path, 'real-bytes');
    $profile->update(['avatar_path' => $path]);

    $this->artisan('performers:backfill-avatars')->assertSuccessful();

    $profile->refresh();
    expect($profile->avatar_path)->toBe($path);
    expect(Storage::disk('local')->get($path))->toBe('real-bytes');
});

it('produces a file the media endpoint can serve', function () {
    $profile = makeBackfillPerformerProfile(['avatar_path' => null]);

    $this->artisan('performers:backfill-avatars')->assertSuccessful();
    $profile->refresh();

    $url = URL::temporarySignedRoute('performer.media', now()->addMinutes(10), [
        'profile_id' => $profile->id,
        'type' => 'avatar',
    ]);

    $this->get($url)->assertOk();
});
