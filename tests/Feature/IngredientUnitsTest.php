<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogue\IngredientUnits;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Models\Ingredient;
use App\Models\IngredientMeasure;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Models\User;
use App\Support\Measurement\MeasureBook;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Which measures a product's unit picker offers first.
 *
 * The vocabulary is closed but it is still twenty-one measures long, and
 * offering all of them to every product is how a tub of yoghurt gets written
 * down in ząbki. Nothing here forbids a measure — the rules are about what is
 * put in front of somebody first.
 */
class IngredientUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
    }

    public function test_a_product_is_offered_the_measures_recipes_use_for_it(): void
    {
        $yoghurt = $this->product('jogurt');

        $this->measured($yoghurt, 'g', 40);
        $this->measured($yoghurt, 'tbsp', 20);

        $offered = $this->offered($yoghurt);

        $this->assertContains($this->unitId('g'), $offered);
        $this->assertContains($this->unitId('tbsp'), $offered);
        // Nobody has ever measured yoghurt in ząbki, and until somebody does it
        // has no business being the tap above "gram".
        $this->assertNotContains($this->unitId('clove'), $offered);
    }

    public function test_the_commonest_measure_comes_first(): void
    {
        $milk = $this->product('mleko');

        $this->measured($milk, 'tbsp', 5);
        $this->measured($milk, 'ml', 50);

        $this->assertSame($this->unitId('ml'), $this->offered($milk)[0]);
    }

    public function test_a_measure_one_recipe_used_once_is_not_offered(): void
    {
        // Cheese is written down in millilitres exactly once in ~1 400 lines.
        // That is one recipe's oddity, not a way cheese is measured.
        $cheese = $this->product('ser żółty');

        $this->measured($cheese, 'g', 100);
        $this->measured($cheese, 'ml', 1);

        $this->assertNotContains($this->unitId('ml'), $this->offered($cheese));
    }

    public function test_an_approximate_measure_is_never_offered(): void
    {
        // A szczypta, a kropla and a garść describe cooking, never a shelf.
        $salt = $this->product('sól');

        $this->measured($salt, 'pinch', 80);
        $this->measured($salt, 'tsp', 10);

        $offered = $this->offered($salt);

        $this->assertNotContains($this->unitId('pinch'), $offered);
        $this->assertContains($this->unitId('tsp'), $offered);
    }

    public function test_pack_sizes_are_offered_beside_the_measures_recipes_use(): void
    {
        /*
         * A fridge holds a kilo of what recipes count in grams and a litre of
         * what they count in millilitres. Those convert exactly, so offering
         * them costs nothing and saves typing "1000".
         */
        $flour = $this->product('mąka');

        $this->measured($flour, 'g', 30);

        $this->assertContains($this->unitId('kg'), $this->offered($flour));

        $milk = $this->product('mleko');

        $this->measured($milk, 'ml', 30);

        $this->assertContains($this->unitId('l'), $this->offered($milk));
    }

    public function test_a_measure_the_product_has_been_weighed_in_is_offered(): void
    {
        // A slice of cheese is a thing on a shelf, and one that converts by
        // definition — the weight of it is recorded.
        $cheese = $this->product('ser żółty');

        $this->measured($cheese, 'g', 30);
        $this->weighed($cheese, 'slice', 20.0);

        $this->assertContains($this->unitId('slice'), $this->offered($cheese));
    }

    public function test_a_product_nobody_has_measured_has_no_opinion(): void
    {
        // Absent rather than present with an empty list: its picker goes on
        // showing the whole vocabulary, which is the honest answer.
        $this->product('coś nowego');

        $this->assertSame([], $this->shortlists());
    }

    public function test_the_measures_nothing_can_weigh_are_named(): void
    {
        /*
         * Holding two słoiki of something is a fact worth writing down; it is
         * just not a fact any recipe's grams can be compared with. Saying so is
         * the same "cannot tell" rule `IngredientMeasures::covers()` keeps,
         * moved to where somebody is choosing.
         */
        $jam = $this->product('dżem');

        $this->measured($jam, 'g', 30);
        $this->measured($jam, 'jar', 10);

        $shortlist = $this->shortlists()[$jam->id];

        $this->assertContains($this->unitId('jar'), $shortlist['unconvertible']);
        // A mass always converts, whatever we know about the product.
        $this->assertNotContains($this->unitId('g'), $shortlist['unconvertible']);
        $this->assertNotContains($this->unitId('kg'), $shortlist['unconvertible']);
    }

    public function test_a_weighed_measure_is_not_named_as_unconvertible(): void
    {
        $jam = $this->product('dżem');

        $this->measured($jam, 'g', 30);
        $this->measured($jam, 'jar', 10);
        $this->weighed($jam, 'jar', 280.0);

        $this->assertNotContains(
            $this->unitId('jar'),
            $this->shortlists()[$jam->id]['unconvertible'],
        );
    }

    public function test_the_kitchen_screen_is_told_which_measures_to_offer(): void
    {
        // The picker is in the browser, so a shortlist the page never receives
        // is a shortlist that does nothing.
        $yoghurt = $this->product('jogurt');

        $this->measured($yoghurt, 'g', 40);

        $this->actingAs(User::factory()->create())
            ->get(route('pantry.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ingredients.0.name', 'jogurt')
                ->where('ingredients.0.unitIds', [$this->unitId('g'), $this->unitId('kg')]));
    }

    /**
     * @return list<int>
     */
    private function offered(Ingredient $product): array
    {
        return $this->shortlists()[$product->id]['units'] ?? [];
    }

    /**
     * @return array<int, array{units: list<int>, unconvertible: list<int>}>
     */
    private function shortlists(): array
    {
        // The book is a singleton and these tests write measures after it could
        // have been read; only a test needs to say so.
        $this->app->make(MeasureBook::class)->forget();

        return $this->app->make(IngredientUnits::class)->all();
    }

    private function product(string $name): Ingredient
    {
        return Ingredient::query()->create([
            'slug' => str($name)->slug()->value(),
            'name' => $name,
            'category' => IngredientCategory::Other,
            'source' => IngredientSource::Import,
        ]);
    }

    /** Writes `$times` recipe lines measuring `$product` in `$unit`. */
    private function measured(Ingredient $product, string $unit, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $recipe = Recipe::query()->create([
                'slug' => $product->slug.'-'.$unit.'-'.$i,
                'title' => 'Danie '.$i,
                'source_name' => 'example.test',
                'source_url' => 'https://example.test/'.$product->slug.'-'.$unit.'-'.$i,
            ]);

            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'ingredient_id' => $product->id,
                'unit_id' => $this->unitId($unit),
                'quantity' => 1,
                'raw_text' => $product->name,
                'position' => 0,
            ]);
        }
    }

    private function weighed(Ingredient $product, string $unit, float $grams): void
    {
        IngredientMeasure::query()->create([
            'ingredient_id' => $product->id,
            'unit_id' => $this->unitId($unit),
            'grams' => $grams,
        ]);
    }

    private function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->value('id');
    }
}
