<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The calorie dictionary read as a file, with no database anywhere near it.
 *
 * `IngredientNutritionSeeder` already refuses a product name that has drifted,
 * but it can only say so once somebody seeds. These checks run in a second and
 * catch the class of mistake a seeder cannot see at all: a decimal point in the
 * wrong place. A wrong weight makes a shopping list wrong and somebody notices
 * at the till; a wrong calorie makes a week's eating wrong and nobody notices at
 * all — which is exactly why it is worth a test that costs nothing to run.
 */
class IngredientNutritionDictionaryTest extends TestCase
{
    /**
     * Where the energy legitimately does not come from protein, fat or
     * carbohydrate, so the cross-check below cannot apply.
     *
     * Ethanol carries 7 kcal per gram and is not a macronutrient, so every one of
     * these reads as calories out of nowhere. Naming them is honest; widening the
     * band until they fit would blind the check for everything else.
     *
     * @var list<string>
     */
    private const array ALCOHOL = [
        'Alkohol mocny',
        'Wódka',
        'Wino białe',
        'Wino czerwone',
        'Piwo',
        'Sake',
    ];

    public function test_every_entry_is_shaped_the_way_the_seeder_expects(): void
    {
        foreach ($this->entries() as $name => $entry) {
            $this->assertIsString($name, 'A product name must be a string.');
            $this->assertGreaterThanOrEqual(4, count($entry), "{$name}: expected [kcal, protein, fat, carbs, key].");
            $this->assertIsNumeric($entry[0], "{$name}: calories must be a number.");
        }
    }

    public function test_no_figure_is_outside_what_food_can_be(): void
    {
        foreach ($this->entries() as $name => [$kcal, $protein, $fat, $carbs]) {
            // Pure fat is 900 and nothing edible is denser; a 100 g portion
            // cannot contain more than 100 g of anything.
            $this->assertGreaterThanOrEqual(0, $kcal, "{$name}: negative calories.");
            $this->assertLessThanOrEqual(900, $kcal, "{$name}: over 900 kcal per 100 g is not food.");

            foreach (['protein' => $protein, 'fat' => $fat, 'carbs' => $carbs] as $macro => $grams) {
                if ($grams === null) {
                    continue;
                }

                $this->assertGreaterThanOrEqual(0, $grams, "{$name}: negative {$macro}.");
                $this->assertLessThanOrEqual(100, $grams, "{$name}: over 100 g of {$macro} in 100 g.");
            }
        }
    }

    public function test_the_calories_agree_with_the_macros(): void
    {
        /*
         * A deliberately wide band, because Atwater's 4/9/4 is an approximation
         * that real food breaks in both directions and for good reasons: fibre
         * is counted as carbohydrate but yields nearer 2 kcal per gram, which
         * puts every ground spice, bran and citrus fruit well under; polyols like
         * xylitol are lower still. The widest honest gap in the file is baking
         * powder at 0.48, and it is mostly mineral.
         *
         * So this is not a check that the figures are precise. It is a check that
         * none of them is out by a factor of ten, which is what a misplaced
         * decimal point does and what no amount of reading the file catches.
         */
        foreach ($this->entries() as $name => [$kcal, $protein, $fat, $carbs]) {
            if (in_array($name, self::ALCOHOL, true) || $protein === null || $fat === null || $carbs === null) {
                continue;
            }

            $fromMacros = 4 * $protein + 9 * $fat + 4 * $carbs;

            // Nothing to compare against: a product with no macros and no
            // calories (salt, water) is consistent by definition.
            if ($fromMacros < 40 && $kcal < 40) {
                continue;
            }

            $this->assertGreaterThan(0, $fromMacros, "{$name}: {$kcal} kcal from no macros at all.");

            $ratio = $kcal / $fromMacros;

            $this->assertGreaterThan(
                0.4,
                $ratio,
                "{$name}: {$kcal} kcal against {$fromMacros} from its macros — a decimal point out?",
            );
            $this->assertLessThan(
                2.0,
                $ratio,
                "{$name}: {$kcal} kcal against {$fromMacros} from its macros — a decimal point out?",
            );
        }
    }

    public function test_no_product_is_listed_twice(): void
    {
        // PHP would silently keep the last of two identical keys, so this reads
        // the file as text. The same trap `IngredientDictionaryTest` covers for
        // the ingredient list, where it found three real duplicates.
        $source = file_get_contents(__DIR__.'/../../database/data/ingredient-nutrition.php');

        preg_match_all("/^    '([^']+)' =>/m", (string) $source, $matches);

        $names = $matches[1];
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'Listed twice: '.implode(', ', $duplicates));
        $this->assertCount(count($this->entries()), $names);
    }

    public function test_the_external_key_is_a_search_term_and_not_an_identifier(): void
    {
        /*
         * The point of the column: somebody later looks these up in USDA or Open
         * Food Facts, and a bare number would look authoritative while resolving
         * to whatever food happened to hold that id. A term is checkable by a
         * human; an invented id is not.
         */
        foreach ($this->entries() as $name => $entry) {
            $key = $entry[4] ?? null;

            if ($key === null) {
                continue;
            }

            $this->assertIsString($key, "{$name}: the external key must be a search term.");
            $this->assertMatchesRegularExpression('/[a-z]{3}/', $key, "{$name}: '{$key}' is not a search term.");
        }
    }

    /**
     * @return array<string, array{0: float, 1: ?float, 2: ?float, 3: ?float, 4?: ?string}>
     */
    private function entries(): array
    {
        return require __DIR__.'/../../database/data/ingredient-nutrition.php';
    }
}
