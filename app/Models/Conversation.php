<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canal de conversa entre um membro e uma performer, aberto no desbloqueio do
 * Interesse. Ver docs/INTEREST_SYSTEM_SPEC.md §4-5.
 */
class Conversation extends Model
{
    protected $fillable = [
        'member_id',
        'performer_profile_id',
        'status',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * O usuário participa desta conversa? É o membro dono, ou o usuário dono do
     * perfil da performer. Usado pela policy e pela autorização do canal.
     */
    public function hasParticipant(User $user): bool
    {
        if ($user->id === $this->member_id) {
            return true;
        }

        return $user->id === $this->performerProfile?->user_id;
    }
}
