<?php

namespace App\Enums\Concerns;

/**
 * Shared resolution logic for the role-specific tier enums. Each enum supplies
 * ordered() (cases low→high) and requirement() (the metric + threshold that
 * unlocks each case); this trait turns a metrics array into the reached tier and
 * walks to the next one. Metrics are keyed by:
 *   confirmed_same, confirmed_cross, converted_same, converted_cross,
 *   active_same, active_cross.
 */
trait ResolvesTier
{
    /** Highest tier whose requirement the metrics satisfy. */
    public static function forMetrics(array $metrics): static
    {
        foreach (array_reverse(static::ordered()) as $tier) {
            $req = $tier->requirement();

            if ($req['metric'] === null || (int) ($metrics[$req['metric']] ?? 0) >= (int) $req['threshold']) {
                return $tier;
            }
        }

        return static::ordered()[0];
    }

    /** The next tier up, or null at the top. */
    public function next(): ?static
    {
        $ordered = static::ordered();
        $index = array_search($this, $ordered, true);

        return $ordered[$index + 1] ?? null;
    }
}
