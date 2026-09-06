<?php

declare(strict_types=1);

namespace App\Planning;

use App\Support\Money\Money;

/**
 * One portion of one recipe, in the two currencies a targeted week is planned in.
 *
 * A named type rather than a pair in an array because the two nulls mean
 * genuinely different things and the difference is easy to lose: no calories
 * disqualifies a dish from a calorie-targeted plan, no price only means nobody
 * has priced it. Reading the second as the first would narrow ten thousand
 * recipes down to whatever was in a leaflet.
 */
final readonly class RecipeFact
{
    public function __construct(
        public ?float $kcalPerPortion,
        public ?Money $costPerPortion,
        public ?float $proteinPerPortion = null,
        /**
         * Ingredients that are neither seasoning nor water — what the dish is
         * actually built from.
         */
        public int $realIngredients = 0,
        /** The product it draws most of its calories from. */
        public ?int $dominantIngredientId = null,
    ) {}

    /**
     * The share of this dish's calories that comes from protein.
     *
     * The one number that separates a meal from a plate of calories. A week
     * planned on calories and price alone converges on flour and potatoes —
     * they are the cheapest calories in any catalogue — and the result hits
     * 2 500 kcal exactly while being pancakes, gnocchi and macaroni cheese.
     * Measured on the live catalogue before this existed, that is precisely
     * what came out.
     *
     * Null when the reading did not state protein, and null must not be read as
     * zero: a dish nobody has broken down is not a dish without protein.
     */
    public function proteinShare(): ?float
    {
        if ($this->proteinPerPortion === null || $this->kcalPerPortion === null || $this->kcalPerPortion <= 0) {
            return null;
        }

        // Four kilocalories to the gram, the same Atwater figure the dictionary
        // test checks the file against.
        return $this->proteinPerPortion * 4 / $this->kcalPerPortion;
    }

    /**
     * How many portions of this it takes to reach a number of calories.
     *
     * Null when the dish has no calorie figure, and null when the figure is zero
     * or negative — a dish nothing can be divided by. Water and tea are real
     * catalogue entries with a genuine zero, and no number of glasses of tea adds
     * up to a lunch.
     */
    public function portionsFor(float $kcal): ?float
    {
        return $this->kcalPerPortion === null || $this->kcalPerPortion <= 0
            ? null
            : $kcal / $this->kcalPerPortion;
    }
}
