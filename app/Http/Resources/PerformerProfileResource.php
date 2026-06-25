<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage_name' => $this->stage_name,
            'bio' => $this->bio,
            'category' => $this->category,
            'work_modes' => $this->work_modes,
            'level' => $this->level,
            'is_live' => $this->is_live,
            'is_verified' => $this->is_verified,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'followers_count' => $this->followers_count,
            'avatar_path' => $this->avatar_path,
            'cover_path' => $this->cover_path,
        ];
    }
}
