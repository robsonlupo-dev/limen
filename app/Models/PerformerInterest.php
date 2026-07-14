<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerInterest extends Model
{
    protected $fillable = [
        'performer_profile_id', 'member_id', 'status',
        'sent_at', 'unlocked_at', 'unlock_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function isUnlocked(): bool
    {
        return $this->status === 'unlocked';
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function unlockLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'unlock_ledger_id');
    }
}
