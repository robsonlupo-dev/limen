<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesTier;

/**
 * Founding-members tiers for people who joined as **performers**. Progression
 * mixes signals: pioneer/founder count *same-role* confirmed performers they
 * referred; ambassador/patron count *cross-role* members who registered for
 * real. Single source of truth for names, thresholds and rewards. Financial
 * rewards are capped at 24 months (MAX_FINANCIAL_BENEFIT_MONTHS); social status
 * can be permanent.
 */
enum PerformerTier: string
{
    use ResolvesTier;

    case Candidate = 'candidate';
    case Pioneer = 'pioneer';
    case Founder = 'founder';
    case Ambassador = 'ambassador';
    case Patron = 'patron';

    public const MAX_FINANCIAL_BENEFIT_MONTHS = 24;

    /** @return array<int, self> low → high */
    public static function ordered(): array
    {
        return [self::Candidate, self::Pioneer, self::Founder, self::Ambassador, self::Patron];
    }

    /** @return array{metric: ?string, threshold: int} */
    public function requirement(): array
    {
        return match ($this) {
            self::Candidate => ['metric' => null, 'threshold' => 0],
            self::Pioneer => ['metric' => 'confirmed_same', 'threshold' => 2],
            self::Founder => ['metric' => 'confirmed_same', 'threshold' => 5],
            self::Ambassador => ['metric' => 'converted_cross', 'threshold' => 1],
            self::Patron => ['metric' => 'converted_cross', 'threshold' => 5],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Candidate => 'Candidata',
            self::Pioneer => 'Pioneira',
            self::Founder => 'Fundadora',
            self::Ambassador => 'Embaixadora',
            self::Patron => 'Patrona',
        };
    }

    public function benefit(): string
    {
        return match ($this) {
            self::Candidate => 'Onboarding VIP + suporte direto',
            self::Pioneer => 'Destaque 7 dias no catálogo no lançamento',
            self::Founder => 'Selo Fundadora permanente + posição prioritária no catálogo',
            self::Ambassador => 'Taxa 18% por 6 meses (vs 20% padrão)',
            self::Patron => 'Taxa 15% por 12 meses + destaque 30 dias no catálogo',
        };
    }

    /** What the referrals for *this* tier must do (for "convide X pessoas…"). */
    public function requirementPhrase(): string
    {
        return match ($this) {
            self::Candidate => '',
            self::Pioneer, self::Founder => 'performers que confirmem o e-mail',
            self::Ambassador, self::Patron => 'membros que se cadastrem no lançamento',
        };
    }
}
