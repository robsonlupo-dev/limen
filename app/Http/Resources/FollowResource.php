<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'following'       => $this->resource['following'],
            'followers_count' => $this->resource['followers_count'],
        ];
    }
}
