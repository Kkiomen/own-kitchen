<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Models\Promotion;
use App\Models\ShoppingListItem;
use App\Support\Measurement\IngredientMeasures;
use App\Support\Money\Money;

/**
 * One thing off the list, bought at one shop, on one offer.
 */
final readonly class PlannedBuy
{
    /**
     * @param  list<Promotion>  $alternatives  the same product on offer elsewhere, best first
     */
    public function __construct(
        public ShoppingListItem $item,
        public Promotion $promotion,
        public int $packs,
        public array $alternatives,
    ) {}

    /**
     * How many packs it takes to cover what the list asks for.
     *
     * The units rarely line up on their own: a list says "2 cebule" and a leaflet
     * sells a 1 kg bag. `IngredientMeasures` is what makes those the same
     * question, so both sides go through grams whenever they do not compare
     * directly.
     *
     * Falls back to one whenever either side is unknown — an amount the list never
     * stated, a pack whose size the leaflet never printed, or a product nobody has
     * weighed. One is the honest answer there: it is what you would pick up, and
     * it never quietly multiplies a price by a number nobody knows.
     */
    public static function packsNeeded(ShoppingListItem $item, Promotion $promotion, IngredientMeasures $measures): int
    {
        $needed = $item->toQuantity();
        $pack = $promotion->packSize();

        if ($needed === null || $pack === null) {
            return 1;
        }

        if (! $needed->unit->isCompatibleWith($pack->unit)) {
            $needed = $measures->toGrams($needed);
            $pack = $measures->toGrams($pack);
        }

        if ($needed === null || $pack === null || $pack->toBase() <= 0.0) {
            return 1;
        }

        return max(1, (int) ceil($needed->toBase() / $pack->toBase()));
    }

    public function cost(): Money
    {
        return $this->promotion->price()->scaledBy($this->packs);
    }

    /**
     * What this line saves against the shop's own normal price. Null when the
     * leaflet never printed one — most do not, and a saving is either a real
     * number or nothing at all.
     */
    public function savings(): ?Money
    {
        return $this->promotion->savings()?->scaledBy($this->packs);
    }
}
