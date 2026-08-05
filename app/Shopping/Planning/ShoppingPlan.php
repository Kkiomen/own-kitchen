<?php

declare(strict_types=1);

namespace App\Shopping\Planning;

use App\Models\ShoppingListItem;
use App\Support\Money\Money;

/**
 * Where to go and what to buy there, for one shopping list.
 *
 * `total` is the cost of the promoted lines only, and nothing here pretends
 * otherwise. We know what a leaflet charges for butter this week; we do not know
 * what the shop charges for the flour that is not on offer. A plan that added a
 * guess for those would produce a confident total that is wrong by more than
 * everything it got right.
 */
final readonly class ShoppingPlan
{
    /**
     * @param  list<PlannedStop>  $stops
     * @param  list<ShoppingListItem>  $withoutPromotion  on the list, on offer nowhere
     */
    public function __construct(
        public array $stops,
        public array $withoutPromotion,
        public string $strategy,
    ) {}

    public function total(): Money
    {
        return array_reduce(
            $this->stops,
            static fn (Money $sum, PlannedStop $stop): Money => $sum->plus($stop->subtotal()),
            new Money(0),
        );
    }

    public function savings(): ?Money
    {
        $known = array_filter(array_map(
            static fn (PlannedStop $stop): ?Money => $stop->savings(),
            $this->stops,
        ));

        return $known === []
            ? null
            : array_reduce($known, static fn (Money $sum, Money $saved): Money => $sum->plus($saved), new Money(0));
    }

    public function promotedCount(): int
    {
        return array_sum(array_map(static fn (PlannedStop $stop): int => count($stop->buys), $this->stops));
    }

    public function isEmpty(): bool
    {
        return $this->stops === [];
    }
}
