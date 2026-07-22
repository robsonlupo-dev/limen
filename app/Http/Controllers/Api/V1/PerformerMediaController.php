<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PerformerMediaController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $profileId = $request->integer('profile_id');
        $type = $request->input('type');

        abort_unless(in_array($type, ['avatar', 'cover'], true), 404);

        $profile = PerformerProfile::findOrFail($profileId);

        $path = $type === 'avatar' ? $profile->avatar_path : $profile->cover_path;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
