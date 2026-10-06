<?php

declare(strict_types=1);

namespace App\Nutrition;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Support\Measurement\MeasureBook;
use Illuminate\Support\Collection;

/**
 * What a recipe is worth, per portion and in total.
 *
 * Three facts have to meet for one line to contribute a calorie, and all three
 * are already somebody else's job:
 *
 *   1. the line states an amount            — `RecipeIngredient::toQuantity()`
 *   2. that amount converts to grams        — `IngredientMeasures::toGrams()`
 *   3. the product has a figure per 100 g   — `NutritionBook`
 *
 * This class is only the meeting point, which is why it is short. It adds no
 * rule of its own except the one that matters: **a line it cannot read is
 * counted as unread, never as zero.** A missing figure and a genuine zero are
 * indistinguishable in a total, and of the two only one of them makes a plan
 * silently under-feed the household.
 *
 * Deliberately not scaled to a requested number of portions. `RecipeScale`
 * already multiplies the loaded rows before anything asks them anything, so a
 * recipe read for six portions arrives here as six portions — and asking this
 * class to scale as well would apply the factor twice.
 */
final readonly class RecipeNutrition
{
    public function __construct(
        private NutritionBook $nutrition,
        private MeasureBook $measures,
    ) {}

    public function for(Recipe $recipe): RecipeEnergy
    {
        return $this->ofLines($recipe->ingredients, $recipe->servings);
    }

    /**
     * The same question asked of lines already in hand.
     *
     * Separate from `for()` because the planner works over a week's worth of
     * rows it has loaded in one query — going back to the model for each recipe
     * would be a query per dish for data already on the table.
     *
     * @param  Collection<int, RecipeIngredient>  $lines
     */
    public function ofLines(Collection $lines, ?int $servings): RecipeEnergy
    {
        $total = Nutrients::zero();
        $countable = 0;
        $read = 0;
        $unknown = [];
        $byIngredient = [];

        foreach ($lines as $line) {
            if (! RecipeEnergy::isCountable($line)) {
                continue;
            }

            $countable++;

            $nutrients = $this->ofLine($line);

            if ($nutrients === null) {
                // A line the importer never resolved has no product to name, so
                // the screen gets what the recipe actually said.
                $product = $line->ingredient;
                $unknown[] = $product === null ? $line->raw_text : $product->name;

                continue;
            }

            $total = $total->plus($nutrients);
            $read++;

            if ($line->ingredient_id !== null) {
                $byIngredient[$line->ingredient_id] = ($byIngredient[$line->ingredient_id] ?? 0.0) + $nutrients->kcal;
            }
        }

        /*
         * A recipe with nothing countable at all is fully covered rather than
         * fully unknown, and its total is a true zero: there was nothing to read.
         * Dividing by zero to decide that would be the only place this class
         * could throw.
         */
        $coverage = $countable === 0 ? 1.0 : $read / $countable;

        arsort($byIngredient);

        return new RecipeEnergy(
            total: $total,
            perPortion: $servings === null || $servings < 1 ? null : $total->scaledBy(1 / $servings),
            coverage: $coverage,
            unknown: array_values(array_unique($unknown)),
            /*
             * What the dish is mostly made of, by calories. Five different
             * pancake recipes are five different rows and one breakfast, and
             * this is the only field that can say so — the titles certainly
             * cannot, and neither can the categories.
             */
            dominantIngredientId: $byIngredient === [] ? null : (int) array_key_first($byIngredient),
            kcalByIngredient: $byIngredient,
        );
    }

    /**
     * One line's contribution, or null when any of the three facts is missing.
     */
    private function ofLine(RecipeIngredient $line): ?Nutrients
    {
        $value = $this->nutrition->for($line->ingredient_id);

        if ($value === null) {
            return null;
        }

        $grams = $this->measures->for($line->ingredient_id)->toGrams($line->toQuantity());

        return $grams === null ? null : $value->forGrams($grams->amount);
    }
}
