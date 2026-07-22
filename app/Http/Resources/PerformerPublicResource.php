<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PerformerPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'stage_name' => $this->stage_name,
            'bio' => $this->bio,
            'category' => $this->category,
            'work_modes' => $this->work_modes,
            'is_live' => $this->is_live,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            // Faixa, nunca o número exato: ver PerformerProfile::followersCountLabel().
            'followers_label' => $this->followersCountLabel(),
            'avatar_url' => $this->mediaUrl('avatar'),
            'cover_url' => $this->mediaUrl('cover'),
        ];
    }

    protected function mediaUrl(string $type): ?string
    {
        $path = $type === 'avatar' ? $this->avatar_path : $this->cover_path;

        if (! $path) {
            return null;
        }

        // Use profile id (not user_id) to avoid exposing internal user identifiers.
        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $this->id, 'type' => $type]
        );
    }
}
