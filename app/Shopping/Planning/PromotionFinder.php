<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Models\Promotion;

/**
 * Every current offer for a set of products, ranked, grouped by product.
 *
 * One query for the whole list rather than one per item: a shopping list of
 * twenty products across thirteen chains is otherwise twenty round trips to
 * answer a question the database can answer once.
 */
final class PromotionFinder
{
    public function __construct(private readonly PromotionRanking $ranking) {}

    /**
     * @param  list<int>  $ingredientIds
     * @param  list<int>|null  $shopIds  the chains the household drives to, or
     *                                   null for "they have not said", which is
     *                                   every chain rather than none.
     * @return array<int, list<Promotion>> Offers best first, keyed by ingredient id.
     */
    public function forIngredients(array $ingredientIds, ?array $shopIds = null): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        $promotions = Promotion::query()
            ->active()
            ->inShops($shopIds)
            ->forIngredients($ingredientIds)
            ->with(['shop', 'packUnit'])
            ->get()
            ->groupBy('ingredient_id');

        $ranked = [];

        foreach ($promotions as $ingredientId => $group) {
            $ranked[(int) $ingredientId] = $this->ranking->sort(array_values($group->all()));
        }

        return $ranked;
    }
}
