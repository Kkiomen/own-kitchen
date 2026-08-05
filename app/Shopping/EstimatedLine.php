<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Models\ShoppingListItem;
use App\Support\Money\Money;

/**
 * What one line of a shopping list is expected to cost, and on what evidence.
 *
 * The evidence travels with the figure on purpose. "6,49 zł" from a leaflet this
 * week and "6,49 zł" from a national average two winters ago deserve different
 * amounts of trust, and a screen that showed them identically would be inviting
 * someone to plan a budget on the weaker one. `basis` is what lets the list say
 * which it is holding.
 */
final readonly class EstimatedLine
{
    /**
     * A price this shop is charging this week, for a product on the list. The
     * strongest thing we can say, and the only one that is a price rather than
     * an estimate.
     */
    public const string PROMOTION = 'promotion';

    /** A typical price per kilo/litre/piece, multiplied by the amount asked for. */
    public const string UNIT = 'unit';

    /** A typical price for one pack, because the line named no amount. */
    public const string PACK = 'pack';

    /** Nothing priced it. `cost` is null and the total leaves it out. */
    public const string UNKNOWN = 'unknown';

    public function __construct(
        public ShoppingListItem $item,
        public ?Money $cost,
        public string $basis,
        /**
         * How many packs the figure covers. Always one outside the promotion
         * case: a typical pack price says what a pack costs, and multiplying it
         * by a count derived from a size we do not know would be arithmetic on a
         * guess.
         */
        public int $packs = 1,
    ) {}

    public function isKnown(): bool
    {
        return $this->cost !== null;
    }
}
