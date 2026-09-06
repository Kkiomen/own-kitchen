<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\UnitDimension;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;
use App\Support\Money\Money;
use App\Support\Money\UnitPrice;

/**
 * What this much of this product typically costs.
 *
 * Extracted from `CostEstimate` when the meal planner needed the same answer
 * about a recipe line rather than a shopping-list row. Two copies of "typical
 * cost of an amount" would drift the first time one of them learned something —
 * and they would drift *quietly*, so a week could be planned to 340 zł and the
 * shopping list for that same week could price itself at 380.
 *
 * The two layers here are the two that are about a product and an amount, and
 * nothing else. A promotion is deliberately **not** among them: an offer is a
 * fact about a shop somebody is driving to this week, which is a question the
 * shopping list can ask and a week's menu cannot — plan a fortnight of meals
 * around this Thursday's leaflet and half of it is wrong by the time you cook it.
 *
 * 1. **A price per kilo/litre/piece × the amount**, when the line states one and
 *    the amount can reach the dimension the price is quoted in.
 * 2. **A typical pack price**, which is what most products actually have.
 * 3. **Null** — and null means *say nothing*, never zero. A line treated as free
 *    is wrong in the direction that costs money and invisible while it does it.
 */
final readonly class IngredientCost
{
    public function __construct(
        private PriceBook $prices,
        private MeasureBook $measures,
    ) {}

    /**
     * Null when nothing recent enough prices this product at all.
     */
    public function of(int $ingredientId, ?Quantity $wanted): ?Money
    {
        return $this->byAmount($ingredientId, $wanted)
            ?? $this->prices->packPriceFor($ingredientId);
    }

    /**
     * Whether this product can be priced in any sense at all — used to count
     * what a plan had to leave out rather than to price it.
     */
    public function knows(int $ingredientId): bool
    {
        return $this->prices->packPriceFor($ingredientId) !== null;
    }

    /**
     * The amount on the line, priced per kilo — the first layer on its own.
     *
     * Public because a caller that has to *say* which layer answered cannot get
     * that from the total: a pack price and a computed one can be the same
     * number by coincidence, and the shopping list marks the rough one with a
     * "~". Asking for the layer directly is the only way to know.
     *
     * Two conversions have to line up and either may fail. The line's unit and
     * the price's dimension often differ — "2 cebule" against a price per kilo —
     * so the amount goes through grams, which only works for a product somebody
     * has weighed. Where it does not, this returns null and the pack price
     * answers instead: a rough figure for the product beats a precise one for
     * the wrong amount of it.
     */
    public function byAmount(int $ingredientId, ?Quantity $wanted): ?Money
    {
        if ($wanted === null) {
            return null;
        }

        $direct = $this->prices->unitPriceFor($ingredientId, $wanted->unit->dimension);

        if ($direct !== null) {
            return $direct->price->scaledBy($wanted->toBase() / $this->baseUnitsPer($direct));
        }

        $grams = $this->measures->for($ingredientId)->toGrams($wanted);
        $perKilo = $this->prices->unitPriceFor($ingredientId, UnitDimension::Mass);

        if ($grams === null || $perKilo === null) {
            return null;
        }

        return $perKilo->price->scaledBy($grams->toBase() / 1000.0);
    }

    /**
     * How many base units the quoted price covers: a thousand grams, a thousand
     * millilitres, or one piece.
     */
    private function baseUnitsPer(UnitPrice $price): float
    {
        return $price->per === UnitDimension::Count ? 1.0 : 1000.0;
    }
}
