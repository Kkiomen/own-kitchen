<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens when the dictionary grows to cover a phrase the importer had already
 * invented a product for. Curating that phrase is the whole point of the review
 * queue, so re-seeding has to make the curated entry win.
 */
class IngredientCurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
    }

    public function test_a_dictionary_entry_takes_an_alias_back_from_a_product_the_importer_invented(): void
    {
        $invented = $this->inventedProduct('serka śmietankowego');

        $this->seed(IngredientSeeder::class);

        $alias = IngredientAlias::query()->where('alias', 'serka smietankowego')->firstOrFail();

        $this->assertSame('Serek kremowy', $alias->ingredient->name);
        $this->assertSame(IngredientSource::Dictionary, $alias->ingredient->source);
        $this->assertNull(Ingredient::query()->find($invented->id), 'The invention held nothing else and should be gone.');
    }

    /**
     * Splitting a variety out of a generic product means moving a spelling from
     * one entry to another, and the dictionary could only ever add.
     *
     * "fasoli czerwonej" was resolving to plain Fasola, so Fasola czerwona was
     * given its own entry — and the seeder refused to run at all, because the new
     * product could not claim its own name from the old one. An alias deleted
     * from the file has to actually leave the database.
     */
    public function test_a_spelling_removed_from_the_dictionary_stops_being_an_alias(): void
    {
        $this->seed(IngredientSeeder::class);

        $onion = Ingredient::query()->where('name', 'Cebula')->firstOrFail();

        // A spelling the file does not list for it, as a stale row would look.
        IngredientAlias::query()->create([
            'ingredient_id' => $onion->id,
            'alias' => 'cebula z poprzedniego slownika',
        ]);

        $this->seed(IngredientSeeder::class);

        $this->assertDatabaseMissing('ingredient_aliases', ['alias' => 'cebula z poprzedniego slownika']);
        // The canonical name is never pruned: nothing in the file lists it either.
        $this->assertDatabaseHas('ingredient_aliases', ['alias' => 'cebula', 'ingredient_id' => $onion->id]);
    }

    /**
     * Repointing the alias alone would leave the recipe pointing at a product that
     * no longer exists, and leave it flagged for a review that has just happened.
     */
    public function test_the_lines_that_used_the_invented_product_follow_it_and_stop_needing_review(): void
    {
        $invented = $this->inventedProduct('serka śmietankowego');
        $line = $this->lineUsing($invented);

        $this->seed(IngredientSeeder::class);

        $line->refresh();

        $this->assertSame('Serek kremowy', $line->ingredient?->name);
        $this->assertFalse($line->needs_review);
    }

    /**
     * The invention is only retired once nothing points at it. A phrase the
     * dictionary has not covered still belongs in the queue.
     */
    public function test_an_invented_product_survives_while_it_still_holds_an_uncurated_phrase(): void
    {
        $invented = $this->inventedProduct('serka śmietankowego');
        IngredientAlias::query()->create([
            'ingredient_id' => $invented->id,
            'alias' => 'czegos czego nie ma w slowniku',
        ]);

        $this->seed(IngredientSeeder::class);

        $this->assertSame(
            IngredientSource::Import,
            Ingredient::query()->findOrFail($invented->id)->source,
        );
    }

    private function inventedProduct(string $name): Ingredient
    {
        $ingredient = Ingredient::query()->create([
            'slug' => 'serka-smietankowego',
            'name' => $name,
            'category' => IngredientCategory::Other,
            'source' => IngredientSource::Import,
        ]);

        IngredientAlias::query()->create([
            'ingredient_id' => $ingredient->id,
            'alias' => 'serka smietankowego',
        ]);

        return $ingredient;
    }

    private function lineUsing(Ingredient $ingredient): RecipeIngredient
    {
        $recipe = Recipe::query()->create([
            'slug' => 'kanapka',
            'title' => 'Kanapka',
            'source_name' => 'example.test',
        ]);

        return RecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'ingredient_id' => $ingredient->id,
            'raw_text' => '25 g serka śmietankowego',
            'quantity' => 25,
            'position' => 0,
            'needs_review' => true,
        ]);
    }
}
