<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Enums\UnitDimension;
use App\Support\Measurement\Quantity;

/**
 * What one kilo, one litre or one piece of something costs.
 *
 * This is the only number two promotions can honestly be compared on. "Masło
 * 8,99" and "Masło 12,49" says nothing until you know one is 200 g and the other
 * half a kilo — comparing the pack prices would recommend the worse deal and look
 * confident doing it.
 */
final readonly class UnitPrice
{
    /**
     * @param  Money  $price  what one kilo / litre / piece costs
     * @param  UnitDimension  $per  which of those three it is
     */
    public function __construct(
        public Money $price,
        public UnitDimension $per,
    ) {}

    /**
     * Null when the pack size is unknown or zero, which is the common case: most
     * leaflet entries are just a name and a price. An unknown size must stay
     * unknown — inventing a "typical" pack would put a made-up per-kilo figure in
     * front of someone deciding where to drive.
     */
    public static function of(Money $packPrice, ?Quantity $packSize): ?self
    {
        if ($packSize === null) {
            return null;
        }

        $base = $packSize->toBase();

        if ($base <= 0.0) {
            return null;
        }

        return new self(
            $packPrice->scaledBy(self::baseUnitsPerDisplayUnit($packSize->unit->dimension) / $base),
            $packSize->unit->dimension,
        );
    }

    public function isCheaperThan(self $other): bool
    {
        return $this->per === $other->per && $this->price->isLessThan($other->price);
    }

    public function isComparableWith(self $other): bool
    {
        return $this->per === $other->per;
    }

    /**
     * "za kg", "za l", "za szt." — the denominator this price is quoted against.
     */
    public function label(): string
    {
        return match ($this->per) {
            UnitDimension::Mass => 'kg',
            UnitDimension::Volume => 'l',
            UnitDimension::Count => 'szt.',
        };
    }

    /**
     * Quantities are held in the dimension's base unit (grams, millilitres,
     * pieces), but nobody shops by the gram. A price per kilo is a thousand
     * times the price per gram.
     */
    private static function baseUnitsPerDisplayUnit(UnitDimension $dimension): float
    {
        return match ($dimension) {
            UnitDimension::Mass, UnitDimension::Volume => 1000.0,
            UnitDimension::Count => 1.0,
        };
    }
}
