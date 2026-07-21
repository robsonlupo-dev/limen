<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de conformidade do direito de eliminação (LGPD art. 18, VI).
 * Ver a migration para por que `data_summary` só carrega contagens.
 */
class DeletionLog extends Model
{
    protected $fillable = [
        'user_id', 'requested_at', 'executed_at', 'reason', 'data_summary',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'executed_at' => 'datetime',
            'data_summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
