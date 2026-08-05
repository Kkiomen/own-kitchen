<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Catalogue\IngredientEmoji;
use App\Enums\IngredientCategory;
use Tests\TestCase;

/**
 * The picture beside a product's name. No database is touched: this is a text
 * rule, and the traps it has to avoid are the substring traps that
 * `database/data/categories.php` already records.
 */
class IngredientEmojiTest extends TestCase
{
    private IngredientEmoji $emoji;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emoji = $this->app->make(IngredientEmoji::class);
    }

    public function test_a_product_gets_its_own_picture(): void
    {
        $this->assertSame('🥕', $this->for('Marchewka', IngredientCategory::Vegetable));
        $this->assertSame('🧄', $this->for('Czosnek', IngredientCategory::Vegetable));
        $this->assertSame('🍅', $this->for('Pomidory', IngredientCategory::Vegetable));
    }

    public function test_declension_does_not_hide_it(): void
    {
        // Every one of these is how the importer actually stores the name.
        $this->assertSame('🧄', $this->for('Ząbki czosnku', IngredientCategory::Vegetable));
        $this->assertSame('🍗', $this->for('Pierś z kurczaka', IngredientCategory::Meat));
        $this->assertSame('🌾', $this->for('Mąki pszennej', IngredientCategory::Grain));
    }

    public function test_a_product_nothing_recognises_keeps_its_category_picture(): void
    {
        $this->assertSame('🥕', $this->for('Tapioka', IngredientCategory::Vegetable));
        $this->assertSame('🧰', $this->for('Papier do pieczenia', IngredientCategory::Equipment));
    }

    public function test_an_unresolved_line_still_gets_a_picture(): void
    {
        $this->assertSame('🍽️', $this->emoji->for(null, null));
    }

    /**
     * The needles are matched longest-first precisely so a specific one can
     * override a broader one. Without it these three are all wrong.
     */
    public function test_a_longer_needle_wins(): void
    {
        $this->assertSame('🌭', $this->for('Serdelki', IngredientCategory::Meat));
        $this->assertSame('🌭', $this->for('Kaszanka', IngredientCategory::Meat));
        $this->assertSame('🌶️', $this->for('Papryka wędzona', IngredientCategory::Spice));
        $this->assertSame('🍪', $this->for('Herbatniki', IngredientCategory::Grain));
        $this->assertSame('🥬', $this->for('Koper włoski', IngredientCategory::Vegetable));
    }

    /**
     * A plain substring search is what put "Coś zupełnie osobliwego" in Soups.
     * The same mechanism here would turn a mackerel into flour.
     */
    public function test_a_needle_never_matches_inside_another_word(): void
    {
        $this->assertSame('🐟', $this->for('Makrela', IngredientCategory::Fish));
        $this->assertSame('🍝', $this->for('Makaron penne', IngredientCategory::Grain));
        $this->assertSame('🥒', $this->for('Cukinia', IngredientCategory::Vegetable));
        $this->assertSame('🍇', $this->for('Winogrona', IngredientCategory::Fruit));
    }

    /**
     * Three needles that are only safe because they are whole words. As stems
     * they would each swallow an unrelated product, so if any of them is ever
     * given a `*` these are the assertions that will say so.
     */
    public function test_the_short_whole_word_needles_stay_contained(): void
    {
        // "mak" must not reach makaron or makrela — see the file's own warning.
        $this->assertSame('🌰', $this->for('Mak', IngredientCategory::NutSeed));
        $this->assertSame('🍝', $this->for('Makaron', IngredientCategory::Grain));

        // "lod" must not reach "łodyga", which normalises to "lodyga".
        $this->assertSame('🧊', $this->for('Lód', IngredientCategory::Beverage));
        $this->assertSame('🥬', $this->for('Łodyga selera', IngredientCategory::Vegetable));

        // "ziele" must not reach "zielona" or "zielenina".
        $this->assertSame('🧂', $this->for('Ziele angielskie', IngredientCategory::Spice));
        $this->assertSame('🫑', $this->for('Papryka zielona', IngredientCategory::Vegetable));
    }

    /**
     * In Polish a "pasta" is a spread, which is why `categories.php` had to drop
     * it from Makarony. Here that same meaning is the correct one.
     */
    public function test_a_polish_pasta_is_a_spread(): void
    {
        $this->assertSame('🥫', $this->for('Pasta miso', IngredientCategory::Sauce));
        $this->assertSame('🥫', $this->for('Pasta warzywna', IngredientCategory::Sauce));

        // And a spread named after what is in it keeps that, which is better
        // still: "Pasta z makreli" is a fish, not an anonymous jar.
        $this->assertSame('🐟', $this->for('Pasta z makreli', IngredientCategory::Sauce));
    }

    public function test_seafood_outranks_the_catch_all_for_fruit(): void
    {
        $this->assertSame('🍎', $this->for('Świeże owoce', IngredientCategory::Fruit));
        $this->assertSame('🦐', $this->for('Owoce morza mieszanka', IngredientCategory::Fish));
    }

    /**
     * Polish drops the stem vowel in the genitive plural, so a prefix built from
     * the nominative silently misses it. These are the ones real data contains.
     */
    public function test_the_genitive_plural_is_covered_too(): void
    {
        $this->assertSame('🍗', $this->for('Skrzydełek kurczaka', IngredientCategory::Meat));
        $this->assertSame('🍞', $this->for('Grzanek', IngredientCategory::Grain));
        $this->assertSame('🥣', $this->for('Otrąb owsianych', IngredientCategory::Grain));
    }

    public function test_every_category_has_a_picture(): void
    {
        foreach (IngredientCategory::cases() as $category) {
            $this->assertNotSame('', $category->emoji(), $category->value.' has none');
        }
    }

    private function for(string $name, IngredientCategory $category): string
    {
        return $this->emoji->for($name, $category);
    }
}
