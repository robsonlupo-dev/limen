<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um lado premiado de uma indicação (feat/referral-program). UNIQUE(referral_id,role)
 * garante idempotência — o hold nunca credita duas vezes o mesmo lado.
 */
class ReferralReward extends Model
{
    protected $fillable = [
        'referral_id',
        'beneficiary_user_id',
        'role',
        'trigger',
        'amount',
        'ledger_id',
        'status',
        'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'rewarded_at' => 'datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }
}
