<?php

declare(strict_types=1);

namespace App\Planning;

use App\Support\Money\Money;

/**
 * The two totals a planned week has, and how much of it we could not price.
 *
 * `unpricedProducts` counts products on the **shopping** side, because that is
 * the number a budget is judged against: saying "I could not price four of the
 * things you still have to buy" is actionable, and saying it about things
 * already in the cupboard is noise.
 */
final readonly class WeekPrice
{
    public function __construct(
        public Money $eats,
        public Money $buys,
        public int $unpricedProducts,
        public int $products,
    ) {}

    /**
     * Whether the shopping comes in under a ceiling.
     *
     * Only ever asked of `buys`, and only ever a claim about what we could
     * price — `unpricedProducts` says how much confidence that deserves, and a
     * caller that ignores it is quoting a total it knows is incomplete.
     */
    public function fitsWithin(?Money $budget): bool
    {
        return $budget === null || ! $budget->isLessThan($this->buys);
    }

    /**
     * How sure the shopping total is: the share of products behind it that
     * something actually priced.
     *
     * One is complete, zero is a total made of nothing at all — which is what a
     * fresh installation would report, and why the screen hides the money rather
     * than printing "0 zł" over a full list.
     */
    public function confidence(): float
    {
        return $this->products === 0
            ? 1.0
            : max(0.0, ($this->products - $this->unpricedProducts) / $this->products);
    }
}
