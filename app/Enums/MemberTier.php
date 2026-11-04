<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesTier;

/**
 * Founding-members tiers for people who joined as **members**. Thresholds count
 * *same-role* referrals (member→member), but on different signals as they climb:
 * confirmed email → real registration → active buyer. Single source of truth for
 * names, thresholds and rewards. Financial rewards are capped at 24 months
 * (MAX_FINANCIAL_BENEFIT_MONTHS); social status (badges, credits) can be permanent.
 */
enum MemberTier: string
{
    use ResolvesTier;

    case Curious = 'curious';
    case Supporter = 'supporter';
    case Founder = 'founder';
    case Patron = 'patron';
    case Architect = 'architect';

    public const MAX_FINANCIAL_BENEFIT_MONTHS = 24;

    /** @return array<int, self> low → high */
    public static function ordered(): array
    {
        return [self::Curious, self::Supporter, self::Founder, self::Patron, self::Architect];
    }

    /** @return array{metric: ?string, threshold: int} */
    public function requirement(): array
    {
        return match ($this) {
            self::Curious => ['metric' => null, 'threshold' => 0],
            self::Supporter => ['metric' => 'confirmed_same', 'threshold' => 3],
            self::Founder => ['metric' => 'converted_same', 'threshold' => 1],
            self::Patron => ['metric' => 'converted_same', 'threshold' => 3],
            self::Architect => ['metric' => 'active_same', 'threshold' => 5],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Curious => 'Curioso',
            self::Supporter => 'Apoiador',
            self::Founder => 'Membro Fundador',
            self::Patron => 'Patrono',
            self::Architect => 'Arquiteto',
        };
    }

    /**
     * Benefício exibido por nível. DECISÃO DO PO (04/11/2026): metas dão STATUS
     * e vantagem, NUNCA valor em token — todo token vem exclusivamente do
     * programa de indicação real (crédito só quando a indicação converte, com
     * e-mail confirmado + hold). Não reintroduzir valores de token aqui.
     */
    public function benefit(): string
    {
        return match ($this) {
            self::Curious => 'Acesso antecipado ao lançamento',
            self::Supporter => 'Prioridade na fila de entrada',
            self::Founder => 'Selo Fundador permanente + 1 semana Explorador grátis',
            self::Patron => 'Prioridade em beta de features',
            self::Architect => 'Nome nos créditos de fundação',
        };
    }

    /** What the referrals for *this* tier must do (for "convide X pessoas…"). */
    public function requirementPhrase(): string
    {
        return match ($this) {
            self::Curious => '',
            self::Supporter => 'amigos que confirmem o e-mail',
            self::Founder, self::Patron => 'amigos que se cadastrem no lançamento',
            self::Architect => 'amigos ativos (2+ compras)',
        };
    }
}
