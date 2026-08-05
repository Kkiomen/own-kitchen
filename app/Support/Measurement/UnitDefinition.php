<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use App\Enums\UnitDimension;

/**
 * A unit stripped of persistence concerns, so measurement logic stays testable
 * without a database.
 */
final readonly class UnitDefinition
{
    public function __construct(
        public string $code,
        public UnitDimension $dimension,
        public float $factorToBase,
        public bool $isApproximate = false,
    ) {}

    /**
     * The unit a dimension's factors are all expressed in — grams, millilitres,
     * items. Handy wherever a calculation has to land in one known unit rather
     * than in whichever one the caller happened to be holding.
     */
    public static function base(UnitDimension $dimension): self
    {
        return new self($dimension->baseUnitCode(), $dimension, 1.0);
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this->dimension === $other->dimension;
    }
}
