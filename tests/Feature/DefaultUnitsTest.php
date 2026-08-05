<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogue\DefaultUnits;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unit offered when a product is put on a shelf. Getting it wrong is not
 * cosmetic: an amount without a unit is refused, so a product with no default
 * costs three taps and a guess every time it is bought.
 */
class DefaultUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
    }

    public function test_a_product_takes_the_unit_recipes_measure_it_in(): void
    {
        $flour = $this->product('mąka');

        $this->measured($flour, 'g', 5);
        $this->measured($flour, 'tbsp', 2);

        $this->fill();

        $this->assertSame($this->unitId('g'), $flour->fresh()->default_unit_id);
    }

    /**
     * Nobody stocks "1 szczypta" of anything. An exact unit therefore wins over
     * an approximate one however rare it is.
     */
    public function test_an_exact_unit_beats_a_more_common_approximate_one(): void
    {
        $salt = $this->product('sól');

        $this->measured($salt, 'pinch', 40);
        $this->measured($salt, 'g', 1);

        $this->fill();

        $this->assertSame($this->unitId('g'), $salt->fresh()->default_unit_id);
    }

    public function test_an_approximate_unit_is_kept_when_it_is_all_there_is(): void
    {
        $parsley = $this->product('natka pietruszki');

        $this->measured($parsley, 'handful', 3);

        $this->fill();

        $this->assertSame($this->unitId('handful'), $parsley->fresh()->default_unit_id);
    }

    /**
     * The dictionary is the authority for what it covers, so usage may fill a
     * gap but must never quietly overrule a curated answer.
     */
    public function test_a_curated_unit_is_left_alone(): void
    {
        $egg = $this->product('jajko', source: IngredientSource::Dictionary, unit: 'piece');

        $this->measured($egg, 'g', 50);

        $this->fill();

        $this->assertSame($this->unitId('piece'), $egg->fresh()->default_unit_id);
    }

    public function test_overwrite_replaces_even_a_curated_unit(): void
    {
        $egg = $this->product('jajko', source: IngredientSource::Dictionary, unit: 'piece');

        $this->measured($egg, 'g', 50);

        $this->fill(overwrite: true);

        $this->assertSame($this->unitId('g'), $egg->fresh()->default_unit_id);
    }

    /**
     * No recipe measures it, so there is nothing to derive — and a guessed unit
     * would be worse than none.
     */
    public function test_a_product_no_recipe_measures_keeps_no_unit(): void
    {
        $obscure = $this->product('kombu');

        $this->fill();

        $this->assertNull($obscure->fresh()->default_unit_id);
    }

    public function test_lines_with_no_unit_do_not_count(): void
    {
        $pepper = $this->product('pieprz');

        $this->measured($pepper, null, 10);
        $this->measured($pepper, 'g', 1);

        $this->fill();

        $this->assertSame($this->unitId('g'), $pepper->fresh()->default_unit_id);
    }

    public function test_the_kitchen_offers_the_derived_unit(): void
    {
        $flour = $this->product('mąka');
        $this->measured($flour, 'g', 3);

        $this->fill();

        $this->actingAs(User::factory()->create())
            ->get(route('pantry.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'ingredients.0.defaultUnitId',
                $this->unitId('g'),
            ));
    }

    private function fill(bool $overwrite = false): int
    {
        return $this->app->make(DefaultUnits::class)->fill($overwrite);
    }

    private function product(
        string $name,
        IngredientSource $source = IngredientSource::Import,
        ?string $unit = null,
    ): Ingredient {
        return Ingredient::query()->create([
            'slug' => str($name)->slug()->value(),
            'name' => $name,
            'category' => IngredientCategory::Other,
            'source' => $source,
            'default_unit_id' => $unit === null ? null : $this->unitId($unit),
        ]);
    }

    /** Writes `$times` recipe lines measuring `$product` in `$unit`. */
    private function measured(Ingredient $product, ?string $unit, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $recipe = Recipe::query()->create([
                'slug' => $product->slug.'-'.($unit ?? 'none').'-'.$i,
                'title' => 'Danie '.$i,
                'source_name' => 'example.test',
                'source_url' => 'https://example.test/'.$product->slug.'-'.($unit ?? 'none').'-'.$i,
            ]);

            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'ingredient_id' => $product->id,
                'unit_id' => $unit === null ? null : $this->unitId($unit),
                'quantity' => 1,
                'raw_text' => $product->name,
                'position' => 0,
            ]);
        }
    }

    private function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->value('id');
    }
}
