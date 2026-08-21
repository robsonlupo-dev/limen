<?php

namespace App\Support;

/**
 * Tradução ÚNICA de cada tipo de lançamento do ledger para linguagem de usuário
 * (feat/content-showcase). O nome de banco (`spend_content`, `chat_access_credit`)
 * NUNCA chega à tela — nem no histórico da carteira do membro, nem no extrato da
 * performer. Dona única: superfície nova que mostrar lançamento pergunta aqui.
 *
 * O rótulo é do ponto de vista de quem VÊ aquele tipo: o membro vê os próprios
 * gastos/compras; a performer vê os créditos que recebeu. Cada tipo só aparece de um
 * lado, então um rótulo por tipo basta. Tipos legados (sessão privada, câmera) e de
 * seed também têm rótulo — o enum inteiro é coberto, nada sai cru.
 */
class LedgerEntryLabel
{
    /** entry_type → rótulo em português claro. */
    private const LABELS = [
        // Entradas do membro
        'purchase' => 'Compra de tokens',
        'bonus' => 'Bônus',
        'subscription_grant' => 'Tokens da assinatura',
        'refund' => 'Reembolso',
        'adjustment' => 'Ajuste',
        'staging_seed_backfill' => 'Ajuste inicial',

        // Gastos do membro
        'spend_tip' => 'Gorjeta enviada',
        'spend_content' => 'Conteúdo desbloqueado',
        'spend_chat_access' => 'Abertura de conversa',
        'spend_gift' => 'Presente enviado',
        'spend_boost' => 'Destaque no catálogo',
        'spend_interest_unlock' => 'Interesse revelado',
        'spend_live' => 'Live assistida',
        'spend_call' => 'Chamada privada',
        'spend_call_reservation' => 'Reserva de chamada',
        'call_reservation_refund' => 'Reembolso de reserva',
        'spend_private' => 'Sessão privada',
        'spend_camera' => 'Câmera',

        // Ganhos da performer
        'tip_credit' => 'Gorjeta recebida',
        'chat_access_credit' => 'Conversa recebida',
        'content_credit' => 'Conteúdo vendido',
        'gift_credit' => 'Presente recebido',
        'call_credit' => 'Chamada recebida',
        'call_noshow_credit' => 'Compensação por falta',
        'live_credit' => 'Live recebida',

        // Saque da performer
        'payout_reserve' => 'Reserva de saque',
        'payout_reversal' => 'Estorno de saque',
    ];

    /**
     * Rótulo de usuário do tipo. Tipo desconhecido cai num genérico ("Movimento") —
     * NUNCA devolve o nome cru de banco (senão um enum novo vazaria antes de ganhar
     * rótulo aqui; `labelsTest` cobre o enum atual para isso não passar batido).
     */
    public static function for(string $entryType): string
    {
        return self::LABELS[$entryType] ?? 'Movimento';
    }

    /** Todos os rótulos conhecidos (para teste de cobertura do enum). */
    public static function all(): array
    {
        return self::LABELS;
    }
}
