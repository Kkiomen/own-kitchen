<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Parsing\PolishTextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Guards the weights file against the two ways it can rot: a product renamed out
 * from under it, and a unit that is not in the closed vocabulary.
 *
 * The seeder throws on both, but it needs a database and a full seed to get
 * there. These fail in a second.
 */
class IngredientMeasureDictionaryTest extends TestCase
{
    /**
     * @var array<string, array{density?: float, grams?: array<string, float>}>
     */
    private array $measures;

    /**
     * @var list<string>
     */
    private array $productNames;

    /**
     * @var list<string>
     */
    private array $unitCodes;

    protected function setUp(): void
    {
        parent::setUp();

        $normalizer = new PolishTextNormalizer;

        $this->measures = require __DIR__.'/../../database/data/ingredient-measures.php';

        /** @var list<array{name: string}> $products */
        $products = require __DIR__.'/../../database/data/ingredients.php';
        $this->productNames = array_map(
            static fn (array $entry): string => $normalizer->normalize($entry['name']),
            $products,
        );

        /** @var list<array{code: string}> $units */
        $units = require __DIR__.'/../../database/data/units.php';
        $this->unitCodes = array_map(static fn (array $unit): string => $unit['code'], $units);
    }

    public function test_every_weight_names_a_product_that_exists(): void
    {
        $normalizer = new PolishTextNormalizer;

        foreach (array_keys($this->measures) as $name) {
            $this->assertContains(
                $normalizer->normalize($name),
                $this->productNames,
                "'{$name}' has weights but is not a product in database/data/ingredients.php.",
            );
        }
    }

    /**
     * Units are a closed vocabulary. An unknown code here is a typo, not data,
     * and it would silently leave every line using it unconvertible.
     */
    public function test_every_weight_uses_a_unit_from_the_vocabulary(): void
    {
        foreach ($this->measures as $name => $entry) {
            foreach (array_keys($entry['grams'] ?? []) as $code) {
                $this->assertContains($code, $this->unitCodes, "Unknown unit '{$code}' on '{$name}'.");
            }
        }
    }

    public function test_no_weight_is_zero_or_negative(): void
    {
        foreach ($this->measures as $name => $entry) {
            foreach ($entry['grams'] ?? [] as $code => $grams) {
                $this->assertGreaterThan(0, $grams, "'{$name}' weighs {$grams} g per {$code}.");
            }
        }
    }

    /**
     * Nothing in a kitchen is lighter than aerated cocoa or heavier than honey by
     * much. A density outside this range is a decimal point in the wrong place,
     * and it would be invisible in use — just quietly wrong amounts.
     */
    public function test_densities_are_physically_plausible(): void
    {
        foreach ($this->measures as $name => $entry) {
            if (! isset($entry['density'])) {
                continue;
            }

            $this->assertGreaterThanOrEqual(0.2, $entry['density'], "Density of '{$name}' looks too low.");
            $this->assertLessThanOrEqual(1.6, $entry['density'], "Density of '{$name}' looks too high.");
        }
    }

    /**
     * The whole file is one product per key. A duplicate would silently win or
     * lose depending on which PHP kept, which is exactly the ambiguity the
     * catalogue exists to avoid.
     */
    public function test_no_product_is_weighed_twice(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../database/data/ingredient-measures.php');

        preg_match_all("/^ {4}'([^']+)' => \[/m", $raw, $matches);

        $duplicates = array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1,
        ));

        $this->assertSame([], $duplicates, 'Listed twice: '.implode(', ', $duplicates));
    }
}
