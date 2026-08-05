<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RecipeBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->storeSampleRecipe();

        // The catalogue lives behind the household account now.
        $this->actingAs(User::factory()->create());
    }

    public function test_the_list_shows_every_recipe_without_its_detail(): void
    {
        $this->get(route('home'))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Recipes/Index')
                ->has('recipes', 1)
                ->where('recipes.0.title', 'Jajecznica z cukinią')
                ->where('recipes.0.ingredientCount', 3)
                ->where('recipes.0.stepCount', 2)
                ->missing('recipe'),
        );
    }

    public function test_a_recipe_has_its_own_url_carrying_the_full_detail(): void
    {
        $this->get(route('recipes.show', 'jajecznica-z-cukinia'))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Recipes/Index')
                ->has('recipes', 1)
                ->where('recipe.title', 'Jajecznica z cukinią')
                ->where('recipe.ingredients.0.ingredient', 'Cukinia')
                ->where('recipe.ingredients.0.quantity', 300)
                ->where('recipe.ingredients.0.unit', 'g')
                ->where('recipe.steps.0.action', 'fry')
                ->where('recipe.steps.0.appliance', 'pan'),
        );
    }

    public function test_a_step_carries_the_amounts_it_uses(): void
    {
        $this->get(route('recipes.show', 'jajecznica-z-cukinia'))->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('recipe.steps.0.uses.0.ingredient', 'Cukinia')
                ->where('recipe.steps.0.uses.0.quantity', 300),
        );
    }

    /**
     * The list filters client side, so the flag has to be on every summary rather
     * than only on the recipe that is open.
     */
    public function test_the_list_says_which_recipes_are_meal_prep(): void
    {
        $this->storeSampleRecipe(
            slug: 'salatka-do-pudelka',
            title: 'Sałatka do pudełka',
            isMealPrep: true,
        );

        $this->get(route('home'))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('recipes', 2)
                ->where('recipes.0.title', 'Jajecznica z cukinią')
                ->where('recipes.0.isMealPrep', false)
                ->where('recipes.1.title', 'Sałatka do pudełka')
                ->where('recipes.1.isMealPrep', true),
        );
    }

    public function test_an_unknown_recipe_is_a_404(): void
    {
        $this->get(route('recipes.show', 'nie-ma-takiego'))->assertNotFound();
    }

    private function storeSampleRecipe(
        string $slug = 'jajecznica-z-cukinia',
        string $title = 'Jajecznica z cukinią',
        bool $isMealPrep = false,
    ): void {
        $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: $slug,
                title: $title,
                sourceUrl: 'https://example.test/przepis/'.$slug,
                ingredientLines: [
                    new IngredientLineDraft('300 g cukinii'),
                    new IngredientLineDraft('4 jajka'),
                    new IngredientLineDraft('1 łyżka masła'),
                ],
                steps: [
                    new StepDraft('Smażyć cukinię na patelni przez 5 minut.'),
                    new StepDraft('Dodać jajka i wymieszać.'),
                ],
                servings: 2,
                servingsLabel: '2 porcje',
                isMealPrep: $isMealPrep,
            ),
            'example.test',
        );
    }
}
