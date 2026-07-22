<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TokenPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tokens' => $this->tokens,
            'bonus' => $this->bonus,
            'price_cents' => $this->price_cents,
            'price_formatted' => 'R$ '.number_format($this->price_cents / 100, 2, ',', '.'),
        ];
    }
}
