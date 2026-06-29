<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tip extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'consumer_id', 'performer_profile_id', 'amount', 'performer_amount',
        'platform_amount', 'message', 'idempotency_key',
        'consumer_ledger_id', 'performer_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'performer_amount' => 'integer',
            'platform_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Tip records are immutable.');
        });
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function consumerLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'consumer_ledger_id');
    }

    public function performerLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'performer_ledger_id');
    }
}
