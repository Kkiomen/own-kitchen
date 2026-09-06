<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Models\Ingredient;
use App\Models\IngredientMeasure;
use App\Models\IngredientNutrition;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Nutrition\NutritionBook;
use App\Nutrition\RecipeEnergy;
use App\Nutrition\RecipeNutrition;
use App\Support\Measurement\MeasureBook;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a recipe is worth in calories, and — the half that matters more — when it
 * refuses to say.
 *
 * Every rule pinned here exists because the alternative is a plan that looks
 * right and quietly under-feeds the household. A missing figure counted as zero
 * does not read as a gap on screen; it reads as a light meal.
 */
class RecipeNutritionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
    }

    public function test_it_adds_up_a_recipe_from_grams(): void
    {
        $recipe = $this->recipe(servings: 2);

        // 200 g of curd at 133 kcal/100 g, plus 50 g of sugar at 400.
        $this->line($recipe, $this->food('twaróg', 133), 200, 'g');
        $this->line($recipe, $this->food('cukier', 400), 50, 'g');

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(466.0, $energy->total->kcal, 0.01);
        $this->assertEqualsWithDelta(233.0, $energy->perPortion->kcal, 0.01);
        $this->assertTrue($energy->isReliable());
    }

    public function test_it_converts_a_counted_amount_through_its_weight(): void
    {
        // The whole reason `ingredient_measures` and this module are separate
        // classes: "2 cebule" is not a weight until somebody says what an onion
        // weighs, and only then is it calories.
        $recipe = $this->recipe(servings: 1);
        $onion = $this->food('cebula', 40);

        $this->weighed($onion, 'piece', 150);
        $this->line($recipe, $onion, 2, 'piece');

        $this->assertEqualsWithDelta(120.0, $this->energy($recipe)->total->kcal, 0.01);
    }

    public function test_a_product_with_no_figure_is_unread_rather_than_free(): void
    {
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->product('masło'), 100, 'g');

        $energy = $this->energy($recipe);

        // The butter is missing from the total, and the coverage says so. Counted
        // as zero it would report a plausible 400 kcal for a dish that is 1 117.
        $this->assertEqualsWithDelta(400.0, $energy->total->kcal, 0.01);
        $this->assertEqualsWithDelta(0.5, $energy->coverage, 0.001);
        $this->assertFalse($energy->isReliable());
        $this->assertSame(['masło'], $energy->unknown);
    }

    public function test_an_amount_that_cannot_reach_grams_is_unread(): void
    {
        // The figure is known and the line states an amount; nobody has said what
        // one of them weighs, so there is still no calorie to be had.
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->food('cytryna', 29), 1, 'piece');

        $this->assertEqualsWithDelta(0.5, $this->energy($recipe)->coverage, 0.001);
    }

    public function test_an_ingredient_named_without_an_amount_counts_against_the_recipe(): void
    {
        /*
         * The correction that mattered most. Excusing these let 4 412 recipes in
         * the real catalogue report full coverage with their frying missing —
         * oil alone is named without an amount on 1 017 lines.
         */
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->food('olej rzepakowy', 884, IngredientCategory::Fat), null, null);

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(0.5, $energy->coverage, 0.001);
        $this->assertFalse($energy->isReliable());
    }

    public function test_an_unmeasured_spice_does_not_count_against_the_recipe(): void
    {
        // "Sól do smaku" is the commonest line in the catalogue and is worth no
        // calories at any amount somebody might have meant. The asymmetry with
        // the fat above is deliberate and is the point of both tests.
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->food('sól', 0, IngredientCategory::Spice), null, null);
        $this->line($recipe, $this->food('natka pietruszki', 36, IngredientCategory::Herb), null, null);

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(1.0, $energy->coverage, 0.001);
        $this->assertTrue($energy->isReliable());
    }

    public function test_an_optional_line_is_neither_counted_nor_held_against_it(): void
    {
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->product('rodzynki'), 50, 'g', optional: true);

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(400.0, $energy->total->kcal, 0.01);
        $this->assertEqualsWithDelta(1.0, $energy->coverage, 0.001);
    }

    public function test_a_recipe_that_never_stated_its_portions_offers_none(): void
    {
        // 78 of ~10 200. Dividing by a guessed number of portions is the same
        // invention `PlannedIngredients` refuses to make when it will not scale
        // these recipes.
        $recipe = $this->recipe(servings: null);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(400.0, $energy->total->kcal, 0.01);
        $this->assertNull($energy->perPortion);
        $this->assertNull($energy->reliableKcalPerPortion());
    }

    public function test_a_partly_read_recipe_is_not_offered_to_the_planner(): void
    {
        // The screen may show a figure with a caveat; a plan has to be able to
        // add up, so this is the narrower of the two answers.
        $recipe = $this->recipe(servings: 2);

        $this->line($recipe, $this->food('cukier', 400), 100, 'g');
        $this->line($recipe, $this->product('masło'), 100, 'g');

        $energy = $this->energy($recipe);

        $this->assertNotNull($energy->perPortion);
        $this->assertNull($energy->reliableKcalPerPortion());
    }

    public function test_an_unknown_macro_is_unknown_rather_than_zero(): void
    {
        // Adding a null as zero would report a week as short of protein because
        // one reading was incomplete — a wrong number that looks like a finding.
        $recipe = $this->recipe(servings: 1);

        $this->line($recipe, $this->food('cukier', 400, protein: 0.0), 100, 'g');
        $this->line($recipe, $this->food('twaróg', 133, protein: null), 100, 'g');

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(533.0, $energy->total->kcal, 0.01);
        $this->assertNull($energy->total->protein);
    }

    public function test_a_recipe_with_nothing_countable_is_covered_rather_than_unknown(): void
    {
        $recipe = $this->recipe(servings: 2);

        $this->line($recipe, $this->food('sól', 0, IngredientCategory::Spice), null, null);

        $energy = $this->energy($recipe);

        $this->assertEqualsWithDelta(1.0, $energy->coverage, 0.001);
        $this->assertEqualsWithDelta(0.0, $energy->total->kcal, 0.01);
    }

    private function energy(Recipe $recipe): RecipeEnergy
    {
        // Both books are singletons and these tests write their data after the
        // container could have read them; only a test needs to say so.
        $this->app->make(MeasureBook::class)->forget();
        $this->app->make(NutritionBook::class)->forget();

        return $this->app->make(RecipeNutrition::class)
            ->for($recipe->load(['ingredients.ingredient', 'ingredients.unit']));
    }

    private function recipe(?int $servings): Recipe
    {
        return Recipe::query()->create([
            'slug' => 'danie-'.Recipe::query()->count(),
            'title' => 'Danie',
            'servings' => $servings,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/danie',
        ]);
    }

    private function product(string $name, IngredientCategory $category = IngredientCategory::Other): Ingredient
    {
        return Ingredient::query()->create([
            'slug' => str($name)->slug()->value(),
            'name' => $name,
            'category' => $category,
            'source' => IngredientSource::Import,
        ]);
    }

    /** A product that also has a figure per 100 g. */
    private function food(
        string $name,
        float $kcal,
        IngredientCategory $category = IngredientCategory::Other,
        ?float $protein = 0.0,
    ): Ingredient {
        $product = $this->product($name, $category);

        IngredientNutrition::query()->create([
            'ingredient_id' => $product->id,
            'kcal_per_100g' => $kcal,
            'protein_g_per_100g' => $protein,
        ]);

        return $product;
    }

    private function weighed(Ingredient $product, string $unit, float $grams): void
    {
        IngredientMeasure::query()->create([
            'ingredient_id' => $product->id,
            'unit_id' => $this->unitId($unit),
            'grams' => $grams,
        ]);
    }

    private function line(
        Recipe $recipe,
        Ingredient $product,
        ?float $quantity,
        ?string $unit,
        bool $optional = false,
    ): void {
        RecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'ingredient_id' => $product->id,
            'unit_id' => $unit === null ? null : $this->unitId($unit),
            'quantity' => $quantity,
            'raw_text' => $product->name,
            'position' => $recipe->ingredients()->count(),
            'is_optional' => $optional,
        ]);
    }

    private function unitId(string $code): int
    {
        return (int) Unit::query()->where('code', $code)->value('id');
    }
}
