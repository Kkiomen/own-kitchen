<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Reading a recipe for a different number of portions.
 *
 * The specification is not "the numbers get multiplied" — it is that *every*
 * answer on the screen moves together. The amounts, the marker saying whether
 * the kitchen covers a line, and what the shopping button writes down all come
 * from the same lines, and a version of this feature that scaled only the first
 * would be wrong in the way nobody notices until they are at the hob.
 */
class RecipeScalingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->user = User::factory()->create();
    }

    public function test_amounts_are_shown_for_the_portions_asked_for(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej', '2 jajka'], servings: 4);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=6')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.base', 4)
                ->where('recipe.scale.servings', 6)
                ->where('recipe.scale.isScaled', true)
                ->where('recipe.ingredients.0.quantity', $this->amount(750))
                ->where('recipe.ingredients.1.quantity', $this->amount(3)),
            );
    }

    public function test_the_recipe_reads_as_written_when_nothing_is_asked_for(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.servings', 4)
                ->where('recipe.scale.isScaled', false)
                ->where('recipe.ingredients.0.quantity', $this->amount(500)),
            );
    }

    /**
     * 78 of ~10 200 recipes never said how many portions they make. There is no
     * factor to compute, so the screen is told not to offer the control at all.
     */
    public function test_a_recipe_that_never_stated_its_portions_cannot_be_scaled(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: null);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=12')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.base', null)
                ->where('recipe.scale.servings', null)
                ->where('recipe.scale.isScaled', false)
                ->where('recipe.ingredients.0.quantity', $this->amount(500)),
            );
    }

    /**
     * The verbatim source line is the evidence an import is checked against, and
     * "pokaż oryginalny tekst" is where it is read. Scaling it would destroy the
     * only thing on the screen that is supposed to disagree.
     */
    public function test_the_original_text_of_a_line_is_never_scaled(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=8')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.ingredients.0.quantity', $this->amount(1000))
                ->where('recipe.ingredients.0.rawText', '500 g mąki pszennej'),
            );
    }

    /**
     * The guided-cooking lines are a second hydration of the same rows. Scaling
     * only the ingredient list would leave a step saying "dodaj 500 g mąki"
     * under a list that reads 1 kg.
     */
    public function test_the_amounts_inside_a_step_are_scaled_too(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4, step: 'Wsyp mąkę do miski.');

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=8')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.steps.0.uses.0.quantity', $this->amount(1000)),
            );
    }

    /**
     * The kitchen holds enough for the recipe as written and not enough for
     * twice it. The marker beside the line has to say so, or a cook doubling a
     * dish is told they have everything and finds out at the hob.
     */
    public function test_the_kitchen_is_judged_against_the_scaled_amount(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);
        $this->hold('Mąka pszenna', 600, 'g');

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.ingredients.0.status', 'held'),
            );

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=8')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.ingredients.0.status', 'not_enough'),
            );
    }

    /**
     * The one that would be invisible: the screen shows the doubled amounts and
     * the button underneath it buys for the original recipe.
     */
    public function test_the_shopping_list_buys_for_the_portions_on_screen(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);

        $this->actingAs($this->user)
            ->post(route('shopping.recipe', $recipe), ['porcje' => 8])
            ->assertRedirect();

        $this->assertSame(1000.0, ShoppingListItem::query()->firstOrFail()->quantity);
    }

    /** Only the shortfall, still — the scale changes the amount, not the rule. */
    public function test_only_the_shortfall_is_bought_when_scaled(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);
        $this->hold('Mąka pszenna', 600, 'g');

        $this->actingAs($this->user)
            ->post(route('shopping.recipe', $recipe), ['porcje' => 8]);

        // 1 kg wanted, 600 g on the shelf.
        $this->assertSame(400.0, ShoppingListItem::query()->firstOrFail()->quantity);
    }

    /** A typo in a shared URL shows a recipe, never an error page. */
    public function test_a_nonsense_number_of_portions_falls_back_to_the_recipe(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=nonsense')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.servings', 4)
                ->where('recipe.ingredients.0.quantity', $this->amount(500)),
            );
    }

    /** Clamped rather than refused, at both ends. */
    public function test_the_number_of_portions_is_clamped(): void
    {
        $recipe = $this->recipe(['500 g mąki pszennej'], servings: 4);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=0')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.servings', 1),
            );

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe).'?porcje=9999')
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipe.scale.servings', 50),
            );
    }

    /**
     * PHP's `json_encode` writes an integral float without its `.0`, so 750.0
     * arrives back as an int. The feature is about the number, not its PHP type.
     *
     * @return callable(mixed): bool
     */
    private function amount(float $expected): callable
    {
        return static fn (mixed $actual): bool => is_numeric($actual)
            && abs((float) $actual - $expected) < 0.0001;
    }

    private function hold(string $name, float $quantity, string $unitCode): void
    {
        PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => StorageLocation::Fridge,
            'quantity' => $quantity,
            'unit_id' => $this->unit($unitCode)->id,
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  list<string>  $lines
     */
    private function recipe(array $lines, ?int $servings, string $step = 'Wymieszać.'): Recipe
    {
        return $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: 'ciasto',
                title: 'Ciasto',
                sourceUrl: 'https://example.test/ciasto',
                ingredientLines: array_map(
                    static fn (string $line): IngredientLineDraft => new IngredientLineDraft($line),
                    $lines,
                ),
                steps: [new StepDraft($step)],
                servings: $servings,
            ),
            'example.test',
        );
    }
}
