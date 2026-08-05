<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Ingredient;
use App\Models\IngredientMeasure;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Loads what a piece, a clove and a spoonful of each product weigh.
 *
 * Re-runnable, and **authoritative**: it replaces a product's measures rather
 * than adding to them, so correcting a number in the data file and seeding again
 * is the whole workflow. Without that, a weight found to be wrong would live on
 * in the database for ever, exactly as a category rule would if
 * `CategoriseRecipes` merged instead of replacing.
 *
 * It fails loudly on a name or a unit it does not recognise. A silently ignored
 * entry is the worst outcome here: the file would look like it covered a product
 * while the app went on saying "cannot tell" about every line that uses it.
 */
class IngredientMeasureSeeder extends Seeder
{
    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    public function run(): void
    {
        /** @var array<string, array{density?: float, grams?: array<string, float>}> $entries */
        $entries = require database_path('data/ingredient-measures.php');

        $unitIds = Unit::query()->pluck('id', 'code');

        foreach ($entries as $name => $entry) {
            $ingredient = $this->product($name);

            $ingredient->update(['density_g_per_ml' => $entry['density'] ?? null]);

            $keep = [];

            foreach ($entry['grams'] ?? [] as $code => $grams) {
                $unitId = $unitIds[$code] ?? null;

                if ($unitId === null) {
                    throw new RuntimeException(
                        "Unknown unit '{$code}' on '{$name}' in database/data/ingredient-measures.php. "
                        .'Units are a closed vocabulary — add it to database/data/units.php first.'
                    );
                }

                IngredientMeasure::query()->updateOrCreate(
                    ['ingredient_id' => $ingredient->id, 'unit_id' => $unitId],
                    ['grams' => $grams],
                );

                $keep[] = $unitId;
            }

            // A measure removed from the file is a measure we decided we could not
            // honestly state. It has to leave the database too.
            $ingredient->measures()->whereNotIn('unit_id', $keep === [] ? [0] : $keep)->delete();
        }
    }

    private function product(string $name): Ingredient
    {
        $ingredient = Ingredient::query()
            ->where('slug', $this->normalizer->slug($name))
            ->first();

        if ($ingredient === null) {
            throw new RuntimeException(
                "No product named '{$name}' — database/data/ingredient-measures.php has drifted from "
                .'database/data/ingredients.php. Fix the name or add the product.'
            );
        }

        return $ingredient;
    }
}
