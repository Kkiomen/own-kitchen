<?php

declare(strict_types=1);

namespace App\Shopping\Planning\Strategies;

use App\Models\Promotion;
use App\Models\ShoppingListItem;
use App\Shopping\Planning\Contracts\PlanStrategy;

/**
 * Catch as much of the list as possible without driving all afternoon.
 *
 * The objective is coverage first, price second: someone who asks for two shops
 * wants most of the list on offer, not eleven groszy off the butter. Ties are
 * broken by what those items would cost, so between two shops covering the same
 * six things the cheaper one wins.
 *
 * The choice of shops is greedy — take the shop covering the most that is still
 * uncovered, then the next — and that is deliberate. Picking the genuinely best
 * pair is set cover, which is NP-hard; on a list of twenty products across
 * thirteen chains the greedy answer is either optimal or a line or two off it,
 * and it is explainable to the person reading the screen.
 */
final class FewestStops implements PlanStrategy
{
    public function __construct(private readonly int $maxStops = 2) {}

    public function key(): string
    {
        return 'najmniej-sklepow';
    }

    public function label(): string
    {
        return "Maksymalnie {$this->maxStops} sklepy";
    }

    /**
     * @param  list<ShoppingListItem>  $items
     * @param  array<int, list<Promotion>>  $offers
     * @return array<int, Promotion>
     */
    public function choose(array $items, array $offers): array
    {
        $bestPerShop = $this->bestPerShop($items, $offers);
        $shops = $this->pickShops($bestPerShop);
        $chosen = [];

        foreach ($items as $item) {
            foreach ($offers[$item->ingredient_id] ?? [] as $promotion) {
                // Ranked best first, so the first offer standing in a shop we are
                // already visiting is the best one available on this trip.
                if (in_array($promotion->shop_id, $shops, true)) {
                    $chosen[$item->id] = $promotion;

                    break;
                }
            }
        }

        return $chosen;
    }

    /**
     * The best offer each shop has for each item: shop id => item id => promotion.
     *
     * @param  list<ShoppingListItem>  $items
     * @param  array<int, list<Promotion>>  $offers
     * @return array<int, array<int, Promotion>>
     */
    private function bestPerShop(array $items, array $offers): array
    {
        $byShop = [];

        foreach ($items as $item) {
            foreach ($offers[$item->ingredient_id] ?? [] as $promotion) {
                // First wins: the list is already in best-first order, so a later
                // offer from the same shop is by definition the worse one.
                $byShop[$promotion->shop_id][$item->id] ??= $promotion;
            }
        }

        return $byShop;
    }

    /**
     * @param  array<int, array<int, Promotion>>  $bestPerShop
     * @return list<int>
     */
    private function pickShops(array $bestPerShop): array
    {
        $chosen = [];
        $covered = [];

        while (count($chosen) < $this->maxStops) {
            $winner = null;
            $winnerScore = null;

            foreach ($bestPerShop as $shopId => $promotions) {
                if (in_array($shopId, $chosen, true)) {
                    continue;
                }

                $fresh = array_diff_key($promotions, $covered);

                if ($fresh === []) {
                    continue;
                }

                // More products first, then the cheaper basket of them. Negated
                // count so one comparison sorts both, ascending.
                $score = [-count($fresh), $this->costOf($fresh)];

                if ($winnerScore === null || $score < $winnerScore) {
                    $winner = $shopId;
                    $winnerScore = $score;
                }
            }

            if ($winner === null) {
                break;
            }

            $chosen[] = $winner;
            $covered += $bestPerShop[$winner];
        }

        return $chosen;
    }

    /**
     * @param  array<int, Promotion>  $promotions
     */
    private function costOf(array $promotions): int
    {
        return array_sum(array_map(static fn (Promotion $promotion): int => $promotion->price_minor, $promotions));
    }
}
