<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reação rápida de um membro a um story (roadmap social, Onda 1b).
 *
 * Sinal leve membro→performer, uma por par (o índice único da migração). O que
 * sai daqui para a performer é sempre AGREGADO (faixa + emojis usados), nunca
 * "quem reagiu" — ver `StoryReactionService` e o docblock da migração.
 *
 * `$fillable` vazio de propósito: as duas FKs e o slug são autoridade do
 * servidor, gravados campo a campo no service (mesma disciplina de `StoryView`).
 * O mais perigoso seria aceitar `member_id` por payload — gravaria reação no nome
 * de outro membro e adulteraria o `DISTINCT` que sustenta a faixa.
 */
class StoryReaction extends Model
{
    protected $fillable = [];

    public function performerStory(): BelongsTo
    {
        return $this->belongsTo(PerformerStory::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
