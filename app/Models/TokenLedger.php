<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenLedger extends Model
{
    const UPDATED_AT = null;

    protected $table = 'token_ledger';

    protected $fillable = [
        'wallet_id', 'entry_type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Token ledger entries are immutable.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Token ledger entries cannot be deleted.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TokenWallet::class, 'wallet_id');
    }
}
