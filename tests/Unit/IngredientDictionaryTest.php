<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IngredientCategory;
use App\Importing\Parsing\PolishTextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Guards the seed dictionary itself. These ran as a database seeder failure
 * before; as a unit test they fail in a second, without a database.
 */
class IngredientDictionaryTest extends TestCase
{
    /**
     * @var list<array{name: string, category: string, unit?: string, staple?: bool, aliases?: list<string>}>
     */
    private array $entries;

    private PolishTextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entries = require __DIR__.'/../../database/data/ingredients.php';
        $this->normalizer = new PolishTextNormalizer;
    }

    public function test_no_product_is_listed_twice(): void
    {
        $names = array_map(
            fn (array $entry): string => $this->normalizer->normalize($entry['name']),
            $this->entries,
        );

        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'Duplicate product names: '.implode(', ', $duplicates));
    }

    /**
     * A product must own its own name. If another entry claims it as an alias, the
     * dictionary describes the same thing twice and recipe lines silently resolve
     * to the wrong product.
     */
    public function test_no_product_name_is_claimed_as_another_products_alias(): void
    {
        $owners = [];

        foreach ($this->entries as $entry) {
            $owners[$this->normalizer->normalize($entry['name'])] = $entry['name'];
        }

        $conflicts = [];

        foreach ($this->entries as $entry) {
            foreach ($entry['aliases'] ?? [] as $alias) {
                $key = $this->normalizer->normalize($alias);

                if (isset($owners[$key]) && $owners[$key] !== $entry['name']) {
                    $conflicts[] = "'{$alias}' of '{$entry['name']}' collides with product '{$owners[$key]}'";
                }
            }
        }

        $this->assertSame([], $conflicts, implode('; ', $conflicts));
    }

    public function test_no_alias_is_shared_by_two_products(): void
    {
        $claims = [];
        $conflicts = [];

        foreach ($this->entries as $entry) {
            foreach ($entry['aliases'] ?? [] as $alias) {
                $key = $this->normalizer->normalize($alias);

                if (isset($claims[$key]) && $claims[$key] !== $entry['name']) {
                    $conflicts[] = "'{$alias}' claimed by both '{$claims[$key]}' and '{$entry['name']}'";
                }

                $claims[$key] = $entry['name'];
            }
        }

        $this->assertSame([], $conflicts, implode('; ', $conflicts));
    }

    public function test_every_entry_uses_a_known_category_and_unit(): void
    {
        $units = array_column(require __DIR__.'/../../database/data/units.php', 'code');
        $categories = array_column(IngredientCategory::cases(), 'value');

        foreach ($this->entries as $entry) {
            $this->assertContains($entry['category'], $categories, "Bad category on {$entry['name']}");

            if (isset($entry['unit'])) {
                $this->assertContains($entry['unit'], $units, "Bad unit on {$entry['name']}");
            }
        }
    }
}
