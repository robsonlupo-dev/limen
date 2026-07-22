<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'following' => $this->resource['following'],
            // Faixa: devolver o exato aqui daria à performer um contador preciso
            // via a própria API de follow.
            'followers_label' => $this->resource['followers_label'],
        ];
    }
}
