<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\MealPlanEntry;
use App\Pantry\Pantry;
use App\Pricing\IngredientCost;
use App\Support\Measurement\Quantity;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * What a planned week costs — both of the two numbers that deserve the name.
 *
 * **What it eats** is everything the week's recipes call for, whether or not the
 * kitchen already holds it. It is the figure that can be compared with last
 * week's, because it does not move when somebody happens to have a full
 * cupboard.
 *
 * **What it buys** is the same week after the kitchen has been subtracted: the
 * money that actually leaves the household this week. It is the one a budget is
 * set against, because it is the one somebody hands over at the till.
 *
 * Neither is a guess about the other. A full cupboard makes the second much
 * smaller than the first and that is not an error — it is the whole reason for
 * keeping both.
 *
 * The subtraction is `PlannedIngredients::toBuy()` and nothing else: it is
 * already the one place that knows how to take a kitchen off a week, including
 * the rule that "cannot tell" leaves a shelf trusted. Re-deriving it here would
 * be a second opinion that could only disagree.
 *
 * **The unpriced are counted, never assumed free.** Roughly a third of what a
 * week calls for has no price behind it today, and treating those as zero would
 * be wrong in the direction that costs money and invisible while doing it — the
 * total would simply come out too small. So the count travels with the money and
 * the screen says it out loud.
 */
final readonly class WeekCost
{
    public function __construct(
        private PlannedIngredients $planned,
        private IngredientCost $costs,
    ) {}

    /**
     * @param  Collection<int, MealPlanEntry>  $entries
     */
    public function of(Collection $entries, Pantry $pantry): WeekPrice
    {
        $needs = $this->planned->of($entries);

        $eats = $this->total($needs->amounts);
        $buys = $this->total($this->planned->toBuy($needs, $pantry));

        return new WeekPrice(
            eats: $eats['money'],
            buys: $buys['money'],
            unpricedProducts: $buys['unpriced'],
            products: count($needs->amounts),
        );
    }

    /**
     * @param  array<int, Quantity|null>  $amounts
     * @return array{money: Money, unpriced: int}
     */
    private function total(array $amounts): array
    {
        $money = new Money(0);
        $unpriced = 0;

        foreach ($amounts as $ingredientId => $amount) {
            $cost = $this->costs->of($ingredientId, $amount);

            if ($cost === null) {
                $unpriced++;

                continue;
            }

            $money = $money->plus($cost);
        }

        return ['money' => $money, 'unpriced' => $unpriced];
    }
}
