<?php

namespace App\Enums;

/**
 * Founding-members tiers, unlocked by the number of *confirmed* referrals.
 * The tier of an entry is always derived from its referral_count (see
 * WaitlistEntryObserver), so this enum is the single source of truth for the
 * thresholds, names and rewards.
 */
enum WaitlistTier: string
{
    case Curious = 'curious';
    case Supporter = 'supporter';
    case Founder = 'founder';
    case Ambassador = 'ambassador';
    case Elite = 'elite';
    case Architect = 'architect';

    /** Confirmed referrals required to reach this tier. */
    public function threshold(): int
    {
        return match ($this) {
            self::Curious => 0,
            self::Supporter => 3,
            self::Founder => 5,
            self::Ambassador => 10,
            self::Elite => 20,
            self::Architect => 50,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Curious => 'Curioso',
            self::Supporter => 'Apoiador',
            self::Founder => 'Fundador',
            self::Ambassador => 'Embaixador',
            self::Elite => 'Fundador Elite',
            self::Architect => 'Arquiteto',
        };
    }

    /** The reward unlocked at this tier. */
    public function benefit(): string
    {
        return match ($this) {
            self::Curious => 'Você está na lista de espera.',
            self::Supporter => '+50 tokens no lançamento',
            self::Founder => 'Selo de Fundador permanente + acesso VIP',
            self::Ambassador => 'Taxa reduzida por 3 meses',
            self::Elite => 'Perfil em destaque por 30 dias',
            self::Architect => 'Nome nos créditos + benefícios vitalícios',
        };
    }

    /** Tiers in ascending threshold order. */
    public static function ordered(): array
    {
        return [
            self::Curious, self::Supporter, self::Founder,
            self::Ambassador, self::Elite, self::Architect,
        ];
    }

    /** The highest tier reached with the given confirmed-referral count. */
    public static function forCount(int $count): self
    {
        $reached = self::Curious;

        foreach (self::ordered() as $tier) {
            if ($count >= $tier->threshold()) {
                $reached = $tier;
            }
        }

        return $reached;
    }

    /** The next tier up, or null if already at the top. */
    public function next(): ?self
    {
        $ordered = self::ordered();
        $i = array_search($this, $ordered, true);

        return $ordered[$i + 1] ?? null;
    }
}
