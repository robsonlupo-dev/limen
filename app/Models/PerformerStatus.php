<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Status do dia da performer (roadmap social, Onda 1a). Um por performer.
 *
 * Estado é POR TEMPO, não por flag: `isExpired()` e o escopo `active()` olham
 * `expires_at` — a expiração vale na LEITURA, igual a story/foto efêmera. Escrita
 * só pelo PerformerStatusService (upsert); `expires_at` é DERIVADO do config, nunca
 * de payload.
 */
class PerformerStatus extends Model
{
    protected $fillable = [
        'body',
        'countdown_at',
        'countdown_label',
    ];

    protected function casts(): array
    {
        return [
            'countdown_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /** Status ainda válido pelo relógio. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
