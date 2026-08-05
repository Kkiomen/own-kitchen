<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Models\Shop;
use App\Support\Money\Money;

/**
 * One shop to walk into, and what to pick up there.
 */
final readonly class PlannedStop
{
    /**
     * @param  list<PlannedBuy>  $buys
     */
    public function __construct(
        public Shop $shop,
        public array $buys,
    ) {}

    public function subtotal(): Money
    {
        return array_reduce(
            $this->buys,
            static fn (Money $total, PlannedBuy $buy): Money => $total->plus($buy->cost()),
            new Money(0),
        );
    }

    /**
     * Null when not one line in this stop printed a "before" price. Zero would
     * claim we checked and found no saving, which is a different statement.
     */
    public function savings(): ?Money
    {
        $known = array_filter(array_map(
            static fn (PlannedBuy $buy): ?Money => $buy->savings(),
            $this->buys,
        ));

        return $known === []
            ? null
            : array_reduce($known, static fn (Money $total, Money $saved): Money => $total->plus($saved), new Money(0));
    }
}
