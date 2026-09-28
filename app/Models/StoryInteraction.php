<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Interação presa a um story (roadmap social, Onda 1b): hoje ENQUETE (poll).
 *
 * Uma por story. `type='poll'` traz `options` (as alternativas da performer);
 * outros tipos deixam `options` nulo. O que sai daqui para a performer é sempre
 * AGREGADO (distribuição), nunca "quem votou" — ver `StoryInteractionService`.
 *
 * `$fillable` restrito ao que é conteúdo validado da performer; `performer_story_id`
 * é autoridade do servidor (vem do story dela, gravado explícito no service).
 */
class StoryInteraction extends Model
{
    public const TYPE_POLL = 'poll';

    protected $fillable = [
        'type',
        'prompt',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function performerStory(): BelongsTo
    {
        return $this->belongsTo(PerformerStory::class);
    }

    /**
     * Os votos desta enquete. Existe para a contagem agregada e o expurgo —
     * **nunca** para virar lista de "quem votou" (anonimato do membro é piso).
     */
    public function votes(): HasMany
    {
        return $this->hasMany(StoryPollVote::class);
    }

    public function isPoll(): bool
    {
        return $this->type === self::TYPE_POLL;
    }
}
