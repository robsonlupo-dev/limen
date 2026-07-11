<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistReferral extends Model
{
    // converted_at is deliberately not fillable: it is set only by direct
    // assignment in WaitlistService::convert(), never from mass-assignment.
    protected $fillable = [
        'referrer_id', 'referred_id', 'confirmed', 'referred_ip_hash', 'referral_type',
    ];

    protected $casts = [
        'confirmed' => 'boolean',
        'converted_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'referred_id');
    }
}
