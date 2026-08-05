<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Shopping\Planning\Contracts\PlanStrategy;
use App\Shopping\Planning\Strategies\CheapestOverall;
use App\Shopping\Planning\Strategies\FewestStops;

/**
 * The ways of reading a plan that the screen offers, in the order it offers them.
 * A new rule is a new class plus a line here.
 */
final class PlanStrategies
{
    /**
     * @return list<PlanStrategy>
     */
    public function all(): array
    {
        return [
            new CheapestOverall,
            new FewestStops(2),
        ];
    }

    /**
     * Falls back to the first rather than failing: the key arrives from a query
     * string, and a stale bookmark should show a plan, not an error page.
     */
    public function get(?string $key): PlanStrategy
    {
        foreach ($this->all() as $strategy) {
            if ($strategy->key() === $key) {
                return $strategy;
            }
        }

        return $this->all()[0];
    }
}
