<?php

declare(strict_types=1);

namespace App\Nutrition;

use Illuminate\Support\Facades\DB;

/**
 * Every product's calories, loaded once and asked many times.
 *
 * A singleton for the same reason `MeasureBook` is one, and it is asked in the
 * same breath: working out what a week is worth means asking about a few hundred
 * lines across a dozen recipes, and every one of those questions needs both
 * books. Two of them per request, a few hundred rows each, read in one query —
 * against a per-line relation, which would be a query per ingredient of every
 * recipe on the screen.
 *
 * `for()` answers **null** for a product nobody has recorded, and every caller
 * has to read that as *stay quiet* rather than as zero. A product counted as
 * zero calories is the worst failure available to this module: it does not look
 * like a gap, it looks like a light meal, and a week built on it would be short
 * of food while reporting that it hit its target exactly.
 */
final class NutritionBook
{
    /**
     * @var array<int, FoodValue>|null
     */
    private ?array $book = null;

    public function for(?int $ingredientId): ?FoodValue
    {
        if ($ingredientId === null) {
            return null;
        }

        return $this->book()[$ingredientId] ?? null;
    }

    /**
     * Every product this book can put a calorie on.
     *
     * The coverage report asks this rather than counting rows itself, so it
     * cannot claim coverage the planner would not actually deliver.
     *
     * @return list<int>
     */
    public function ingredientIds(): array
    {
        return array_keys($this->book());
    }

    public function isEmpty(): bool
    {
        return $this->book() === [];
    }

    /**
     * Forget what was loaded. Only a seeder or a test that has just written
     * figures needs this; nothing in a request does.
     */
    public function forget(): void
    {
        $this->book = null;
    }

    /**
     * @return array<int, FoodValue>
     */
    private function book(): array
    {
        if ($this->book !== null) {
            return $this->book;
        }

        $book = [];

        foreach (DB::table('ingredient_nutrition')->get() as $row) {
            $book[(int) $row->ingredient_id] = new FoodValue(
                kcalPer100g: (float) $row->kcal_per_100g,
                proteinPer100g: $row->protein_g_per_100g === null ? null : (float) $row->protein_g_per_100g,
                fatPer100g: $row->fat_g_per_100g === null ? null : (float) $row->fat_g_per_100g,
                carbsPer100g: $row->carbs_g_per_100g === null ? null : (float) $row->carbs_g_per_100g,
                externalKey: $row->external_key === null ? null : (string) $row->external_key,
            );
        }

        return $this->book = $book;
    }
}
