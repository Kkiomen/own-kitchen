<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Models\Promotion;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Shopping\Planning\Contracts\PlanStrategy;
use App\Support\Measurement\MeasureBook;

/**
 * Turns "co kupić" into "gdzie po to pojechać".
 *
 * The shopping list is already one line per product, which is what makes this
 * possible at all: a promotion points at the same canonical `Ingredient` the
 * list line does, so matching them is a join rather than a guess about text.
 */
final class ShoppingPlanner
{
    /**
     * How many runners-up to carry to the screen. The cook standing in the shop
     * can see the pack size we never learned, so the second-best offer is worth
     * showing — but a list of thirteen chains under every product is a wall.
     */
    private const int ALTERNATIVES = 3;

    public function __construct(
        private readonly PromotionFinder $finder,
        private readonly MeasureBook $measures,
    ) {}

    /**
     * @param  list<int>|null  $shopIds  the chains the household drives to. Null
     *                                   means they have not chosen, which is
     *                                   every chain — a plan narrowed to nothing
     *                                   would be an empty screen on a fresh
     *                                   account.
     */
    public function plan(ShoppingList $list, PlanStrategy $strategy, ?array $shopIds = null): ShoppingPlan
    {
        $items = $this->itemsToBuy($list);
        $offers = $this->finder->forIngredients(
            array_values(array_unique(array_map(
                static fn (ShoppingListItem $item): int => $item->ingredient_id,
                $items,
            ))),
            $shopIds,
        );

        $chosen = $strategy->choose($items, $offers);

        return new ShoppingPlan(
            stops: $this->stops($items, $chosen, $offers),
            withoutPromotion: array_values(array_filter(
                $items,
                static fn (ShoppingListItem $item): bool => ! isset($chosen[$item->id]),
            )),
            strategy: $strategy->key(),
        );
    }

    /**
     * @param  list<ShoppingListItem>  $items
     * @param  array<int, Promotion>  $chosen
     * @param  array<int, list<Promotion>>  $offers
     * @return list<PlannedStop>
     */
    private function stops(array $items, array $chosen, array $offers): array
    {
        $byShop = [];

        foreach ($items as $item) {
            $promotion = $chosen[$item->id] ?? null;

            if ($promotion === null) {
                continue;
            }

            $byShop[$promotion->shop_id][] = new PlannedBuy(
                item: $item,
                promotion: $promotion,
                packs: PlannedBuy::packsNeeded($item, $promotion, $this->measures->for($item->ingredient_id)),
                alternatives: $this->alternatives($offers[$item->ingredient_id] ?? [], $promotion),
            );
        }

        $stops = [];

        foreach ($byShop as $buys) {
            $stops[] = new PlannedStop(shop: $buys[0]->promotion->shop, buys: $buys);
        }

        // Configured order, which is the order the household actually passes the
        // shops — not alphabetical and not "biggest basket first".
        usort($stops, static fn (PlannedStop $a, PlannedStop $b): int => [$a->shop->position, $a->shop->name]
            <=> [$b->shop->position, $b->shop->name]);

        return $stops;
    }

    /**
     * The same product on offer elsewhere. One per shop: two entries for butter
     * in the same Biedronka leaflet are a choice you make at the shelf, not a
     * reason to drive somewhere.
     *
     * @param  list<Promotion>  $ranked
     * @return list<Promotion>
     */
    private function alternatives(array $ranked, Promotion $chosen): array
    {
        $seen = [$chosen->shop_id => true];
        $alternatives = [];

        foreach ($ranked as $promotion) {
            if (isset($seen[$promotion->shop_id])) {
                continue;
            }

            $seen[$promotion->shop_id] = true;
            $alternatives[] = $promotion;

            if (count($alternatives) === self::ALTERNATIVES) {
                break;
            }
        }

        return $alternatives;
    }

    /**
     * @return list<ShoppingListItem>
     */
    private function itemsToBuy(ShoppingList $list): array
    {
        return array_values(
            ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->stillToBuy()
                ->with(['ingredient:id,name,category', 'unit'])
                ->get()
                ->all(),
        );
    }
}
