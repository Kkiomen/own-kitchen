<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\MealPlanEntry;
use App\Pantry\Pantry;
use App\Pricing\IngredientCost;
use App\Support\Measurement\MeasureBook;
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
 *
 * **With a shop named, the week is priced in that shop.** A product it has on
 * offer costs the offer when the offer is the cheaper choice — see
 * `ShopOffers::quote()` — and everything else keeps its typical price, because
 * that shop sells it too, just not discounted. What the offers save is
 * measured against those typical prices, so it is only claimed where both
 * figures exist.
 */
final readonly class WeekCost
{
    public function __construct(
        private PlannedIngredients $planned,
        private IngredientCost $costs,
        private MeasureBook $measures,
    ) {}

    /**
     * @param  Collection<int, MealPlanEntry>  $entries
     */
    public function of(Collection $entries, Pantry $pantry, ?ShopOffers $offers = null): WeekPrice
    {
        $needs = $this->planned->of($entries);

        $eats = $this->total($needs->amounts, $offers);
        $buys = $this->total($this->planned->toBuy($needs, $pantry), $offers);

        return new WeekPrice(
            eats: $eats['money'],
            buys: $buys['money'],
            unpricedProducts: $buys['unpriced'],
            products: count($needs->amounts),
            onOffer: $buys['onOffer'],
            savings: $buys['savings'],
        );
    }

    /**
     * @param  array<int, Quantity|null>  $amounts
     * @return array{money: Money, unpriced: int, onOffer: int, savings: Money}
     */
    private function total(array $amounts, ?ShopOffers $offers): array
    {
        $money = new Money(0);
        $savings = new Money(0);
        $unpriced = 0;
        $onOffer = 0;

        foreach ($amounts as $ingredientId => $amount) {
            if ($offers?->has($ingredientId)) {
                $quote = $offers->quote($ingredientId, $amount, $this->costs, $this->measures->for($ingredientId));

                if ($quote === null) {
                    $unpriced++;

                    continue;
                }

                $money = $money->plus($quote['cost']);

                if ($quote['onOffer']) {
                    $onOffer++;
                    $savings = $savings->plus($quote['saves'] ?? new Money(0));
                }

                continue;
            }

            $cost = $this->costs->of($ingredientId, $amount);

            if ($cost === null) {
                $unpriced++;

                continue;
            }

            $money = $money->plus($cost);
        }

        return ['money' => $money, 'unpriced' => $unpriced, 'onOffer' => $onOffer, 'savings' => $savings];
    }
}
