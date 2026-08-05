<?php

declare(strict_types=1);

namespace App\Importing\Resolving;

use App\Models\Ingredient;

final readonly class ResolvedIngredient
{
    public function __construct(
        public Ingredient $ingredient,
        /**
         * False when the ingredient had to be invented from an unrecognised phrase.
         * The line is still imported, but queued for review.
         */
        public bool $isConfident,
        /**
         * True when the word the parser took for a unit turned out to be part of
         * the product's name ("1 listek laurowy"), so the line has no unit at all.
         */
        public bool $unitBelongsToName = false,
    ) {}
}
