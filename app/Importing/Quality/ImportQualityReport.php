<?php

declare(strict_types=1);

namespace App\Importing\Quality;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Support\Facades\DB;

/**
 * Measures how well the last import understood what it read.
 *
 * Kept out of the console command so the numbers can be asserted in tests, and
 * so a growing dictionary can be judged by the same yardstick every time.
 */
final class ImportQualityReport
{
    public function generate(int $unresolvedSamples = 25): QualitySnapshot
    {
        return new QualitySnapshot(
            recipes: Recipe::query()->count(),
            ingredientLines: RecipeIngredient::query()->count(),
            steps: RecipeStep::query()->count(),
            products: Ingredient::query()->count(),
            productsAwaitingCuration: Ingredient::query()->awaitingCuration()->count(),
            linesWithProduct: RecipeIngredient::query()->whereNotNull('ingredient_id')->count(),
            linesWithQuantity: RecipeIngredient::query()->whereNotNull('quantity')->count(),
            linesWithUnit: RecipeIngredient::query()->whereNotNull('unit_id')->count(),
            linesNeedingReview: RecipeIngredient::query()->where('needs_review', true)->count(),
            stepsWithAction: RecipeStep::query()->whereNotNull('action')->count(),
            stepsWithAppliance: RecipeStep::query()->whereNotNull('appliance')->count(),
            stepsWithTemperature: RecipeStep::query()->whereNotNull('temperature_celsius')->count(),
            stepsWithDuration: RecipeStep::query()->whereNotNull('duration_seconds')->count(),
            stepsWithoutIngredients: RecipeStep::query()->doesntHave('ingredients')->count(),
            stepIngredientLinks: DB::table('recipe_step_ingredients')->count(),
            recipesWithImage: Recipe::query()->whereNotNull('image_url')->count(),
            unresolvedLines: $this->unresolvedLines($unresolvedSamples),
            recipesNeedingReview: $this->recipesNeedingReview(),
        );
    }

    /**
     * The review queue, which is also the to-do list for the seed dictionary.
     *
     * @return list<string>
     */
    private function unresolvedLines(int $limit): array
    {
        $lines = [];

        $rows = RecipeIngredient::query()
            ->where('needs_review', true)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $line) {
            $lines[] = $line->raw_text;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function recipesNeedingReview(): array
    {
        $slugs = [];

        foreach (Recipe::query()->needingReview()->orderBy('slug')->get() as $recipe) {
            $slugs[] = $recipe->slug;
        }

        return $slugs;
    }
}
