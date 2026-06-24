<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'token_package_id', 'provider', 'provider_charge_id',
        'method', 'amount_cents', 'tokens', 'status',
        'pix_qr_code', 'pix_copy_paste', 'expires_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tokens' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tokenPackage(): BelongsTo
    {
        return $this->belongsTo(TokenPackage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
