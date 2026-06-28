<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PerformerPrivateResource extends PerformerPublicResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'rate_public'  => $this->rate_public,
            'rate_private' => $this->rate_private,
            'rate_camera'  => $this->rate_camera,
            'level'        => $this->level,
            'split_pct'    => $this->split_pct,
        ]);
    }
}
