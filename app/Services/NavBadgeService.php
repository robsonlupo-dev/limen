<?php

namespace App\Services;

use App\Models\CallReservation;
use App\Models\CustomOrder;
use App\Models\MemberPhotoAccess;
use App\Models\PhotoGrant;
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
        private BroadcastService $broadcasts,
    ) {}

    /**
     * @return array{messages: int, hearts: int, channels: int, custom_orders: int, access_requests: int, received_photos: int, reservations: int}
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
            // Canais (Onda 2): broadcasts não vistos das performers que o membro
            // segue. Só o membro tem canais; performer/admin sempre 0.
            'channels' => $user->role === 'consumer'
                ? $this->broadcasts->unseenCountForMember($user)
                : 0,
            // Encomendas sob medida (Onda 4 §4.3) que pedem AÇÃO agora: a performer
            // vê os pedidos novos (requested, precisa aceitar/recusar); o membro vê as
            // entregas (delivered, precisa aprovar/relatar). CONTAGEM, nunca carimbo.
            'custom_orders' => $this->pendingCustomOrders($user),
            // Pendências do PAINEL da performer (reorg do Painel, UAT Fase 21): o
            // que pede AÇÃO dela e ficava enterrado no fim da rolagem. A nav soma as
            // três no badge do "Painel"; "Pessoas" mostra só as solicitações. Cada
            // contagem reusa a regra que já tem dona (as MESMAS queries do painel e
            // do PhotoAccessService), só que como count — nunca o conteúdo.
            'access_requests' => $this->pendingAccessRequests($user),
            'received_photos' => $this->unseenReceivedPhotos($user),
            'reservations' => $this->pendingReservations($user),
        ];
    }

    /**
     * Pedidos PENDENTES para as fotos PRIVADAS dela — a contagem do que
     * `PhotoAccessService::pendingRequestsFor()` lista. Só performer.
     */
    private function pendingAccessRequests(User $user): int
    {
        $profileId = $this->performerProfileId($user);

        return $profileId === null
            ? 0
            : PhotoGrant::pending()
                ->whereHas('photo', fn ($q) => $q
                    ->where('performer_profile_id', $profileId)
                    ->where('is_private', true))
                ->count();
    }

    /**
     * Fotos que membros compartilharam com ela, dentro do prazo e que ela AINDA
     * NÃO abriu (`viewed_at` nulo) — é isso que "precisa dela". Mesma regra de
     * "ativa" do painel (`expires_at` futuro e a foto ainda existe).
     */
    private function unseenReceivedPhotos(User $user): int
    {
        $profileId = $this->performerProfileId($user);

        return $profileId === null
            ? 0
            : MemberPhotoAccess::query()
                ->where('performer_profile_id', $profileId)
                ->where('expires_at', '>', now())
                ->whereNull('viewed_at')
                ->whereHas('photo')
                ->count();
    }

    /**
     * Agendamentos aguardando a CONFIRMAÇÃO dela. Só conta com a flag de chamada
     * ligada (dark launch): com ela desligada a tela de agendamentos nem existe, e
     * um badge apontando para o nada seria pior que nenhum.
     */
    private function pendingReservations(User $user): int
    {
        if (! config('features.call_enabled')) {
            return 0;
        }

        $profileId = $this->performerProfileId($user);

        return $profileId === null
            ? 0
            : CallReservation::query()
                ->where('performer_profile_id', $profileId)
                ->where('status', CallReservation::STATUS_PENDING)
                ->count();
    }

    /** Id do perfil se o usuário é performer com perfil; null para os demais papéis. */
    private function performerProfileId(User $user): ?int
    {
        return $user->role === 'performer' ? $user->performerProfile?->id : null;
    }

    /** Encomendas que aguardam ação do usuário (por papel). 0 para admin/moderador. */
    private function pendingCustomOrders(User $user): int
    {
        if ($user->role === 'performer') {
            $profileId = $user->performerProfile?->id;

            // Precisa da ação DELA: pedido novo (aceitar/recusar) OU aceito (entregar).
            return $profileId === null
                ? 0
                : CustomOrder::where('performer_profile_id', $profileId)
                    ->whereIn('status', [CustomOrder::STATUS_REQUESTED, CustomOrder::STATUS_ACCEPTED])
                    ->count();
        }

        if ($user->role === 'consumer') {
            return CustomOrder::where('member_id', $user->id)
                ->where('status', CustomOrder::STATUS_DELIVERED)
                ->count();
        }

        return 0;
    }
}
