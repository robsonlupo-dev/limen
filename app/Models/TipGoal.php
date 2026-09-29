<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta de gorjeta da performer (roadmap social, Onda 4 — §4.2). Uma ATIVA por
 * performer (garantido no TipGoalService, não por UNIQUE dura — guarda histórico).
 *
 * Estado é por `ended_at`: null = ativa. `started_at` ancora o progresso — o total
 * arrecadado conta as gorjetas com created_at >= started_at (TipGoalService). Escrita
 * só pelo serviço (forceFill); nada de mass-assignment de performer_profile_id/datas.
 */
class TipGoal extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /** Meta ainda ativa (não encerrada). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
