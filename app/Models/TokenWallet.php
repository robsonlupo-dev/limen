<?php

namespace App\Models;

use App\Support\TokenMath;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TokenWallet extends Model
{
    protected $fillable = ['user_id', 'balance'];

    protected function casts(): array
    {
        return [
            // pending_grant permanece inteiro (franquia é sempre inteira — PO).
            'pending_grant_tokens' => 'integer',
        ];
    }

    /**
     * DECIMAL(20,4) desde a economia decimal (19/08/2026). Contrato uniforme de
     * TODA quantidade de token no sistema: lê INT quando inteiro ("1500.0000" → 1500,
     * o caso do membro), STRING decimal quando fracionário ("4.80", carteira de
     * performer). Aritmética passa por TokenMath (aceita int|string) — nunca float.
     */
    protected function balance(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(TokenLedger::class, 'wallet_id');
    }
}
