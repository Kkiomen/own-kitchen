<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * What one source's refresh did. Mutable on purpose: it is a tally kept while a
 * run is in progress, not a value anybody stores.
 */
final class PriceImportSummary
{
    public function __construct(
        public int $stored = 0,
        public int $matched = 0,
        public int $priced = 0,
        public int $failed = 0,
    ) {}

    /**
     * The share of readings that reached a canonical product. A reading that did
     * not is vocabulary the dictionary is missing, never a reason to invent one.
     */
    public function matchRate(): float
    {
        return $this->stored === 0 ? 0.0 : $this->matched / $this->stored;
    }

    /**
     * The share that can actually price anything.
     *
     * Lower than the match rate, and the gap is the point: knowing a reading is
     * about butter is useless without knowing how much butter the figure buys.
     * A source whose match rate is high and whose priced rate is low is stating
     * prices for packs it does not describe.
     */
    public function pricedRate(): float
    {
        return $this->stored === 0 ? 0.0 : $this->priced / $this->stored;
    }
}
