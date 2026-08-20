<?php

namespace App\Models;

use App\Support\TokenMath;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenLedger extends Model
{
    const UPDATED_AT = null;

    protected $table = 'token_ledger';

    protected $fillable = [
        'wallet_id', 'entry_type', 'amount', 'applied_rate', 'balance_after',
        'reference_type', 'reference_id', 'description',
    ];

    protected function casts(): array
    {
        return [
            // applied_rate segue inteiro (a taxa congelada é 70/75/80/100).
            'applied_rate' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    // amount/balance_after são DECIMAL(20,4) desde a economia decimal (19/08/2026).
    // Contrato uniforme: INT quando inteiro ("-200.0000" → -200), STRING decimal
    // quando fracionário ("1.6000"). A soma DB (sum('amount')) ignora o accessor e
    // volta decimal — normalize por TokenMath::of no chamador.
    protected function amount(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
    }

    protected function balanceAfter(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
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
