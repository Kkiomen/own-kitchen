<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Ingredient;
use App\Models\IngredientNutrition;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Loads what 100 g of each product is worth in calories and macros.
 *
 * Re-runnable and **authoritative**, exactly like `IngredientMeasureSeeder`:
 * correcting a figure in the data file and seeding again is the whole workflow,
 * and an entry taken out of the file leaves the database too. Without that
 * second half the file would only ever be an authority on what a product *does*
 * say, and a calorie found to be wrong would live on for ever.
 *
 * It owns only what it wrote. Rows from another source — a future importer
 * reading an external database — are left alone, because "the curated file no
 * longer mentions this" is not a statement about a figure somebody else
 * supplied. That is also why deleting is scoped by `source` rather than being a
 * bare `whereNotIn`.
 *
 * It fails loudly on a product name it does not recognise. A silently ignored
 * entry is the worst outcome available here: the file would look as though it
 * covered a staple while every plan built on it quietly left that staple out of
 * the calorie count — and a plan that is short by one staple is a plan that
 * looks fine and is wrong.
 */
class IngredientNutritionSeeder extends Seeder
{
    /**
     * What this file's figures are marked as, and therefore what this seeder is
     * allowed to remove.
     */
    public const string SOURCE = 'curated';

    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    public function run(): void
    {
        /** @var array<string, array{0: float, 1: ?float, 2: ?float, 3: ?float, 4?: ?string}> $entries */
        $entries = require database_path('data/ingredient-nutrition.php');

        $keep = [];

        foreach ($entries as $name => $entry) {
            $ingredient = $this->product((string) $name);

            IngredientNutrition::query()->updateOrCreate(
                ['ingredient_id' => $ingredient->id],
                [
                    'kcal_per_100g' => $entry[0],
                    'protein_g_per_100g' => $entry[1],
                    'fat_g_per_100g' => $entry[2],
                    'carbs_g_per_100g' => $entry[3],
                    'source' => self::SOURCE,
                    'external_key' => $entry[4] ?? null,
                ],
            );

            $keep[] = $ingredient->id;
        }

        IngredientNutrition::query()
            ->where('source', self::SOURCE)
            ->whereNotIn('ingredient_id', $keep === [] ? [0] : $keep)
            ->delete();
    }

    private function product(string $name): Ingredient
    {
        $ingredient = Ingredient::query()
            ->where('slug', $this->normalizer->slug($name))
            ->first();

        if ($ingredient === null) {
            throw new RuntimeException(
                "No product named '{$name}' — database/data/ingredient-nutrition.php has drifted from "
                .'database/data/ingredients.php. Fix the name or add the product.'
            );
        }

        return $ingredient;
    }
}
