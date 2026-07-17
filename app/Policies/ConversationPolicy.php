<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Ver a conversa e suas mensagens: só os dois participantes. Não-participante
     * recebe 403 (o controller traduz para 404 quando for para não revelar a
     * existência da conversa).
     */
    public function view(User $user, Conversation $conversation): bool
    {
        $conversation->loadMissing('performerProfile');

        return $conversation->hasParticipant($user);
    }
}
