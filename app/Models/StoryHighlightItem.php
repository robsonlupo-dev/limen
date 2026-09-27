<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de um destaque (roadmap social, Onda 1a). Guarda a própria cópia
 * permanente da mídia. `media_path`/`content_hash` são prova/layout de disco —
 * nunca saem em serialização. Escrita só pelo StoryHighlightService.
 */
class StoryHighlightItem extends Model
{
    protected $fillable = ['visibility_level'];

    protected $hidden = ['media_path', 'content_hash'];

    public function highlight(): BelongsTo
    {
        return $this->belongsTo(StoryHighlight::class, 'story_highlight_id');
    }
}
