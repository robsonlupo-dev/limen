<?php

namespace App\Enums;

/**
 * Papéis de conta (coluna `users.role`). Fonte ÚNICA dos quatro valores — antes
 * espalhados como string mágica ('admin'/'moderator'/'performer'/'consumer') por
 * middleware, policy, seeder, job e controller.
 *
 * `role` NÃO é `$fillable`: quem grava é autoridade do servidor (forceFill nos
 * comandos/seed), nunca payload de request. Por isso este enum é o contrato de
 * LEITURA/comparação — não um cast na coluna. Um cast quebraria as dezenas de
 * comparações `->role === 'performer'`/`'consumer'` espalhadas pelo código (enum
 * vs string daria sempre falso). Os atalhos ficam no User (`isAdmin`,
 * `isModerator`, `canModerate`), que comparam contra `Role::Admin->value` — a
 * mesma disciplina de "gate único" do CLAUDE.md.
 *
 * Hierarquia de moderação: **admin ⊇ moderator**. `canModerate()` é o único
 * ponto que decide "quem alcança a fila /moderacao/*"; o gate admin-only usa
 * `Role::Admin` direto (KYC, payout, ban, tier, config).
 */
enum Role: string
{
    case Consumer = 'consumer';
    case Performer = 'performer';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /**
     * Alcança a fila de moderação (/moderacao/*). O admin herda tudo do
     * moderador; ninguém mais entra. Fonte única da regra "quem pode moderar".
     */
    public function canModerate(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }
}
