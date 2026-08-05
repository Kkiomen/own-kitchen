<?php

declare(strict_types=1);

namespace App\Offers\Quality;

/**
 * What the leaflet import currently understands, as numbers that can be compared
 * between runs. The report exists for the same reason the recipe one does: these
 * were once worked out by hand in a scratch file, which is neither repeatable nor
 * comparable.
 */
final readonly class PromotionQualitySnapshot
{
    /**
     * @param  array<string, int>  $perShop  shop name => offers
     * @param  array<string, int>  $topMatches  product name => offers matched to it
     * @param  list<string>  $unmatched  leaflet entries nothing was matched to
     */
    public function __construct(
        public int $promotions,
        public int $shops,
        public int $matched,
        public int $withPackSize,
        public int $withUnitPrice,
        public int $withRegularPrice,
        public int $expired,
        public array $perShop,
        public array $topMatches,
        public array $unmatched,
    ) {}

    public function percentOf(int $count): float
    {
        return $this->promotions === 0 ? 0.0 : round($count / $this->promotions * 100, 1);
    }

    public function matchPercent(): float
    {
        return $this->percentOf($this->matched);
    }
}
