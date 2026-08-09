<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contador diário de mensagens grátis da performer. Ver a migration e
 * ChatService::sendCatalogMessage. Uma linha por (performer, dia).
 */
class PerformerMessageQuota extends Model
{
    protected $fillable = [
        'performer_profile_id',
        'quota_date',
        'sent_count',
    ];

    protected function casts(): array
    {
        return [
            'quota_date' => 'date',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }
}
