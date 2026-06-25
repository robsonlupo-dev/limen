<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount_cents' => $this->amount_cents,
            'tokens' => $this->tokens,
            'pix_qr_code' => $this->pix_qr_code,
            'pix_copy_paste' => $this->pix_copy_paste,
            'expires_at' => $this->expires_at,
            'confirmed_at' => $this->confirmed_at,
            'created_at' => $this->created_at,
        ];
    }
}
