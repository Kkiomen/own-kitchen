<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Enums\UnitDimension;
use App\Models\Promotion;
use App\Models\ShoppingListItem;
use App\Pricing\PriceBook;
use App\Shopping\Planning\PlannedBuy;
use App\Shopping\Planning\PromotionFinder;
use App\Support\Measurement\MeasureBook;
use App\Support\Money\Money;
use App\Support\Money\UnitPrice;

/**
 * "Ile mniej więcej zapłacę za te zakupy."
 *
 * Layered, most specific first, exactly like ingredient aliases and product
 * weights — and for the same reason: the layers are different *kinds* of
 * knowledge, not a ranked list of guesses.
 *
 * 1. **A promotion in a shop the household drives to.** Not an estimate at all:
 *    it is the price the till will charge, for the number of packs the amount
 *    needs. Narrowed to the chosen chains, because an offer in a shop nobody is
 *    driving to is not a price this trip will pay.
 * 2. **A typical price per kilo**, multiplied by the amount the line asks for.
 *    Only when both exist — an amount on the line and a unit price for the
 *    product.
 * 3. **A typical price for one pack.** What most lines actually get, because
 *    most lines name no amount and most leaflets print no size.
 * 4. **Nothing.** The line is reported as unpriced and left out of the total.
 *
 * The fourth layer is the one that makes the other three worth trusting. An
 * estimate that silently treated an unknown line as free would be wrong in the
 * direction that costs money, and it would be invisible: the total would simply
 * be too small. So the count of unpriced lines travels with the total and the
 * screen shows it.
 */
final class CostEstimate
{
    public function __construct(
        private readonly PromotionFinder $promotions,
        private readonly PriceBook $prices,
        private readonly MeasureBook $measures,
    ) {}

    /**
     * @param  list<ShoppingListItem>  $items  what is still to buy
     * @param  list<int>|null  $shopIds  the chains being driven to, or null for all
     */
    public function for(array $items, ?array $shopIds = null): EstimatedList
    {
        if ($items === []) {
            return new EstimatedList([]);
        }

        $offers = $this->promotions->forIngredients(
            array_values(array_unique(array_map(
                static fn (ShoppingListItem $item): int => $item->ingredient_id,
                $items,
            ))),
            $shopIds,
        );

        $lines = [];

        foreach ($items as $item) {
            $lines[] = $this->estimate($item, $offers[$item->ingredient_id][0] ?? null);
        }

        return new EstimatedList($lines);
    }

    private function estimate(ShoppingListItem $item, ?Promotion $offer): EstimatedLine
    {
        if ($offer !== null) {
            $packs = PlannedBuy::packsNeeded($item, $offer, $this->measures->for($item->ingredient_id));

            return new EstimatedLine(
                item: $item,
                cost: $offer->price()->scaledBy($packs),
                basis: EstimatedLine::PROMOTION,
                packs: $packs,
            );
        }

        $byAmount = $this->fromUnitPrice($item);

        if ($byAmount !== null) {
            return new EstimatedLine($item, $byAmount, EstimatedLine::UNIT);
        }

        $pack = $this->prices->packPriceFor($item->ingredient_id);

        return $pack === null
            ? new EstimatedLine($item, null, EstimatedLine::UNKNOWN)
            : new EstimatedLine($item, $pack, EstimatedLine::PACK);
    }

    /**
     * The amount on the line, priced per kilo.
     *
     * Two conversions have to line up and either may fail. The line's unit and
     * the price's dimension often differ — "2 cebule" against a price per kilo —
     * so the amount goes through grams, which only works for a product somebody
     * has weighed. Where it does not, this returns null and the pack price
     * answers instead: a rough figure for the product beats a precise one for
     * the wrong amount of it.
     */
    private function fromUnitPrice(ShoppingListItem $item): ?Money
    {
        $wanted = $item->toQuantity();

        if ($wanted === null) {
            return null;
        }

        $direct = $this->prices->unitPriceFor($item->ingredient_id, $wanted->unit->dimension);

        if ($direct !== null) {
            return $direct->price->scaledBy($wanted->toBase() / $this->baseUnitsPer($direct));
        }

        // Not priced in the dimension the line is written in. Grams are the one
        // bridge the catalogue has, and only for a product with a weight.
        $grams = $this->measures->for($item->ingredient_id)->toGrams($wanted);
        $perKilo = $this->prices->unitPriceFor($item->ingredient_id, UnitDimension::Mass);

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
