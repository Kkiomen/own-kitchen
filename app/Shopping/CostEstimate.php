<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Models\Promotion;
use App\Models\ShoppingListItem;
use App\Pricing\IngredientCost;
use App\Pricing\PriceBook;
use App\Shopping\Planning\PlannedBuy;
use App\Shopping\Planning\PromotionFinder;
use App\Support\Measurement\MeasureBook;
use App\Support\Money\Money;

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
        private readonly IngredientCost $costs,
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

        $byAmount = $this->costs->byAmount($item->ingredient_id, $item->toQuantity());

        if ($byAmount !== null) {
            return new EstimatedLine($item, $byAmount, EstimatedLine::UNIT);
        }

        $pack = $this->prices->packPriceFor($item->ingredient_id);

        return $pack === null
            ? new EstimatedLine($item, null, EstimatedLine::UNKNOWN)
            : new EstimatedLine($item, $pack, EstimatedLine::PACK);
    }
}
