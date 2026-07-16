<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCharge extends Model
{
    protected $fillable = [
        'subscription_id', 'provider_event_id', 'amount_cents',
        'status', 'charged_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'charged_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
