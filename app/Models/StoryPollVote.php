<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voto de um membro numa enquete de story (roadmap social, Onda 1b).
 *
 * Um por par (o índice único da migração), imutável. `$fillable` vazio: as FKs e o
 * índice da opção são autoridade do servidor, gravados campo a campo no service
 * (mesma disciplina de `StoryView`/`StoryReaction`). Aceitar `member_id` por
 * payload gravaria voto no nome de outro membro e adulteraria a distribuição.
 */
class StoryPollVote extends Model
{
    protected $fillable = [];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(StoryInteraction::class, 'story_interaction_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
