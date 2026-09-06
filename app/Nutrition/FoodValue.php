<?php

declare(strict_types=1);

namespace App\Nutrition;

/**
 * What 100 g of one product is worth — the figure as the dictionary states it,
 * before anybody says how much of it a recipe wants.
 *
 * Per 100 g rather than per anything else for the reason `ingredient_measures`
 * exists: a recipe line states an amount in whatever unit it likes, and grams
 * are the only common ground between "2 cebule", "pół szklanki" and "300 g".
 * Turning that amount into grams is `IngredientMeasures`' job; turning grams
 * into calories is this one's, and keeping the two apart is what stops either
 * from having to know about the other.
 */
final readonly class FoodValue
{
    public function __construct(
        public float $kcalPer100g,
        public ?float $proteinPer100g = null,
        public ?float $fatPer100g = null,
        public ?float $carbsPer100g = null,
        public ?string $externalKey = null,
    ) {}

    /**
     * What this many grams of the product is worth.
     */
    public function forGrams(float $grams): Nutrients
    {
        $hundreds = $grams / 100;

        return new Nutrients(
            $this->kcalPer100g * $hundreds,
            $this->proteinPer100g === null ? null : $this->proteinPer100g * $hundreds,
            $this->fatPer100g === null ? null : $this->fatPer100g * $hundreds,
            $this->carbsPer100g === null ? null : $this->carbsPer100g * $hundreds,
        );
    }
}
