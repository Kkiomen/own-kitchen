<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Cooking one dish, one step at a time. The screen's own behaviour — the timer,
 * the deadline that survives being backgrounded — lives in the browser; what is
 * pinned here is that the page is served, scoped to a signed-in household, and
 * carries everything the cook needs to stand at the hob with it.
 */
class CookingModeTest extends TestCase
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

    public function test_a_recipe_can_be_cooked_step_by_step(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->get(route('cooking.show', $recipe))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Cooking/Show')
                ->where('recipe.title', 'Cukinia z cebulą')
                ->has('recipe.steps', 2),
            );
    }

    /**
     * The timer is offered for the step's own stated time and nothing else — a
     * step that says nothing about waiting must not get a clock invented for it.
     */
    public function test_a_step_carries_the_wait_it_stated(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->get(route('cooking.show', $recipe))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recipe.steps.0.durationSeconds', null)
                ->where('recipe.steps.1.durationSeconds', 600),
            );
    }

    /**
     * What the step consumes, so "ADD 150 g" is possible instead of replaying
     * prose. An unresolved line still travels, as its original wording.
     */
    public function test_a_step_carries_the_lines_it_uses(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->get(route('cooking.show', $recipe))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recipe.steps.0.uses.0', fn (AssertableInertia $line) => $line
                    ->where('name', 'Cukinia')
                    ->where('quantity', 300)
                    ->where('unit', 'g')
                    ->etc(),
                )
                ->etc(),
            );
    }

    /**
     * The portions come along. Reading a recipe scaled to six and then being
     * walked through the amounts for three is the one way this screen could be
     * actively wrong, so the same `?porcje=` the recipe screen reads applies here.
     */
    public function test_the_portions_carry_over_from_the_recipe(): void
    {
        $recipe = $this->recipe();
        $recipe->update(['servings' => 2]);

        $this->actingAs($this->user)
            ->get(route('cooking.show', ['recipe' => $recipe, 'porcje' => 4]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recipe.servings', 4)
                ->where('recipe.isScaled', true)
                ->where('recipe.steps.0.uses.0.quantity', 600)
                ->etc(),
            );
    }

    public function test_a_recipe_that_never_stated_its_portions_is_not_scaled(): void
    {
        $recipe = $this->recipe();
        $recipe->update(['servings' => null]);

        $this->actingAs($this->user)
            ->get(route('cooking.show', ['recipe' => $recipe, 'porcje' => 4]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recipe.isScaled', false)
                ->where('recipe.steps.0.uses.0.quantity', 300)
                ->etc(),
            );
    }

    public function test_cooking_is_for_the_household_only(): void
    {
        $this->get(route('cooking.show', $this->recipe()))->assertRedirect(route('login'));
    }

    private function recipe(): Recipe
    {
        $recipe = $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: 'cukinia-z-cebula',
                title: 'Cukinia z cebulą',
                sourceUrl: 'https://example.test/cukinia',
                ingredientLines: [
                    new IngredientLineDraft('300 g cukinii'),
                    new IngredientLineDraft('1 cebula'),
                ],
                /*
                 * Raw wording on purpose: the wait comes from the importer's own
                 * step parser, so this pins the number the cooking screen will
                 * actually be given rather than one the test made up.
                 */
                steps: [
                    new StepDraft('Pokrój cukinię i cebulę.'),
                    new StepDraft('Duś pod przykryciem przez 10 minut.'),
                ],
            ),
            'example.test',
        );

        return $recipe;
    }
}
