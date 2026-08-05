<?php

declare(strict_types=1);

namespace App\Shopping\Planning\Contracts;

use App\Models\Promotion;
use App\Models\ShoppingListItem;

/**
 * How to turn "these products are on offer in these shops" into "go here, buy
 * this".
 *
 * There is more than one right answer and the household picks: the absolute
 * cheapest basket can mean six stops, and two stops can be worth a few złoty.
 * Behind an interface so a third rule ("only shops within 2 km") is a new class
 * rather than another branch in a method that already has two.
 */
interface PlanStrategy
{
    /**
     * Stable key used in the URL and in the chip the user taps.
     */
    public function key(): string;

    public function label(): string;

    /**
     * @param  list<ShoppingListItem>  $items
     * @param  array<int, list<Promotion>>  $offers  ranked best first, keyed by ingredient id
     * @return array<int, Promotion> the chosen offer, keyed by shopping list item id
     */
    public function choose(array $items, array $offers): array;
}
