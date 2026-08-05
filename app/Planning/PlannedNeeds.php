<?php

declare(strict_types=1);

namespace App\Planning;

use App\Support\Measurement\Quantity;

/**
 * What a set of planned days adds up to, before the kitchen is consulted.
 *
 * One entry per product, however many meals asked for it — the same rule the
 * shopping list follows, applied a week earlier.
 */
final readonly class PlannedNeeds
{
    /**
     * @param  array<int, Quantity|null>  $amounts  ingredient id => total wanted.
     *                                              Null is "some, amount unknown",
     *                                              never "none".
     * @param  int  $unresolvedLines  lines the importer never matched to a product.
     *                                Reported so the screen can say so; there is
     *                                nothing to write down for them.
     * @param  list<string>  $unscaledRecipes  titles of recipes that never said how
     *                                         many portions they make, so their
     *                                         amounts are taken as written
     * @param  list<string>  $wholeBatches  recipes bought as one whole cooking
     *                                      because fewer portions were planned
     *                                      than the recipe makes
     * @param  int  $meals  planned dishes counted
     * @param  int  $notes  entries that are a note rather than a recipe
     */
    public function __construct(
        public array $amounts = [],
        public int $unresolvedLines = 0,
        public array $unscaledRecipes = [],
        public array $wholeBatches = [],
        public int $meals = 0,
        public int $notes = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->amounts === [];
    }
}
