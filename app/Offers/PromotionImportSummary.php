<?php

declare(strict_types=1);

namespace App\Offers;

/**
 * What one refresh of the leaflets did. `matched` is the number that matters:
 * an offer nobody can tie to a product cannot end up on a shopping plan.
 */
final class PromotionImportSummary
{
    public function __construct(
        public int $stored = 0,
        public int $matched = 0,
        public int $pruned = 0,
        public int $failed = 0,
    ) {}

    public function matchRate(): float
    {
        return $this->stored === 0 ? 0.0 : round($this->matched / $this->stored * 100, 1);
    }
}
