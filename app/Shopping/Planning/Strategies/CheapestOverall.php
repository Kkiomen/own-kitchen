<?php

declare(strict_types=1);

namespace App\Shopping\Planning\Strategies;

use App\Models\Promotion;
use App\Models\ShoppingListItem;
use App\Shopping\Planning\Contracts\PlanStrategy;

/**
 * Buy everything wherever it is cheapest, however many shops that turns out to
 * be. The ranking has already decided what "cheapest" means for one product, so
 * this is simply its first answer, product by product.
 */
final class CheapestOverall implements PlanStrategy
{
    public function key(): string
    {
        return 'najtaniej';
    }

    public function label(): string
    {
        return 'Najtaniej';
    }

    /**
     * @param  list<ShoppingListItem>  $items
     * @param  array<int, list<Promotion>>  $offers
     * @return array<int, Promotion>
     */
    public function choose(array $items, array $offers): array
    {
        $chosen = [];

        foreach ($items as $item) {
            $best = $offers[$item->ingredient_id][0] ?? null;

            if ($best !== null) {
                $chosen[$item->id] = $best;
            }
        }

        return $chosen;
    }
}
