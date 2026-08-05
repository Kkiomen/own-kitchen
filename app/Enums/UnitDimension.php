<?php

declare(strict_types=1);

namespace App\Enums;

enum UnitDimension: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';

    /**
     * The unit every factor_to_base is expressed in for this dimension.
     */
    public function baseUnitCode(): string
    {
        return match ($this) {
            self::Mass => 'g',
            self::Volume => 'ml',
            self::Count => 'piece',
        };
    }
}
