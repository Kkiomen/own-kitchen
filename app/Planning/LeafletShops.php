<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\Promotion;
use App\Models\Shop;
use App\Shopping\Planning\PromotionRanking;
use Illuminate\Support\Facades\DB;

/**
 * Which shops a week can be planned around, and what each one offers.
 *
 * Only offers matched to a product count. A leaflet is mostly lawnmowers and
 * shampoo; "Lidl, 117 promocji" when two of them are food would promise a plan
 * the shop cannot feed, so the screen is told how many *products* a shop has on
 * offer, which is the number the planner can actually use.
 */
final readonly class LeafletShops
{
    /**
     * Products a leaflet sells that a recipe never buys.
     *
     * Water is the one found on real data: "Woda mineralna 6 × 1,5 l" matches
     * Woda, and the water in a recipe comes out of the tap. Left in, every soup
     * and every pot of kasza counted as "built on this week's offers".
     */
    private const array NEVER_BOUGHT_FOR_A_RECIPE = ['woda'];

    public function __construct(private PromotionRanking $ranking) {}

    /**
     * Every shop with at least one product on offer, most products first.
     *
     * @return list<array{id: int, name: string, products: int}>
     */
    public function choices(): array
    {
        $counts = DB::table('promotions')
            ->whereNotNull('ingredient_id')
            ->whereNotIn('ingredient_id', $this->neverBought())
            ->where(static function ($query): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString());
            })
            ->groupBy('shop_id')
            ->selectRaw('shop_id, count(distinct ingredient_id) as products')
            ->pluck('products', 'shop_id');

        $choices = [];

        foreach (Shop::query()->inWalkingOrder()->get(['id', 'name']) as $shop) {
            $products = (int) ($counts[$shop->id] ?? 0);

            if ($products > 0) {
                $choices[] = ['id' => $shop->id, 'name' => $shop->name, 'products' => $products];
            }
        }

        usort($choices, static fn (array $a, array $b): int => $b['products'] <=> $a['products']);

        return $choices;
    }

    /**
     * The best current offer per product in one shop, as the shopping plan
     * would rank them — so the week is planned on the offer the plan will later
     * send you to the shelf for.
     */
    public function offersAt(Shop $shop): ShopOffers
    {
        $groups = Promotion::query()
            ->active()
            ->inShops([$shop->id])
            ->whereNotNull('ingredient_id')
            ->whereNotIn('ingredient_id', $this->neverBought())
            ->with(['shop', 'packUnit'])
            ->get()
            ->groupBy('ingredient_id');

        $best = [];

        foreach ($groups as $ingredientId => $group) {
            /*
             * An offer that states a "before" or a size first, whatever the
             * ranking thinks of the rest. The others cannot be told apart from
             * a price per 100 g or a toy named after a cheese — "Ser Edam
             * 2,99" is a hundred grams — and `ShopOffers::quote()` declines to
             * judge them, so one of those ranked first would hide a genuine
             * offer on the same product.
             */
            $judgeable = $group->filter(static fn (Promotion $offer): bool => $offer->regular_price_minor !== null
                || $offer->unit_price_minor !== null);

            $best[(int) $ingredientId] = $this->ranking->sort(array_values(
                ($judgeable->isEmpty() ? $group : $judgeable)->all(),
            ))[0];
        }

        return new ShopOffers($shop->id, $shop->name, $best);
    }

    /** @return list<int> */
    private function neverBought(): array
    {
        return array_values(array_map(
            intval(...),
            DB::table('ingredients')->whereIn('slug', self::NEVER_BOUGHT_FOR_A_RECIPE)->pluck('id')->all(),
        ));
    }
}
