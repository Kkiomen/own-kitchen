<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use Illuminate\Support\Facades\DB;

/**
 * Every product's measures, loaded once and asked many times.
 *
 * Deliberately not a relation on `Ingredient`. The callers that need this ask
 * about a whole shopping list or a whole recipe at once, and a lazily loaded
 * relation would turn each of those into a query per line. The whole book is a
 * few hundred rows — smaller than one page of the recipe list — so it is read in
 * two queries and kept.
 */
final class MeasureBook
{
    /**
     * @var array<int, IngredientMeasures>|null
     */
    private ?array $book = null;

    public function for(?int $ingredientId): IngredientMeasures
    {
        if ($ingredientId === null) {
            return IngredientMeasures::none();
        }

        return $this->book()[$ingredientId] ?? IngredientMeasures::none();
    }

    /**
     * Forget what was loaded. Only a seeder or a test that has just written
     * measures needs this; nothing in a request does.
     */
    public function forget(): void
    {
        $this->book = null;
    }

    /**
     * @return array<int, IngredientMeasures>
     */
    private function book(): array
    {
        if ($this->book !== null) {
            return $this->book;
        }

        $densities = DB::table('ingredients')
            ->whereNotNull('density_g_per_ml')
            ->pluck('density_g_per_ml', 'id');

        $grams = [];

        foreach (DB::table('ingredient_measures as m')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->select('m.ingredient_id', 'u.code', 'm.grams')->get() as $row) {
            $grams[(int) $row->ingredient_id][(string) $row->code] = (float) $row->grams;
        }

        $book = [];

        foreach (array_unique([...array_keys($grams), ...array_map('intval', $densities->keys()->all())]) as $id) {
            $book[$id] = new IngredientMeasures(
                densityGramsPerMl: isset($densities[$id]) ? (float) $densities[$id] : null,
                gramsPerUnit: $grams[$id] ?? [],
            );
        }

        return $this->book = $book;
    }
}
