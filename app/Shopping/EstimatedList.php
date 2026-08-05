<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Support\Money\Money;

/**
 * What a whole shopping list is expected to cost.
 *
 * The total and the count of lines nobody could price are one object because
 * they are one statement. "Około 84 zł" is a different claim from "około 84 zł,
 * i nie znam ceny sześciu rzeczy", and separating them would let a screen show
 * the first without the second.
 */
final readonly class EstimatedList
{
    /**
     * @param  list<EstimatedLine>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * The lines that could be priced, added up.
     *
     * Zero when nothing could be — which the screen must distinguish from "these
     * shopping are free". That is what `unpriced` and `hasAnyPrice` are for.
     */
    public function total(): Money
    {
        $total = new Money(0);

        foreach ($this->lines as $line) {
            if ($line->cost !== null) {
                $total = $total->plus($line->cost);
            }
        }

        return $total;
    }

    /**
     * How much of the total is a price rather than an estimate — the part backed
     * by an offer in a shop the household is actually driving to.
     */
    public function promoted(): Money
    {
        $total = new Money(0);

        foreach ($this->lines as $line) {
            if ($line->basis === EstimatedLine::PROMOTION && $line->cost !== null) {
                $total = $total->plus($line->cost);
            }
        }

        return $total;
    }

    public function pricedCount(): int
    {
        return count(array_filter($this->lines, static fn (EstimatedLine $line): bool => $line->isKnown()));
    }

    public function unpricedCount(): int
    {
        return count($this->lines) - $this->pricedCount();
    }

    public function hasAnyPrice(): bool
    {
        return $this->pricedCount() > 0;
    }

    /**
     * @return array<int, EstimatedLine> keyed by shopping list item id
     */
    public function byItem(): array
    {
        $lines = [];

        foreach ($this->lines as $line) {
            $lines[$line->item->id] = $line;
        }

        return $lines;
    }
}
