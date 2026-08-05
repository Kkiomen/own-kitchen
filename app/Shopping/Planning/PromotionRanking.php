<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Enums\UnitDimension;
use App\Models\Promotion;

/**
 * Puts the offers for one product in order, best first.
 *
 * The ordering rule is the whole argument of this feature, so it is written out
 * rather than left in a comparator:
 *
 * 1. Offers priced per kilo (or litre, or piece) come first, cheapest first.
 *    This is the only comparison that is actually true.
 * 2. Offers whose pack size we never learned come next, cheapest pack first.
 *    They are not demoted because they are bad deals — they are demoted because
 *    we cannot show they are good ones, and a plan that says "drive here" has to
 *    be able to defend itself.
 * 3. Offers priced per a different dimension come last. Butter sold by the piece
 *    and butter sold by the kilo are both real, and neither number tells you
 *    anything about the other.
 *
 * They stay in the list either way. The screen shows the runners-up, because the
 * cook standing in the shop knows the pack size we did not.
 */
final class PromotionRanking
{
    /**
     * @param  list<Promotion>  $promotions  all offers for one product
     * @return list<Promotion>
     */
    public function sort(array $promotions): array
    {
        $main = $this->dominantDimension($promotions);

        usort($promotions, function (Promotion $a, Promotion $b) use ($main): int {
            return [$this->tier($a, $main), $this->cost($a, $main)]
                <=> [$this->tier($b, $main), $this->cost($b, $main)];
        });

        return $promotions;
    }

    /**
     * The dimension most of this product's offers are priced in. Chosen by count
     * rather than by the ingredient's default unit: what the shops actually sell
     * it by beats what a recipe happens to measure it in.
     *
     * @param  list<Promotion>  $promotions
     */
    private function dominantDimension(array $promotions): ?UnitDimension
    {
        $counts = [];

        foreach ($promotions as $promotion) {
            if ($promotion->unit_price_per !== null) {
                $counts[$promotion->unit_price_per->value] = ($counts[$promotion->unit_price_per->value] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return UnitDimension::from((string) array_key_first($counts));
    }

    private function tier(Promotion $promotion, ?UnitDimension $main): int
    {
        if ($promotion->unit_price_per === null) {
            return 1;
        }

        return $promotion->unit_price_per === $main ? 0 : 2;
    }

    private function cost(Promotion $promotion, ?UnitDimension $main): int
    {
        return $promotion->unit_price_per === $main && $promotion->unit_price_minor !== null
            ? $promotion->unit_price_minor
            : $promotion->price_minor;
    }
}
