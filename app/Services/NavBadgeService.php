<?php

namespace App\Services;

use App\Models\User;

/**
 * Contadores de "não vistos" da navegação (feat/activity-badges) — as bolinhas
 * com número ao lado de "Mensagens" e "Interessadas", no estilo do "52" do
 * Seeking. Dona única do QUE cada papel vê:
 *
 *  - MEMBRO: mensagens não lidas (legíveis) + corações recebidos não vistos.
 *  - PERFORMER: mensagens não lidas (as interações que chegam DOS membros — o
 *    coração é performer→membro, então não há "coração recebido" do lado dela).
 *  - ADMIN/moderador: nada (sem essas superfícies).
 *
 * Cada número reusa a regra que já tem dona:
 *  - mensagens: ChatService::unreadCountFor (respeita o paywall do chat);
 *  - corações: PerformerHeartService::unseenCountForMember (watermark hearts_seen_at).
 *
 * NUNCA carimbo, sempre CONTAGEM: o front recebe inteiros, nunca a data do último
 * evento — mesma disciplina do resto do projeto. Consumido por um prop LAZY do
 * HandleInertiaRequests (avaliado no render, depois do controller), então abrir a
 * seção que zera o watermark já reflete zero na mesma resposta.
 */
class NavBadgeService
{
    public function __construct(
        private ChatService $chat,
        private PerformerHeartService $hearts,
    ) {}

    /**
     * @return array{messages: int, hearts: int}
     */
    public function for(User $user): array
    {
        return [
            'messages' => $this->chat->unreadCountFor($user),
            // Coração é performer→membro: só o membro tem "recebidos". A performer
            // (e admin/moderador) sempre 0 aqui.
            'hearts' => $user->role === 'consumer'
                ? $this->hearts->unseenCountForMember($user)
                : 0,
        ];
    }
}
