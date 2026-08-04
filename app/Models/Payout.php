<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'performer_id', 'tokens', 'amount_brl', 'pix_key', 'pix_key_type',
        'status', 'period_year', 'period_month', 'asaas_transfer_id', 'failure_reason',
        'requested_at', 'processed_at', 'unresolved_since',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'amount_brl' => 'decimal:2',
            'pix_key' => 'encrypted',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'unresolved_since' => 'datetime',
        ];
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_id');
    }
}
