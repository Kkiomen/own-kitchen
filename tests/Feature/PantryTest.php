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
use App\Models\Unit;
use App\Models\User;
use App\Pantry\Pantry;
use App\Pantry\RecipeAvailability;
use App\Support\Measurement\MeasureBook;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PantryTest extends TestCase
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

    public function test_a_product_can_be_put_on_a_shelf(): void
    {
        $this->actingAs($this->user)
            ->post(route('pantry.store'), [
                'ingredient_id' => $this->ingredient('Cukinia')->id,
                'location' => StorageLocation::Fridge->value,
                'quantity' => 500,
                'unit_id' => $this->unit('g')->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, PantryItem::query()->count());
    }

    /**
     * "I have salt" is a useful statement on its own. Demanding a number would
     * make people either lie or stop keeping the list current.
     */
    public function test_an_amount_is_optional(): void
    {
        $this->actingAs($this->user)
            ->post(route('pantry.store'), [
                'ingredient_id' => $this->ingredient('Sól')->id,
                'location' => StorageLocation::Pantry->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(PantryItem::query()->firstOrFail()->quantity);
    }

    public function test_an_amount_without_a_unit_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('pantry.store'), [
                'ingredient_id' => $this->ingredient('Cukinia')->id,
                'location' => StorageLocation::Fridge->value,
                'quantity' => 500,
            ])
            ->assertSessionHasErrors('unit_id');
    }

    /**
     * Two rows for the same product on the same shelf would make every amount
     * comparison wrong.
     */
    public function test_adding_the_same_product_twice_updates_it(): void
    {
        $payload = [
            'ingredient_id' => $this->ingredient('Cukinia')->id,
            'location' => StorageLocation::Fridge->value,
            'quantity' => 500,
            'unit_id' => $this->unit('g')->id,
        ];

        $this->actingAs($this->user)->post(route('pantry.store'), $payload);
        $this->actingAs($this->user)->post(route('pantry.store'), [...$payload, 'quantity' => 900]);

        $this->assertSame(1, PantryItem::query()->count());
        $this->assertSame(900.0, PantryItem::query()->firstOrFail()->quantity);
    }

    /**
     * What is on a shelf changes far more often than what is on it — half the
     * courgette went into dinner — so correcting an amount is a first-class edit.
     */
    public function test_an_amount_can_be_corrected(): void
    {
        $item = $this->hold('Cukinia', 500, 'g');

        $this->actingAs($this->user)
            ->patch(route('pantry.update', $item), [
                'ingredient_id' => $item->ingredient_id,
                'location' => StorageLocation::Fridge->value,
                'quantity' => 200,
                'unit_id' => $this->unit('g')->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(200.0, $item->refresh()->quantity);
    }

    /**
     * One row per product per place. Moving something onto a shelf that already
     * holds it states what is there now, rather than breaking the unique key.
     */
    public function test_moving_a_product_onto_a_shelf_that_holds_it_replaces_that_row(): void
    {
        $fridge = $this->hold('Cukinia', 500, 'g');
        $this->hold('Cukinia', 100, 'g', location: StorageLocation::Freezer);

        $this->actingAs($this->user)
            ->patch(route('pantry.update', $fridge), [
                'ingredient_id' => $fridge->ingredient_id,
                'location' => StorageLocation::Freezer->value,
                'quantity' => 500,
                'unit_id' => $this->unit('g')->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PantryItem::query()->count());
        $this->assertSame(500.0, PantryItem::query()->firstOrFail()->quantity);
    }

    public function test_an_entry_can_be_thrown_out(): void
    {
        $item = $this->hold('Cukinia', 500, 'g');

        $this->actingAs($this->user)
            ->delete(route('pantry.destroy', $item))
            ->assertRedirect();

        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_another_account_cannot_correct_an_entry(): void
    {
        $other = User::factory()->create();
        $item = $this->hold('Cukinia', 500, 'g', owner: $other);

        $this->actingAs($this->user)
            ->patch(route('pantry.update', $item), [
                'ingredient_id' => $item->ingredient_id,
                'location' => StorageLocation::Fridge->value,
                'quantity' => 1,
                'unit_id' => $this->unit('g')->id,
            ])
            ->assertNotFound();

        $this->assertSame(500.0, $item->refresh()->quantity);
    }

    public function test_one_kitchen_is_invisible_to_another_account(): void
    {
        $other = User::factory()->create();
        $item = $this->hold('Cukinia', 500, 'g', owner: $other);

        $this->actingAs($this->user)
            ->delete(route('pantry.destroy', $item))
            ->assertNotFound();

        $this->assertSame(1, PantryItem::query()->count());
    }

    public function test_a_recipe_whose_products_are_all_held_is_missing_nothing(): void
    {
        $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);
        $this->hold('Cukinia', 500, 'g');
        $this->hold('Cebula', 3, 'piece');

        $this->assertSame(0, $this->missingCount('cukinia-z-cebula'));
    }

    public function test_products_that_are_not_held_are_counted(): void
    {
        $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);
        $this->hold('Cukinia', 500, 'g');

        $this->assertSame(1, $this->missingCount('cukinia-z-cebula'));
    }

    /**
     * Nobody wants to be told they cannot cook because they are out of salt.
     */
    public function test_staples_are_assumed_to_be_at_hand(): void
    {
        $this->recipe('Cukinia z solą', ['300 g cukinii', '1 szczypta soli']);
        $this->hold('Cukinia', 500, 'g');

        $this->assertSame(0, $this->missingCount('cukinia-z-sola'));
    }

    public function test_baking_paper_is_not_shopping(): void
    {
        $this->recipe('Zapiekana cukinia', ['300 g cukinii', '2 arkusze papieru do pieczenia']);
        $this->hold('Cukinia', 500, 'g');

        $this->assertSame(0, $this->missingCount('zapiekana-cukinia'));
    }

    public function test_an_optional_line_never_blocks_a_recipe(): void
    {
        $this->recipe('Cukinia z dodatkiem', ['300 g cukinii', 'opcjonalnie: 100 g fety']);
        $this->hold('Cukinia', 500, 'g');

        $this->assertSame(0, $this->missingCount('cukinia-z-dodatkiem'));
    }

    /**
     * Having the product is not the same as having enough of it — this is what
     * turns the match into a usable shopping list.
     */
    public function test_too_little_of_a_product_counts_as_missing(): void
    {
        $recipe = $this->recipe('Dużo cukinii', ['800 g cukinii']);
        $this->hold('Cukinia', 200, 'g');

        $missing = $this->app->make(RecipeAvailability::class)
            ->missingFor($recipe->load('ingredients.ingredient', 'ingredients.unit'), Pantry::of($this->user, $this->app->make(MeasureBook::class)));

        $this->assertCount(1, $missing);
        $this->assertSame('not_enough', $missing[0]['reason']);
    }

    public function test_enough_of_a_product_in_another_unit_still_counts(): void
    {
        $recipe = $this->recipe('Trochę cukinii', ['300 g cukinii']);
        $this->hold('Cukinia', 1, 'kg');

        $missing = $this->app->make(RecipeAvailability::class)
            ->missingFor($recipe->load('ingredients.ingredient', 'ingredients.unit'), Pantry::of($this->user, $this->app->make(MeasureBook::class)));

        $this->assertSame([], $missing);
    }

    /**
     * An entry written without an amount means "some", never "none".
     */
    public function test_an_unmeasured_entry_is_not_treated_as_a_shortage(): void
    {
        $recipe = $this->recipe('Dużo cukinii', ['800 g cukinii']);
        $this->hold('Cukinia', null, null);

        $missing = $this->app->make(RecipeAvailability::class)
            ->missingFor($recipe->load('ingredients.ingredient', 'ingredients.unit'), Pantry::of($this->user, $this->app->make(MeasureBook::class)));

        $this->assertSame([], $missing);
    }

    public function test_the_same_product_on_two_shelves_adds_up(): void
    {
        $recipe = $this->recipe('Dużo cukinii', ['800 g cukinii']);
        $this->hold('Cukinia', 500, 'g', location: StorageLocation::Fridge);
        $this->hold('Cukinia', 400, 'g', location: StorageLocation::Freezer);

        $missing = $this->app->make(RecipeAvailability::class)
            ->missingFor($recipe->load('ingredients.ingredient', 'ingredients.unit'), Pantry::of($this->user, $this->app->make(MeasureBook::class)));

        $this->assertSame([], $missing);
    }

    public function test_the_recipe_list_reports_what_is_missing(): void
    {
        $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);
        $this->hold('Cukinia', 500, 'g');

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recipes.0.missing', 1));
    }

    private function missingCount(string $slug): int
    {
        $recipe = Recipe::query()->where('slug', $slug)->firstOrFail();
        $counts = $this->app->make(RecipeAvailability::class)->missingCounts($this->user);

        return $counts[$recipe->id] ?? 0;
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }

    private function hold(
        string $name,
        ?float $quantity,
        ?string $unitCode,
        ?User $owner = null,
        StorageLocation $location = StorageLocation::Fridge,
    ): PantryItem {
        return PantryItem::query()->create([
            'user_id' => ($owner ?? $this->user)->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => $location,
            'quantity' => $quantity,
            'unit_id' => $unitCode === null ? null : $this->unit($unitCode)->id,
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function recipe(string $title, array $lines): Recipe
    {
        return $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: str($title)->slug()->value(),
                title: $title,
                sourceUrl: 'https://example.test/'.str($title)->slug()->value(),
                ingredientLines: array_map(
                    static fn (string $line): IngredientLineDraft => new IngredientLineDraft($line),
                    $lines,
                ),
                steps: [new StepDraft('Wymieszać i gotować przez 10 minut.')],
            ),
            'example.test',
        );
    }
}
