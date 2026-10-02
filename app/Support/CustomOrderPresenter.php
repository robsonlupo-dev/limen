<?php

namespace App\Support;

use App\Models\CustomOrder;
use App\Models\PerformerContent;

/**
 * Apresentação da encomenda sob medida (Onda 4 §4.3) para cada lado. O membro vê a
 * performer (perfil público) e a peça entregue quando pode ver; a performer vê o membro
 * SÓ por FanAlias (M.13.10) — nunca member_id/nome. Estado + ações derivam do status.
 */
class CustomOrderPresenter
{
    /** @return array<string, mixed> */
    public static function forMember(CustomOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'description' => $order->description,
            'price' => (int) $order->offered_price_tokens,
            'performer' => [
                'stage_name' => $order->performerProfile?->stage_name,
                'slug' => $order->performerProfile?->slug,
            ],
            'delivered' => self::deliveredMedia($order),
            // Recado da performer na entrega (quando houver). Texto já filtrado na porta.
            'delivery_message' => $order->delivery_message,
            'dispute_deadline_at' => $order->dispute_deadline_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            // Ações que o MEMBRO pode tomar agora.
            'can_cancel' => $order->isRequested(),
            // Só aprova o que dá para ver: vídeo em processamento/falho não libera
            // escrow (o service reforça; o cron estorna a mídia não-pronta na janela).
            'can_approve' => $order->isDelivered() && $order->deliveredIsReady(),
            'can_dispute' => $order->isDelivered()
                && ($order->dispute_deadline_at === null || now()->lessThanOrEqualTo($order->dispute_deadline_at)),
        ];
    }

    /** @return array<string, mixed> */
    public static function forPerformer(CustomOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'description' => $order->description,
            'price' => (int) $order->offered_price_tokens,
            // O outro lado é o membro — só FanAlias, nunca id/nome reais.
            'fan' => FanAlias::label($order->performer_profile_id, (int) $order->member_id),
            'delivered' => self::deliveredMediaForOwner($order),
            // O próprio recado que ela escreveu na entrega (para ela reler).
            'delivery_message' => $order->delivery_message,
            'dispute_deadline_at' => $order->dispute_deadline_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            // Ações que a PERFORMER pode tomar agora.
            'can_accept' => $order->isRequested(),
            'can_decline' => $order->isRequested(),
            'can_deliver' => $order->isAccepted(),
        ];
    }

    /**
     * Projeção para a MODERAÇÃO de uma disputa (Onda 4 §4.3). O moderador decide
     * release/refund e por isso vê os dois lados de forma pseudonimizada: a performer
     * pela vitrine pública (stage_name) e o membro SÓ por FanAlias — nunca member_id/
     * nome/e-mail, mesma disciplina da fila de denúncias. Sem a URL da mídia: a prova
     * é servida pelos endpoints dedicados de moderação, nunca embutida na prop.
     *
     * @return array<string, mixed>
     */
    public static function forModeration(CustomOrder $order): array
    {
        $piece = $order->deliveredContent;

        return [
            'id' => $order->id,
            'status' => $order->status,
            'description' => $order->description,
            'price' => (int) $order->offered_price_tokens,
            'performer' => [
                'stage_name' => $order->performerProfile?->stage_name,
                'slug' => $order->performerProfile?->slug,
            ],
            'fan' => FanAlias::label($order->performer_profile_id, (int) $order->member_id),
            'delivered' => $piece === null ? null : [
                'kind' => $piece->isVideo() ? 'video' : 'photo',
                'status' => $piece->status,
            ],
            // As duas falas que o moderador precisa para decidir a disputa com justiça: o
            // recado da performer na entrega e o MOTIVO que o membro deu ao contestar.
            'delivery_message' => $order->delivery_message,
            'dispute_reason' => $order->dispute_reason,
            'dispute_deadline_at' => $order->dispute_deadline_at?->toIso8601String(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Bloco da mídia entregue (pronta). URL só quando a peça existe e está READY; o
     * serving (content.image/video) re-checa o acesso pelo ContentVisibilityService, então
     * a URL só entrega bytes a quem pode ver (o membro dono do unlock, ou a performer).
     *
     * @return array<string, mixed>|null
     */
    private static function deliveredMedia(CustomOrder $order): ?array
    {
        $piece = $order->deliveredContent;
        if ($piece === null) {
            return null;
        }

        $isVideo = $piece->isVideo();

        return [
            'kind' => $isVideo ? 'video' : 'photo',
            'status' => $piece->status,
            'url' => $piece->isReady()
                ? ($isVideo ? route('content.video', $piece->id) : route('content.image', $piece->id))
                : null,
        ];
    }

    /**
     * Mídia entregue vista pela PERFORMER (dona). Usa a rota da dona
     * (`performer.content.image`) — a rota do membro (`content.image`) vive num grupo
     * role:consumer e recusaria a performer. Não há stream de vídeo da dona, então para
     * vídeo servimos o PÔSTER (a mesma rota devolve o thumbnail): serve de conferência
     * ("é essa peça"), sem playback. A performer já viu o vídeo inteiro na prévia do envio.
     *
     * @return array<string, mixed>|null
     */
    private static function deliveredMediaForOwner(CustomOrder $order): ?array
    {
        $piece = $order->deliveredContent;
        if ($piece === null) {
            return null;
        }

        return [
            'kind' => $piece->isVideo() ? 'video' : 'photo',
            'status' => $piece->status,
            // Foto ou pôster do vídeo — sempre uma imagem, pela rota da dona.
            'poster' => $piece->isReady() ? route('performer.content.image', $piece->id) : null,
        ];
    }
}
