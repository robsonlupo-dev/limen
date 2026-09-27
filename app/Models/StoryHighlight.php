<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Coleção de destaque (Highlights) da performer (roadmap social, Onda 1a).
 * Permanente. Escrita só pelo StoryHighlightService; `performer_profile_id` é
 * autoridade (forceFill), fora do $fillable. A capa é o primeiro item.
 */
class StoryHighlight extends Model
{
    protected $fillable = ['title'];

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoryHighlightItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
